<?php

namespace Tests\Feature;

use App\Enums\LearnerRecordSourceType;
use App\Enums\VerificationState;
use App\Jobs\InstitutionIntegrations\PerformInstitutionPullLookupJob;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Models\Application;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\InstitutionIntegration;
use App\Models\InstitutionPullLookupLog;
use App\Models\LearnerRecord;
use App\Models\Qualification;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstitutionPullLookupAutoVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstitutionWithPullIntegration(): AwardingInstitution
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 1]
        );

        $inst = AwardingInstitution::query()->create([
            'country_id' => $country->id,
            'name' => 'Pull University',
            'consent_form_path' => null,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        InstitutionIntegration::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => true,
            'lookup_url' => 'https://institution.test/lookup',
            'auth_type' => 'none',
            'credentials' => null,
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 0,
            'driver' => 'generic_rest',
        ]);

        return $inst;
    }

    private function makeQualification(AwardingInstitution $inst, array $overrides = []): Qualification
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-PULL-'.rand(1000, 9999),
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

        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'John Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'student_number' => 'STU-001',
            'certificate_number' => null,
            'examination_number' => null,
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => '2024-01-10',
            'qualification_type' => 'L6',
            'qualification_type_id' => null,
            'is_foreign_qualification' => false,
            'verification_state' => VerificationState::AwaitingAutoVerification,
            'transcript_required' => false,
        ], $overrides));
    }

    public function test_no_internal_match_with_active_integration_routes_to_level_1_without_pull(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $inst = $this->makeInstitutionWithPullIntegration();
        $qualification = $this->makeQualification($inst);

        Http::fake();
        Bus::fake([PerformInstitutionPullLookupJob::class]);

        $this->assertSame(0, LearnerRecord::query()->count());

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Http::assertNothingSent();
        Bus::assertNotDispatched(PerformInstitutionPullLookupJob::class);

        $qualification->refresh();
        $this->assertNull($qualification->institution_pull_lookup_dispatched_at);
        $this->assertNull($qualification->institution_pull_lookup_attempted_at);
        $this->assertNull($qualification->learner_record_id);
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
        $this->assertSame(0, InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->count());
        $this->assertSame(0, LearnerRecord::query()->count());
    }

    public function test_no_internal_match_with_integration_auto_assigns_when_category_configured(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $inst = $this->makeInstitutionWithPullIntegration();

        $category = VerificationAssignmentCategory::query()->create([
            'name' => $inst->name,
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $level1 = User::factory()->activated()->create(['applicant_type' => null, 'name' => 'Officer A']);
        $level1->assignRole('Verification Officer Level 1');
        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1->id,
            'is_active' => true,
            'is_available' => true,
        ]);

        $qualification = $this->makeQualification($inst);

        Http::fake();
        Bus::fake([PerformInstitutionPullLookupJob::class]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Bus::assertNotDispatched(PerformInstitutionPullLookupJob::class);
        Http::assertNothingSent();

        $qualification->refresh();
        $this->assertSame(VerificationState::AssignedToLevel1, $qualification->verification_state);
        $this->assertSame($level1->id, (int) $qualification->assigned_verifier_id);
    }

    public function test_internal_match_with_active_integration_does_not_dispatch_pull_lookup(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => true,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $inst = $this->makeInstitutionWithPullIntegration();
        $qualification = $this->makeQualification($inst, ['student_number' => 'STU-010']);

        LearnerRecord::query()->create([
            'awarding_institution_id' => (int) $inst->id,
            'student_id' => 'STU-010',
            'student_id_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeStudentId('STU-010'),
            'program_of_study' => 'Diploma in Testing',
            'qualification_title_normalized' => \App\Support\Normalization\LearnerRecordNormalizer::normalizeProgramTitle('Diploma in Testing'),
            'year_awarded' => 2024,
            'source_type' => LearnerRecordSourceType::Manual,
            'is_active' => true,
        ]);

        Http::fake();
        Bus::fake([PerformInstitutionPullLookupJob::class]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Bus::assertNotDispatched(PerformInstitutionPullLookupJob::class);
        Http::assertNothingSent();

        $qualification->refresh();
        $this->assertNull($qualification->institution_pull_lookup_dispatched_at);
        $this->assertNull($qualification->institution_pull_lookup_attempted_at);
        $this->assertSame(VerificationState::AutoVerifiedPendingLevel2, $qualification->verification_state);
        $this->assertNotNull($qualification->learner_record_id);
        $this->assertSame(0, InstitutionPullLookupLog::query()->where('qualification_id', $qualification->id)->count());
    }

    public function test_auto_verification_disabled_does_not_dispatch_pull_lookup(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        config([
            'auto_verification.enabled' => false,
            'auto_verification.threshold' => 70,
            'auto_verification.auto_issue_enabled' => false,
        ]);

        $inst = $this->makeInstitutionWithPullIntegration();
        $qualification = $this->makeQualification($inst);

        Http::fake();
        Bus::fake([PerformInstitutionPullLookupJob::class]);

        ProcessQualificationAutoVerificationJob::dispatchSync((int) $qualification->id);

        Bus::assertNotDispatched(PerformInstitutionPullLookupJob::class);
        Http::assertNothingSent();

        $qualification->refresh();
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
        $this->assertSame('auto_verification_disabled', $qualification->auto_verification_failure_reason);
    }
}
