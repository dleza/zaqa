<?php

namespace Tests\Feature;

use App\Domain\Fees\QualificationFeeResolver;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleVisibility;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationLifecycleEvent;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdfWrapper;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class Level2DecisionLevel1CorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed([BillingCategoriesSeeder::class, QualificationTypesSeeder::class, FeeStructuresSeeder::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function level2DecisionPayload(array $overrides = []): array
    {
        return array_merge([
            'findings' => 'Level 1 findings for review.',
            'accreditation_statement' => 'Accredited institution under the Zambia Qualifications Authority Act.',
        ], $overrides);
    }

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');
        $user->givePermissionTo([
            'verification.pool.view',
            'verification.level2.review',
            'verification.decide.approve',
            'verification.decide.reject',
            'verification.certificate.issue',
        ]);

        return $user;
    }

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');
        $user->givePermissionTo(['verification.level1.process', 'verification.pool.view']);

        return $user;
    }

    private function makeQualificationAwaitingLevel2(array $overrides = []): Qualification
    {
        $type = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2C-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'paid_at' => now(),
        ]);

        return Qualification::query()->create(array_merge([
            'application_id' => $application->id,
            'verification_reference_number' => 'ZAQA-Q-'.Str::upper((string) Str::ulid()),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => $type->name,
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $type->zqf_level_code,
            'qualification_type_id' => $type->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
            'verification_state' => VerificationState::UnderLevel2Review,
            'reviewer_notes' => 'Original Level 1 findings with typo.',
            'level1_recommended_for_award' => true,
            'level1_accreditation_statement' => 'Original accrediation statement.',
            'reviewed_at' => now()->subDay(),
            'level1_review_completed_by_user_id' => $this->makeLevel1Officer()->id,
        ], $overrides));
    }

    private function createConfirmedPayment(Application $application): void
    {
        $application->loadMissing('qualifications');
        $required = app(QualificationFeeResolver::class)->totalVerificationFeesCents($application);
        Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => null,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => $required,
            'provider' => 'test',
            'confirmed_at' => now(),
        ]);
    }

    private function mockPdfLoadView(?callable $assertRecognitionStatement = null): void
    {
        $pdfMock = Mockery::mock(DomPdfWrapper::class);
        $pdfMock->shouldReceive('setPaper')->andReturnSelf();
        $pdfMock->shouldReceive('output')->andReturn('%PDF-test-output');
        Pdf::shouldReceive('loadView')
            ->andReturnUsing(function (string $view, array $data) use ($pdfMock, $assertRecognitionStatement) {
                if ($assertRecognitionStatement !== null) {
                    $assertRecognitionStatement($view, $data);
                }

                return $pdfMock;
            });
    }

    public function test_show_page_includes_level1_findings_and_accreditation_for_prefill(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.level1_review.findings', 'Original Level 1 findings with typo.')
                ->where('qualification.level1_review.accreditation_statement', 'Original accrediation statement.'));
    }

    public function test_edit_page_includes_level1_findings_and_accreditation_for_prefill(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.edit', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.level1_review.findings', 'Original Level 1 findings with typo.')
                ->where('qualification.level1_review.accreditation_statement', 'Original accrediation statement.'));
    }

    public function test_approval_request_requires_findings_and_accreditation_when_issuing_certificate(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), [
                'issue_certificate' => true,
            ])
            ->assertSessionHasErrors(['findings', 'accreditation_statement']);
    }

    public function test_level2_can_approve_without_changing_level1_values(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload([
                'findings' => 'Original Level 1 findings with typo.',
                'accreditation_statement' => 'Original accrediation statement.',
                'issue_certificate' => false,
            ]))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $qualification->refresh();
        $this->assertSame('Original Level 1 findings with typo.', $qualification->reviewer_notes);
        $this->assertSame('Original accrediation statement.', $qualification->level1_accreditation_statement);
        $this->assertDatabaseMissing('audit_logs', [
            'event_type' => 'verification.level2_corrected_level1_submission',
            'entity_id' => $qualification->id,
        ]);
    }

    public function test_level2_can_edit_findings_during_approval_and_audit_is_recorded(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload([
                'findings' => 'Corrected Level 1 findings without typo.',
                'issue_certificate' => false,
            ]))
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame('Corrected Level 1 findings without typo.', $qualification->reviewer_notes);

        $audit = AuditLog::query()
            ->where('event_type', 'verification.level2_corrected_level1_submission')
            ->where('entity_id', $qualification->id)
            ->first();

        $this->assertNotNull($audit);
        $metadata = (array) ($audit->metadata ?? []);
        $this->assertContains('findings', $metadata['changed_fields'] ?? []);
        $this->assertSame('approval', $metadata['decision_context'] ?? null);
        $this->assertSame('Original Level 1 findings with typo.', $metadata['old_findings'] ?? null);
        $this->assertSame('Corrected Level 1 findings without typo.', $metadata['new_findings'] ?? null);
    }

    public function test_level2_can_edit_accreditation_statement_during_approval_and_certificate_uses_corrected_value(): void
    {
        Storage::fake('local');
        Mail::fake();

        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);
        $this->createConfirmedPayment($qualification->application);

        $corrected = 'Corrected accreditation statement for CVEQ issue.';

        $this->mockPdfLoadView(function (string $view, array $data) use ($corrected) {
            $this->assertSame('pdf.qualification-certificate', $view);
            $this->assertSame($corrected, $data['recognition_statement'] ?? null);
        });

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload([
                'findings' => 'Original Level 1 findings with typo.',
                'accreditation_statement' => $corrected,
                'issue_certificate' => true,
            ]))
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame($corrected, $qualification->level1_accreditation_statement);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.level2_corrected_level1_submission',
            'entity_id' => $qualification->id,
        ]);
    }

    public function test_rejection_request_requires_findings(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.reject', $qualification), [
                'reason' => 'Does not meet requirements.',
            ])
            ->assertSessionHasErrors(['findings']);
    }

    public function test_level2_can_edit_findings_during_rejection(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.reject', $qualification), $this->level2DecisionPayload([
                'findings' => 'Corrected internal findings during rejection.',
                'reason' => 'Applicant-visible rejection reason.',
                'generate_rejection_notice' => false,
            ]))
            ->assertRedirect();

        $qualification->refresh();
        $this->assertSame('Corrected internal findings during rejection.', $qualification->reviewer_notes);
        $this->assertSame(VerificationState::Rejected, $qualification->verification_state);

        $audit = AuditLog::query()
            ->where('event_type', 'verification.level2_corrected_level1_submission')
            ->where('entity_id', $qualification->id)
            ->first();

        $this->assertNotNull($audit);
        $this->assertSame('rejection', ($audit->metadata ?? [])['decision_context'] ?? null);
    }

    public function test_rejection_certificate_uses_reason_not_accreditation_statement(): void
    {
        Storage::fake('local');
        Mail::fake();

        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);
        $this->createConfirmedPayment($qualification->application);

        $applicantReason = 'Public rejection reason for applicant.';

        $this->mockPdfLoadView(function (string $view, array $data) use ($applicantReason) {
            $this->assertSame('pdf.rejection-certificate', $view);
            $this->assertSame($applicantReason, $data['decision_summary'] ?? null);
            $this->assertArrayNotHasKey('recognition_statement', $data);
        });

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.reject', $qualification), $this->level2DecisionPayload([
                'findings' => 'Internal findings updated on reject.',
                'accreditation_statement' => 'Should not appear on rejection notice.',
                'reason' => $applicantReason,
                'generate_rejection_notice' => true,
            ]))
            ->assertRedirect();
    }

    public function test_show_page_reflects_level2_correction_metadata_after_approval(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload([
                'findings' => 'Updated findings after Level 2 review.',
                'accreditation_statement' => 'Updated accreditation statement.',
                'issue_certificate' => false,
            ]))
            ->assertRedirect();

        $this->actingAs($l2)
            ->get(route('admin.verification.qualifications.show', $qualification))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('qualification.level1_review.findings', 'Updated findings after Level 2 review.')
                ->where('qualification.level1_review.accreditation_statement', 'Updated accreditation statement.')
                ->where('qualification.level1_review.level2_correction.changed_fields', ['findings', 'accreditation_statement']));
    }

    public function test_internal_lifecycle_event_recorded_for_level2_correction(): void
    {
        $l2 = $this->makeLevel2Officer();
        $qualification = $this->makeQualificationAwaitingLevel2(['level2_review_owner_id' => $l2->id]);

        $this->actingAs($l2)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload([
                'findings' => 'Changed findings only.',
                'issue_certificate' => false,
            ]))
            ->assertRedirect();

        $event = ApplicationLifecycleEvent::query()
            ->where('application_id', $qualification->application_id)
            ->where('event_code', 'like', 'verification.level2_corrected_level1_submission.q'.$qualification->id.'%')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(LifecycleVisibility::Internal->value, $event->visibility?->value ?? $event->visibility);
    }

    public function test_unauthorized_user_cannot_submit_level2_decision_corrections(): void
    {
        $l1 = $this->makeLevel1Officer();
        $qualification = $this->makeQualificationAwaitingLevel2([
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $this->actingAs($l1)
            ->post(route('admin.verification.qualifications.approve', $qualification), $this->level2DecisionPayload())
            ->assertForbidden();
    }
}
