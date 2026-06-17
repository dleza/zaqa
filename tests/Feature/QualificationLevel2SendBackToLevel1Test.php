<?php

namespace Tests\Feature;

use App\Domain\Verification\AssignmentService;
use App\Domain\Verification\QualificationLevel1ReviewService;
use App\Domain\Verification\QualificationLevel2SendBackToLevel1Service;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\AuditLog;
use App\Models\AwardingInstitution;
use App\Models\Country;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use App\Models\VerificationAssignmentCategory;
use App\Models\VerificationAssignmentCategoryUser;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationLevel2SendBackToLevel1Test extends TestCase
{
    use RefreshDatabase;

    private function seedBase(): void
    {
        $this->seed([
            RolesAndPermissionsSeeder::class,
            BillingCategoriesSeeder::class,
            QualificationTypesSeeder::class,
            FeeStructuresSeeder::class,
        ]);
    }

    private function diplomaType(): QualificationType
    {
        return QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
    }

    private function makeLevel1(string $email = 'l1@example.test'): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null, 'email' => $email]);
        $u->assignRole('Verification Officer Level 1');
        $u->givePermissionTo(['verification.level1.process', 'verification.pool.view', 'dashboard.view']);

        return $u;
    }

    private function makeLevel2(string $email = 'l2@example.test'): User
    {
        $u = User::factory()->activated()->create(['applicant_type' => null, 'email' => $email]);
        $u->assignRole('Verification Officer Level 2');
        $u->givePermissionTo(['verification.level2.review', 'verification.pool.view', 'dashboard.view']);

        return $u;
    }

    private function makeApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2L1-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'submitted',
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(14),
            'paid_at' => now(),
        ]);
    }

    private function makeLevel2ReadyQualification(User $level1, User $level2): Qualification
    {
        $type = $this->diplomaType();
        $application = $this->makeApplication();

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'assigned_verifier_id' => $level1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        app(AssignmentService::class)->assign($qualification, $level2, $level1, 'Please review.');
        app(QualificationLevel1ReviewService::class)->completeLevel1(
            qualification: $qualification,
            actor: $level1,
            findings: 'Initial findings.',
            recommendedForAward: false,
            qualificationTypeId: $type->id,
        );

        $qualification->refresh();
        $qualification->forceFill(['level2_review_owner_id' => $level2->id])->save();

        return $qualification->fresh();
    }

    public function test_level2_can_send_qualification_back_to_level1(): void
    {
        $this->seedBase();
        Mail::fake();

        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back_to_level1', $qualification), [
                'comment' => 'Please correct the qualification type and findings.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);
        $this->assertSame($level1->id, (int) $qualification->assigned_verifier_id);
        $this->assertNull($qualification->level2_review_owner_id);
        $this->assertNotNull($qualification->returned_to_level1_at);
        $this->assertSame($level2->id, (int) $qualification->returned_to_level1_by_user_id);
        $this->assertSame($level2->id, (int) $qualification->level2_return_target_user_id);
        $this->assertSame(1, (int) $qualification->level1_correction_cycle);

        $this->assertDatabaseHas('application_comments', [
            'qualification_id' => $qualification->id,
            'type' => 'level2_send_back_to_level1',
            'visibility' => 'internal',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'event_type' => 'verification.level2_sent_back_to_level1',
        ]);
    }

    public function test_level1_cannot_send_back_to_level1(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.send_back_to_level1', $qualification), [
                'comment' => 'Attempted send-back.',
            ])
            ->assertForbidden();
    }

    public function test_comment_is_required(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back_to_level1', $qualification), [
                'comment' => '  ',
            ])
            ->assertSessionHasErrors('comment');
    }

    public function test_internal_comment_is_not_applicant_visible(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        app(QualificationLevel2SendBackToLevel1Service::class)->sendBackToLevel1(
            $qualification,
            $level2,
            'Internal correction only.',
        );

        $comment = ApplicationComment::query()
            ->where('qualification_id', $qualification->id)
            ->where('type', 'level2_send_back_to_level1')
            ->firstOrFail();

        $this->assertSame('internal', $comment->visibility);
        $this->assertNotSame('applicant_visible', $comment->visibility);
    }

    public function test_level1_sees_correction_banner_on_qualification_page(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        app(QualificationLevel2SendBackToLevel1Service::class)->sendBackToLevel1(
            $qualification,
            $level2,
            'Please update accreditation statement.',
        );

        $this->actingAs($level1)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.level2_send_back_correction.comment', 'Please update accreditation statement.')
                ->where('qualification.level2_send_back_correction.sent_by_name', $level2->name));
    }

    public function test_level1_resubmit_returns_to_same_level2_officer(): void
    {
        $this->seedBase();
        Mail::fake();

        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $type = $this->diplomaType();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        app(QualificationLevel2SendBackToLevel1Service::class)->sendBackToLevel1(
            $qualification,
            $level2,
            'Please revise findings.',
        );

        $this->actingAs($level1)
            ->post(route('admin.verification.qualifications.level1_complete', $qualification), [
                'qualification_type_id' => $type->id,
                'recommended_for_award' => '0',
                'findings' => 'Revised findings after correction.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel2Review, $qualification->verification_state);
        $this->assertSame($level2->id, (int) $qualification->level2_review_owner_id);
        $this->assertNull($qualification->returned_to_level1_at);

        $this->assertDatabaseHas('audit_logs', [
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'event_type' => 'verification.level1_resubmitted_after_level2_send_back',
        ]);
    }

    public function test_fallback_category_level1_assignment_when_original_officer_unavailable(): void
    {
        $this->seedBase();
        Mail::fake();

        $zmb = Country::query()->create(['iso_code' => 'ZMB', 'name' => 'Zambia', 'is_active' => true, 'sort_order' => 0]);
        $inst = AwardingInstitution::query()->create(['country_id' => $zmb->id, 'name' => 'Test Uni', 'is_active' => true, 'sort_order' => 0]);
        $category = VerificationAssignmentCategory::query()->create([
            'name' => 'Local Test',
            'type' => 'local_institution',
            'is_active' => true,
        ]);
        $category->awardingInstitutions()->attach($inst->id);

        $level1Original = $this->makeLevel1('original-l1@example.test');
        $level1Fallback = $this->makeLevel1('fallback-l1@example.test');
        $level2 = $this->makeLevel2();

        VerificationAssignmentCategoryUser::query()->create([
            'verification_assignment_category_id' => $category->id,
            'user_id' => $level1Fallback->id,
            'review_level' => 'level1',
            'is_active' => true,
            'is_available' => true,
        ]);

        $type = $this->diplomaType();
        $application = $this->makeApplication();
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_id' => $inst->id,
            'awarding_institution_name' => $inst->name,
            'qualification_holder_name' => 'Jane Doe',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_assignment_category_id' => $category->id,
            'assigned_verifier_id' => $level1Original->id,
            'level1_review_completed_by_user_id' => $level1Original->id,
            'verification_state' => VerificationState::UnderLevel2Review,
            'reviewed_at' => now(),
            'level2_review_owner_id' => $level2->id,
        ]);

        $level1Original->forceFill(['is_active' => false])->save();

        app(QualificationLevel2SendBackToLevel1Service::class)->sendBackToLevel1(
            $qualification,
            $level2,
            'Original officer unavailable; reassign.',
        );

        $qualification->refresh();
        $this->assertSame($level1Fallback->id, (int) $qualification->assigned_verifier_id);
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);
    }

    public function test_applicant_send_back_flow_still_works(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        $level2->givePermissionTo('verification.send_back');

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back', $qualification), [
                'comment' => 'Applicant must amend documents.',
            ])
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame(VerificationState::ReturnedToApplicant, $qualification->verification_state);
        $this->assertNull($qualification->returned_to_level1_at);
    }

    public function test_notification_sent_to_level1_on_send_back(): void
    {
        $this->seedBase();
        Mail::fake();

        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);

        app(QualificationLevel2SendBackToLevel1Service::class)->sendBackToLevel1(
            $qualification,
            $level2,
            'Please correct evaluation report attachment.',
        );

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $level1->id,
            'type' => 'verification.qualification_sent_back_to_level1',
        ]);

        Mail::assertQueued(\App\Mail\Verification\QualificationSentBackToLevel1Mail::class);
    }

    public function test_optional_attachment_is_stored(): void
    {
        $this->seedBase();
        $level1 = $this->makeLevel1();
        $level2 = $this->makeLevel2();
        $qualification = $this->makeLevel2ReadyQualification($level1, $level2);
        $file = UploadedFile::fake()->create('l2-note.pdf', 100, 'application/pdf');

        $this->actingAs($level2)
            ->post(route('admin.verification.qualifications.send_back_to_level1', $qualification), [
                'comment' => 'See attached guidance.',
                'attachment' => $file,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('qualification_documents', [
            'qualification_id' => $qualification->id,
            'document_type' => DocumentType::Level2SendBackToLevel1Attachment->value,
        ]);
    }
}
