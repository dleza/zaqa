<?php

namespace Tests\Feature;

use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewLockService;
use App\Domain\LearnerRecords\LearnerRecordSubmissionReviewService;
use App\Enums\LearnerRecordSubmissionSourceType;
use App\Enums\LearnerRecordSubmissionStatus;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\LearnerRecordSubmission;
use App\Models\User;
use App\Support\Normalization\LearnerRecordNormalizer;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LearnerRecordSubmissionReviewLockTest extends TestCase
{
    use RefreshDatabase;

    public function test_reviewer_can_start_and_release_review_lock(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->reviewerUser();
        $submission = $this->makePendingSubmission();

        $this->actingAs($admin)
            ->post("/admin/learner-records/submissions/{$submission->id}/start-review")
            ->assertRedirect();

        $submission->refresh();
        $this->assertSame($admin->id, (int) $submission->review_locked_by_user_id);
        $this->assertNotNull($submission->review_locked_at);

        $this->actingAs($admin)
            ->post("/admin/learner-records/submissions/{$submission->id}/release-review")
            ->assertRedirect();

        $submission->refresh();
        $this->assertNull($submission->review_locked_by_user_id);
        $this->assertNull($submission->review_locked_at);
    }

    public function test_second_reviewer_cannot_approve_without_lock(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $reviewerA = $this->reviewerUser();
        $reviewerB = $this->reviewerUser();
        $submission = $this->makePendingSubmission();

        app(LearnerRecordSubmissionReviewLockService::class)->lock($submission, $reviewerA);

        $this->expectException(ValidationException::class);
        app(LearnerRecordSubmissionReviewService::class)->approveAsNew($submission->fresh(), $reviewerB);
    }

    public function test_reviewer_with_lock_can_approve_submission(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->reviewerUser();
        $submission = $this->makePendingSubmission();

        app(LearnerRecordSubmissionReviewLockService::class)->lock($submission, $admin);
        app(LearnerRecordSubmissionReviewService::class)->approveAsNew($submission->fresh(), $admin);

        $submission->refresh();
        $this->assertSame(LearnerRecordSubmissionStatus::Approved, $submission->status);
        $this->assertNull($submission->review_locked_by_user_id);
    }

    public function test_show_page_includes_next_pending_submission_id(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->reviewerUser();
        $first = $this->makePendingSubmission(studentId: 'STU-101');
        $second = $this->makePendingSubmission(studentId: 'STU-102');

        $this->actingAs($admin)
            ->get("/admin/learner-records/submissions/{$first->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/LearnerRecords/Submissions/Show')
                ->where('submission.id', $first->id)
                ->where('submission.next_submission_id', $second->id)
            );
    }

    public function test_http_approve_requires_active_lock(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = $this->reviewerUser();
        $submission = $this->makePendingSubmission();

        $this->actingAs($admin)->post("/admin/learner-records/submissions/{$submission->id}/approve", [
            'decision' => 'approve_new',
        ])->assertSessionHasErrors('lock');
    }

    private function reviewerUser(): User
    {
        $user = User::factory()->activated()->create();
        $user->givePermissionTo([
            'dashboard.view',
            'learner_record_submissions.view',
            'learner_record_submissions.review',
            'learner_record_submissions.approve',
            'learner_record_submissions.reject',
        ]);

        return $user;
    }

    private function makePendingSubmission(string $studentId = 'STU-100'): LearnerRecordSubmission
    {
        $country = Country::query()->firstOrCreate(
            ['iso_code' => 'ZMB'],
            ['name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]
        );

        $institution = AwardingInstitution::query()->create([
            'country_id' => (int) $country->id,
            'name' => 'Test Institution '.uniqid('', true),
            'is_active' => true,
            'sort_order' => 0,
        ]);

        return LearnerRecordSubmission::query()->create([
            'source_type' => LearnerRecordSubmissionSourceType::InstitutionPush,
            'source_institution_id' => (int) $institution->id,
            'student_id' => $studentId,
            'student_id_normalized' => LearnerRecordNormalizer::normalizeStudentId($studentId),
            'first_name' => 'Mary',
            'last_name' => 'Banda',
            'name_normalized' => LearnerRecordNormalizer::normalizeNameParts('Mary', null, 'Banda'),
            'program_of_study' => 'BSc Nursing',
            'qualification_title_normalized' => LearnerRecordNormalizer::normalizeProgramTitle('BSc Nursing'),
            'year_awarded' => 2024,
            'dedupe_hash' => LearnerRecordNormalizer::dedupeHash((int) $institution->id, null, LearnerRecordNormalizer::normalizeStudentId($studentId), 2024),
            'status' => LearnerRecordSubmissionStatus::Pending,
            'received_at' => now(),
        ]);
    }
}
