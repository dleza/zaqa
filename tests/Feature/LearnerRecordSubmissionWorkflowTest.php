<?php

namespace Tests\Feature;

use App\Domain\InstitutionIntegrations\InstitutionPullLookupService;
use App\Domain\LearnerRecords\LearnerRecordMatchingService;
use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewLockService;
use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewService;
use App\Enums\LearnerRecordSourceType;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Enums\LearnerRecordSubmissionStatus;
use App\Enums\VerificationState;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionApiClient;
use App\Models\InstitutionIntegration;
use App\Models\LearnerRecord;
use App\Models\LearnerRecordSubmission;
use App\Models\Qualification;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LearnerRecordSubmissionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);
    }

    public function test_authorized_user_can_approve_submission_as_new(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $submission = $this->makePendingSubmission();

        $this->actingAs($admin)->post("/admin/learner-records/submissions/{$submission->id}/start-review");
        $this->actingAs($admin)->post("/admin/learner-records/submissions/{$submission->id}/approve", [
            'decision' => 'approve_new',
        ])->assertRedirect();

        $submission->refresh();
        $this->assertSame(LearnerRecordSubmissionStatus::Approved, $submission->status);
        $this->assertNotNull($submission->approved_learner_record_id);
        $this->assertSame(1, LearnerRecord::query()->count());

        $record = LearnerRecord::query()->first();
        $this->assertSame(LearnerRecordSourceType::InstitutionApi, $record->source_type);
        $this->assertNotNull($record->verified_at);
    }

    public function test_reject_does_not_create_learner_record(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $submission = $this->makePendingSubmission();

        $this->lockSubmissionForReview($submission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->reject($submission, $admin, 'Invalid record');

        $this->assertSame(0, LearnerRecord::query()->count());
        $submission->refresh();
        $this->assertSame(LearnerRecordSubmissionStatus::Rejected, $submission->status);
    }

    public function test_mark_duplicate_does_not_create_learner_record(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $submission = $this->makePendingSubmission();

        $this->lockSubmissionForReview($submission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->markDuplicate($submission, $admin, 'Already exists');

        $this->assertSame(0, LearnerRecord::query()->count());
        $submission->refresh();
        $this->assertSame(LearnerRecordSubmissionStatus::Duplicate, $submission->status);
    }

    public function test_terminal_submission_cannot_be_reviewed_again(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $submission = $this->makePendingSubmission();

        $this->lockSubmissionForReview($submission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->approveAsNew($submission, $admin);

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(LearnerRecordSubmissionReviewService::class)->reject($submission->fresh(), $admin, 'Too late');
    }

    public function test_unauthorized_user_cannot_approve_submission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $user = User::factory()->activated()->create();
        $submission = $this->makePendingSubmission();

        $this->actingAs($user)->post("/admin/learner-records/submissions/{$submission->id}/approve", [
            'decision' => 'approve_new',
        ])->assertForbidden();
    }

    public function test_pending_submission_is_not_used_by_auto_verification(): void
    {
        $qualification = $this->makeQualificationForMatching();
        $this->makePendingSubmission(
            institutionId: (int) $qualification->awarding_institution_id,
            studentId: '2019001',
            program: 'Bachelor of Science',
            year: 2024,
        );

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
        $this->assertNull($qualification->learner_record_id);
    }

    public function test_approved_submission_can_be_matched_after_promotion(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $qualification = $this->makeQualificationForMatching();
        $submission = $this->makePendingSubmission(
            institutionId: (int) $qualification->awarding_institution_id,
            studentId: '2019001',
            program: 'Bachelor of Science',
            year: 2024,
        );

        $this->lockSubmissionForReview($submission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->approveAsNew($submission, $admin);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        $qualification->refresh();
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);
    }

    public function test_duplicate_candidates_populated_for_exact_dedupe_hash(): void
    {
        $inst = $this->makeInstitution('University of Zambia');
        $hash = LearnerRecordNormalizer::dedupeHash(
            awardingInstitutionId: (int) $inst->id,
            certificateNoNormalized: LearnerRecordNormalizer::normalizeCertificateNo('UNZA-2024-0001'),
            studentIdNormalized: LearnerRecordNormalizer::normalizeStudentId('2019001'),
            yearAwarded: 2024,
        );

        LearnerRecord::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'student_id' => '2019001',
            'certificate_no' => 'UNZA-2024-0001',
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId('2019001'),
            'certificate_no_normalized' => LearnerRecordNormalizer::normalizeCertificateNo('UNZA-2024-0001'),
            'first_name' => 'Martin',
            'last_name' => 'Mwale',
            'program_of_study' => 'Bachelor of Science',
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle('Bachelor of Science'),
            'year_awarded' => 2024,
            'dedupe_hash' => $hash,
            'source_type' => LearnerRecordSourceType::Manual->value,
            'is_active' => true,
        ]);

        $client = InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'name' => 'Client',
            'scopes' => ['learner-records:write'],
            'is_active' => true,
        ]);
        $token = $client->createToken('t', ['learner-records:write'])->plainTextToken;

        $this->postJson('/api/institution/v1/learner-records', [
            'student_id' => '2019001',
            'certificate_no' => 'UNZA-2024-0001',
            'first_name' => 'Martin',
            'last_name' => 'Mwale',
            'program_of_study' => 'Bachelor of Science',
            'year_awarded' => 2024,
        ], [
            'Authorization' => 'Bearer '.$token,
        ])->assertStatus(202);

        $submission = LearnerRecordSubmission::query()->first();
        $this->assertNotEmpty($submission->duplicate_candidates);
        $this->assertContains('exact_duplicate_hash', $submission->risk_flags ?? []);
    }

    public function test_pull_lookup_stages_submission_instead_of_learner_record(): void
    {
        $qualification = $this->makeQualificationForMatching(withIntegration: true);

        Http::fake([
            'http://127.0.0.1:8001/api/zaqa/v1/learner-lookup' => Http::response([
                'found' => true,
                'source_reference' => 'verification_records:42',
                'record' => [
                    'student_id' => '2019001',
                    'certificate_no' => 'UNZA-2024-0001',
                    'first_name' => 'Martin',
                    'last_name' => 'Mwale',
                    'program_of_study' => 'Bachelor of Science',
                    'year_awarded' => 2024,
                ],
            ], 200),
        ]);

        app(InstitutionPullLookupService::class)->lookup($qualification);

        $this->assertSame(0, LearnerRecord::query()->count());
        $this->assertSame(1, LearnerRecordSubmission::query()->count());
        $this->assertSame(LearnerRecordSubmissionSourceType::InstitutionPull, LearnerRecordSubmission::query()->first()->source_type);
    }

    public function test_approval_and_rejection_create_audit_logs(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->adminWithSubmissionPermissions();
        $approveSubmission = $this->makePendingSubmission();
        $rejectSubmission = $this->makePendingSubmission(studentId: 'STU-999');

        $this->lockSubmissionForReview($approveSubmission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->approveAsNew($approveSubmission, $admin);
        $this->lockSubmissionForReview($rejectSubmission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->reject($rejectSubmission, $admin, 'Bad data');

        $this->assertTrue(AuditLog::query()->where('event_type', 'learner_record_submission.approved')->exists());
        $this->assertTrue(AuditLog::query()->where('event_type', 'learner_record_submission.rejected')->exists());
        $this->assertTrue(AuditLog::query()->where('event_type', 'learner_record.created_from_submission')->exists());
    }

    private function lockSubmissionForReview(LearnerRecordSubmission $submission, User $admin): void
    {
        app(LearnerRecordSubmissionReviewLockService::class)->lock($submission, $admin);
    }

    private function adminWithSubmissionPermissions(): User
    {
        $admin = User::factory()->activated()->create();
        $admin->givePermissionTo([
            'dashboard.view',
            'learner_record_submissions.view',
            'learner_record_submissions.review',
            'learner_record_submissions.approve',
            'learner_record_submissions.reject',
        ]);

        return $admin;
    }

    private function makeInstitution(string $name): AwardingInstitution
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]
        );

        return AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => $name,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    private function makePendingSubmission(
        ?int $institutionId = null,
        string $studentId = 'STU-100',
        string $program = 'BSc Nursing',
        int $year = 2024,
    ): LearnerRecordSubmission {
        $institutionId ??= (int) $this->makeInstitution('Test Institution '.uniqid('', true))->id;

        return LearnerRecordSubmission::query()->create([
            'source_type' => LearnerRecordSubmissionSourceType::InstitutionPush,
            'source_institution_id' => $institutionId,
            'student_id' => $studentId,
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId($studentId),
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'name_normalized' => LearnerRecordNormalizer::normalizeNameParts('Mary', null, 'Banda'),
            'program_of_study' => $program,
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle($program),
            'year_awarded' => $year,
            'dedupe_hash' => LearnerRecordNormalizer::dedupeHash($institutionId, null, LearnerRecordNormalizer::normalizeStudentId($studentId), $year),
            'status' => LearnerRecordSubmissionStatus::Pending,
            'received_at' => now(),
        ]);
    }

    private function makeQualificationForMatching(bool $withIntegration = false): Qualification
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $inst = $this->makeInstitution('University of Zambia');

        if ($withIntegration) {
            InstitutionIntegration::query()->create([
                'awarding_institution_id' => (int) $inst->id,
                'is_active' => true,
                'supports_push' => false,
                'supports_pull' => true,
                'lookup_url' => 'http://127.0.0.1:8001/api/zaqa/v1/learner-lookup',
                'auth_type' => 'bearer_token',
                'credentials' => ['bearer_token' => 'token'],
                'request_method' => 'POST',
                'timeout_seconds' => 15,
                'retry_attempts' => 0,
                'driver' => 'generic_rest',
            ]);
        }

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-TEST-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);

        return Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Martin Mwale',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/9',
            'student_number' => '2019001',
            'certificate_number' => 'UNZA-2024-0001',
            'title_of_qualification' => 'Bachelor of Science',
            'award_date' => '2024-12-15',
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ]);
    }
}
