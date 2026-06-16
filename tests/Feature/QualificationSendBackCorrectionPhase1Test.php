<?php

namespace Tests\Feature;

use App\Domain\Applications\ApplicantQualificationAmendmentComments;
use App\Domain\Applications\QualificationCaptureService;
use App\Domain\Verification\QualificationSendBackService;
use App\Enums\ApplicationStatus;
use App\Enums\DocumentType;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QualificationSendBackCorrectionPhase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    private function makePaidSubmittedApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-CORR-'.rand(10000, 99999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
            'service_deadline_at' => now()->addDays(14),
        ]);
    }

    private function makeQualification(Application $application, array $overrides = []): Qualification
    {
        $typeId = (int) QualificationType::query()->value('id');

        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'qualification_type_id' => $typeId,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel1Review,
        ], $overrides));
    }

    public function test_applicant_cannot_edit_non_returned_qualification_when_another_is_returned(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $returned = $this->makeQualification($application, [
            'title_of_qualification' => 'Returned Diploma',
            'verification_state' => VerificationState::ReturnedToApplicant,
            'returned_to_applicant_at' => now(),
        ]);
        $locked = $this->makeQualification($application, [
            'title_of_qualification' => 'Locked Diploma',
            'nrc_passport_number' => '222222/22/2',
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $this->actingAs($applicant)
            ->get(route('applicant.applications.qualifications.workspace.edit', [
                'application' => $application,
                'qualification' => $locked,
            ]))
            ->assertRedirect(route('applicant.applications.show', $application));

        $this->actingAs($applicant)
            ->get(route('applicant.applications.qualifications.workspace.edit', [
                'application' => $application,
                'qualification' => $returned,
            ]))
            ->assertOk();
    }

    public function test_applicant_cannot_delete_document_for_non_returned_qualification(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $this->makeQualification($application, [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'returned_to_applicant_at' => now(),
        ]);
        $locked = $this->makeQualification($application, [
            'nrc_passport_number' => '333333/33/3',
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $document = QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $locked->id,
            'document_type' => DocumentType::CertificateCopy->value,
            'original_name' => 'certificate.pdf',
            'stored_name' => 'certificate.pdf',
            'disk' => 'local',
            'path' => 'applications/'.$application->id.'/certificate.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1,
            'sha256_hash' => hash('sha256', 'certificate'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $this->actingAs($applicant)
            ->delete(route('applicant.documents.destroy', ['document' => $document]))
            ->assertSessionHasErrors('qualification');

        $this->assertDatabaseHas('qualification_documents', ['id' => $document->id]);
    }

    public function test_comment_history_excludes_internal_comments(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application);

        ApplicationComment::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'author_user_id' => null,
            'type' => 'send_back',
            'visibility' => 'applicant_visible',
            'body' => 'Visible to applicant',
        ]);
        ApplicationComment::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'author_user_id' => null,
            'type' => 'send_back',
            'visibility' => 'internal',
            'body' => 'Internal only',
        ]);

        $history = ApplicantQualificationAmendmentComments::historyForQualification($qualification);

        $this->assertCount(1, $history);
        $this->assertSame('Visible to applicant', $history[0]['body']);
    }

    public function test_multiple_send_back_cycles_appear_in_comment_history(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application);

        ApplicationComment::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'type' => 'send_back',
            'visibility' => 'applicant_visible',
            'body' => 'First request',
            'created_at' => now()->subDays(2),
        ]);
        ApplicationComment::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
            'type' => 'send_back',
            'visibility' => 'applicant_visible',
            'body' => 'Second request',
            'created_at' => now()->subDay(),
        ]);

        $history = ApplicantQualificationAmendmentComments::historyForQualification($qualification);

        $this->assertCount(2, $history);
        $this->assertSame('Second request', $history[0]['body']);
        $this->assertSame('First request', $history[1]['body']);
    }

    public function test_application_show_displays_correction_required_status(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $this->makeQualification($application, [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'returned_to_applicant_at' => now(),
        ]);

        $this->actingAs($applicant)
            ->get(route('applicant.applications.show', $application))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('application.display_status_label', 'Correction required')
                ->where('application.correction_required', true)
                ->where('application.current_status', ApplicationStatus::Submitted->value)
                ->has('application.qualifications.0.amendment_comment_history'));
    }

    public function test_inactive_officer_fallback_on_reopen(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $officer = User::factory()->activated()->create(['applicant_type' => null, 'is_active' => false]);
        $officer->givePermissionTo('verification.level1.process');

        $qualification = $this->makeQualification($application, [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $officer->id,
            'send_back_reopen_level' => 'level1',
        ]);

        app(QualificationCaptureService::class)->reopenQualificationAfterApplicantAmendment($qualification->fresh(), $applicant);

        $qualification->refresh();
        $this->assertSame(VerificationState::AwaitingAssignment, $qualification->verification_state);
        $this->assertNull($qualification->assigned_verifier_id);
    }

    public function test_officer_receives_notification_when_applicant_submits_corrections(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo('verification.level1.process');

        $qualification = $this->makeQualification($application, [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $officer->id,
            'send_back_reopen_level' => 'level1',
        ]);

        app(QualificationCaptureService::class)->reopenQualificationAfterApplicantAmendment($qualification->fresh(), $applicant);

        $this->assertDatabaseHas('notifications', [
            'notifiable_type' => User::class,
            'notifiable_id' => $officer->id,
            'type' => 'verification.qualification_corrections_submitted',
        ]);
    }

    public function test_reopen_restores_level1_officer_when_active(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $applicant = User::query()->findOrFail($application->applicant_user_id);

        $officer = User::factory()->activated()->create(['applicant_type' => null]);
        $officer->givePermissionTo('verification.level1.process');

        $qualification = $this->makeQualification($application, [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $officer->id,
            'send_back_reopen_level' => 'level1',
        ]);

        app(QualificationCaptureService::class)->reopenQualificationAfterApplicantAmendment($qualification->fresh(), $applicant);

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);
        $this->assertSame($officer->id, $qualification->assigned_verifier_id);
    }

    public function test_application_level_send_back_blocked_for_multi_qualification_application(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $this->makeQualification($application, ['nrc_passport_number' => '111111/11/1']);
        $this->makeQualification($application, ['nrc_passport_number' => '222222/22/2']);

        $actor = User::factory()->activated()->create(['applicant_type' => null]);
        $actor->givePermissionTo(['verification.send_back', 'dashboard.view']);

        $this->actingAs($actor)
            ->post(route('admin.verification.applications.send_back', ['application' => $application->id]), [
                'comment' => 'Please fix everything.',
            ])
            ->assertSessionHasErrors('application');
    }

    public function test_application_level_send_back_still_works_for_single_qualification(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $this->makeQualification($application);

        $actor = User::factory()->activated()->create(['applicant_type' => null]);
        $actor->givePermissionTo(['verification.send_back', 'dashboard.view']);

        $this->actingAs($actor)
            ->post(route('admin.verification.applications.send_back', ['application' => $application->id]), [
                'comment' => 'Please fix the application.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $application->refresh();
        $this->assertSame(ApplicationStatus::SentBack, $application->current_status);
    }

    public function test_qualification_send_back_still_sets_returned_state(): void
    {
        $application = $this->makePaidSubmittedApplication();
        $qualification = $this->makeQualification($application);

        $actor = User::factory()->activated()->create(['applicant_type' => null]);
        $actor->givePermissionTo('verification.level2.review');

        app(QualificationSendBackService::class)->sendBackToApplicant(
            $qualification,
            $actor,
            'Please upload a clearer scan.',
        );

        $qualification->refresh();
        $this->assertSame(VerificationState::ReturnedToApplicant, $qualification->verification_state);
        $this->assertSame($actor->id, $qualification->send_back_by_user_id);
        $this->assertSame('level2', $qualification->send_back_reopen_level);
    }
}
