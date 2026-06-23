<?php

namespace Tests\Feature;

use App\Domain\Finance\Events\PaymentProofApproved;
use App\Domain\Finance\Events\PaymentProofRejected;
use App\Domain\Applications\Events\ApplicationSubmitted;
use App\Domain\Finance\Listeners\SendPaymentProofApprovedNotification;
use App\Domain\Finance\Listeners\SendPaymentProofRejectedNotification;
use App\Domain\Finance\PaymentProofReviewService;
use App\Domain\Payments\InvoiceService;
use App\Jobs\Verification\ProcessQualificationAutoVerificationJob;
use App\Enums\DocumentType;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\Finance\BankTransferProofSubmittedMail;
use App\Mail\Finance\PaymentProofApprovedMail;
use App\Mail\Finance\PaymentProofRejectedMail;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\Qualification;
use App\Models\QualificationDocument;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FinancePaymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makePaymentReadyApplication(): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual', 'email' => 'applicant@example.test']);
        $qualificationType = QualificationType::query()
            ->where('zqf_level_code', 'L6')
            ->firstOrFail();

        ApplicantProfile::create([
            'user_id' => $applicant->id,
            'first_name' => 'Applicant',
            'surname' => 'Test',
            'nrc_number' => '111111/11/1',
            'email' => $applicant->email,
            'phone_primary' => $applicant->phone_primary,
            'identity_document_disk' => 'local',
            'identity_document_path' => 'profiles/'.$applicant->id.'/identity.pdf',
            'identity_document_original_name' => 'identity.pdf',
            'identity_document_size_bytes' => 1,
            'identity_document_uploaded_at' => now(),
        ]);

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FIN-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [
                'submitting_for' => 'self',
                'wizard_declarations' => [
                    'terms_accepted_at' => now()->toIso8601String(),
                    'information_confirmed_at' => now()->toIso8601String(),
                ],
            ],
        ]);

        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'Test Institute',
            'qualification_holder_name' => 'Applicant Test',
            'nrc_passport_number' => '111111/11/1',
            'certificate_number' => 'CERT-FIN-001',
            'title_of_qualification' => 'Diploma in Finance Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'diploma',
            'qualification_type_id' => $qualificationType->id,
            'is_foreign_qualification' => false,
            'verification_state' => null,
        ]);

        QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => $qualification->id,
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

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh(), $applicant);

        return [$applicant, $application, $invoice, $qualification];
    }

    private function makeManualProofPayment(): array
    {
        [$applicant, $application, $invoice] = $this->makePaymentReadyApplication();

        $proof = QualificationDocument::query()->create([
            'application_id' => $application->id,
            'qualification_id' => null,
            'document_type' => DocumentType::PaymentProof->value,
            'original_name' => 'proof.pdf',
            'stored_name' => 'proof.pdf',
            'disk' => 'local',
            'path' => 'applications/'.$application->id.'/proof.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 2048,
            'sha256_hash' => hash('sha256', 'proof'),
            'visibility' => 'private',
            'uploaded_by_user_id' => $applicant->id,
            'version_number' => 1,
            'is_current_version' => true,
        ]);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::AwaitingFinanceReview,
            'currency' => 'ZMW',
            'amount_cents' => (int) $invoice->amount_cents,
            'provider' => 'test',
            'proof_document_id' => $proof->id,
            'awaiting_finance_review_at' => now(),
        ]);

        return [$applicant, $application, $invoice, $payment];
    }

    private function createPaymentForInvoice(Application $application, Invoice $invoice, PaymentStatus $status, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::Card,
            'status' => $status,
            'currency' => 'ZMW',
            'amount_cents' => (int) $invoice->amount_cents,
            'provider' => 'test',
            'provider_reference' => 'REF-'.Str::upper(Str::random(8)),
            'initiated_at' => now()->subMinutes(5),
            'last_status_at' => now()->subMinutes(5),
        ], $overrides));
    }

    public function test_finance_user_can_view_payment_proof_queue(): void
    {
        [, , , $payment] = $this->makeManualProofPayment();

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $res = $this->actingAs($finance)->get('/admin/finance/payment-proofs');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page->component('Admin/Finance/PaymentProofs/Index', shouldExist: false));
        $res->assertSee((string) $payment->id);
    }

    public function test_non_finance_user_cannot_access_payment_proof_queue(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);

        $this->actingAs($user)->get('/admin/finance/payment-proofs')->assertForbidden();
    }

    public function test_finance_can_approve_pending_proof_sets_payment_confirmed_and_invoice_paid_and_is_idempotent(): void
    {
        [, , $invoice, $payment] = $this->makeManualProofPayment();

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        /** @var PaymentProofReviewService $reviews */
        $reviews = $this->app->make(PaymentProofReviewService::class);
        $reviews->approve($payment, $finance, 'Valid');

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertNotNull($payment->confirmed_at);

        // Idempotent approval should not throw or change status.
        $reviews->approve($payment, $finance, 'Second click');
        $payment->refresh();
        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
    }

    public function test_finance_payment_detail_includes_navigation_links_to_applicant_but_not_verification_qualification(): void
    {
        [$applicant, $application, $invoice, $qualification] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Failed, [
            'failed_at' => now()->subMinute(),
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->get("/admin/finance/payments/{$payment->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Finance/Payments/Show')
                ->where('can.view_applicant', true)
                ->where('can.view_qualifications', false)
                ->where('navigation.applicant.href', route('admin.applicants.show', ['user' => $applicant->id]))
                ->has('navigation.qualifications', 1)
                ->where('navigation.qualifications.0.id', $qualification->id)
                ->where('navigation.qualifications.0.href', null)
            );

        $this->actingAs($finance)->get("/admin/applicants/{$applicant->id}")->assertOk();
        $this->actingAs($finance)->get("/admin/verification/qualifications/{$qualification->id}")->assertForbidden();
    }

    public function test_finance_can_correct_failed_payment_to_confirmed_and_update_transaction_id(): void
    {
        Queue::fake();

        [, $application, $invoice] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Failed, [
            'failed_at' => now()->subMinutes(2),
            'provider_transaction_id' => 'TX-OLD-001',
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Gateway callback was delayed; confirming after reconciliation.',
                'provider_transaction_id' => 'TX-NEW-999',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHas('success');

        $payment->refresh();
        $invoice->refresh();
        $application->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('TX-NEW-999', $payment->provider_transaction_id);
        $this->assertNotNull($payment->confirmed_at);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);

        Queue::assertPushed(ProcessQualificationAutoVerificationJob::class);

        $log = AuditLog::query()
            ->where('event_type', 'finance.payment_corrected')
            ->where('entity_type', Payment::class)
            ->where('entity_id', $payment->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('failed', data_get($log?->before_state, 'status'));
        $this->assertSame('confirmed', data_get($log?->after_state, 'status'));
        $this->assertSame('Gateway callback was delayed; confirming after reconciliation.', data_get($log?->metadata, 'note'));
    }

    public function test_finance_can_correct_confirmed_payment_transaction_id_without_changing_status(): void
    {
        [, $application, $invoice] = $this->makePaymentReadyApplication();

        $application->forceFill([
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now()->subMinute(),
            'paid_at' => now()->subMinute(),
        ])->save();

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now()->subMinute(),
        ])->save();

        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Confirmed, [
            'confirmed_at' => now()->subMinute(),
            'provider_transaction_id' => 'TX-ORIGINAL',
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Replacing transaction reference from bank settlement report.',
                'provider_transaction_id' => 'TX-UPDATED',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHas('success');

        $payment->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('TX-UPDATED', $payment->provider_transaction_id);

        $log = AuditLog::query()
            ->where('event_type', 'finance.payment_corrected')
            ->where('entity_type', Payment::class)
            ->where('entity_id', $payment->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse((bool) data_get($log?->metadata, 'status_changed'));
        $this->assertTrue((bool) data_get($log?->metadata, 'provider_transaction_id_changed'));
        $this->assertSame('TX-ORIGINAL', data_get($log?->before_state, 'provider_transaction_id'));
        $this->assertSame('TX-UPDATED', data_get($log?->after_state, 'provider_transaction_id'));
    }

    public function test_finance_can_resync_confirmed_payment_to_submit_application_without_changing_transaction_id(): void
    {
        Queue::fake();

        [, $application, $invoice] = $this->makePaymentReadyApplication();

        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Confirmed, [
            'confirmed_at' => now()->subMinute(),
            'provider_transaction_id' => 'TX-SYNC-001',
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Synchronizing application after confirmed payment.',
                'provider_transaction_id' => 'TX-SYNC-001',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHas('success');

        $payment->refresh();
        $invoice->refresh();
        $application->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('TX-SYNC-001', $payment->provider_transaction_id);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);

        Queue::assertPushed(ProcessQualificationAutoVerificationJob::class);

        $log = AuditLog::query()
            ->where('event_type', 'finance.payment_corrected')
            ->where('entity_type', Payment::class)
            ->where('entity_id', $payment->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertFalse((bool) data_get($log?->metadata, 'status_changed'));
        $this->assertFalse((bool) data_get($log?->metadata, 'provider_transaction_id_changed'));
        $this->assertTrue((bool) data_get($log?->metadata, 'application_sync_performed'));
    }

    public function test_finance_payment_correction_requires_permission(): void
    {
        [, $application, $invoice] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Failed, [
            'failed_at' => now()->subMinute(),
        ]);

        $user = User::factory()->activated()->create(['applicant_type' => null]);

        $this->actingAs($user)
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Attempted correction without permission.',
            ])
            ->assertForbidden();
    }

    public function test_finance_payment_correction_requires_provider_transaction_id_when_confirming(): void
    {
        [, $application, $invoice] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Failed, [
            'failed_at' => now()->subMinute(),
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Trying to confirm without a transaction ID.',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHasErrors(['provider_transaction_id']);

        $this->assertSame(PaymentStatus::Failed, $payment->fresh()->status);
    }

    public function test_finance_payment_correction_is_blocked_for_awaiting_finance_review_payments(): void
    {
        [, , , $payment] = $this->makeManualProofPayment();

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Confirmed->value,
                'note' => 'Trying to bypass proof review.',
                'provider_transaction_id' => 'TX-REVIEW-001',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHasErrors(['status']);
    }

    public function test_finance_payment_correction_cannot_reverse_confirmed_payment_to_failed(): void
    {
        [, $application, $invoice] = $this->makePaymentReadyApplication();

        $application->forceFill([
            'current_status' => ApplicationStatus::Submitted,
            'submitted_at' => now()->subMinute(),
            'paid_at' => now()->subMinute(),
        ])->save();

        $invoice->forceFill([
            'status' => InvoiceStatus::Paid,
            'paid_at' => now()->subMinute(),
        ])->save();

        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Confirmed, [
            'confirmed_at' => now()->subMinute(),
            'provider_transaction_id' => 'TX-CONF-001',
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/correct", [
                'status' => PaymentStatus::Failed->value,
                'note' => 'Attempting an invalid reversal.',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHasErrors(['status']);

        $this->assertSame(PaymentStatus::Confirmed, $payment->fresh()->status);
    }

    public function test_uploading_proof_can_send_bank_transfer_notification_email_to_single_recipient(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['payments.bank_transfer.pop_notification_emails' => ['finance@example.test']]);

        [$applicant, $application] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $application->refresh();
        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();

        Mail::assertQueued(BankTransferProofSubmittedMail::class, function (BankTransferProofSubmittedMail $mail) use ($application, $payment) {
            $rendered = $mail->render();

            return $mail->hasTo('finance@example.test')
                && ! $mail->hasCc('records@example.test')
                && str_contains($rendered, $application->application_number)
                && str_contains($rendered, (string) $payment->invoice?->invoice_number)
                && ! str_contains($rendered, '/applicant/documents/');
        });

        $this->assertDatabaseHas('email_logs', [
            'application_id' => $application->id,
            'email' => 'finance@example.test',
            'template_key' => 'finance_payment_proof_submitted',
            'status' => 'queued',
        ]);
    }

    public function test_uploading_proof_uses_first_recipient_as_to_and_remaining_as_cc(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['payments.bank_transfer.pop_notification_emails' => ['finance@example.test', 'records@example.test', 'audit@example.test']]);

        [$applicant, $application] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        Mail::assertQueued(BankTransferProofSubmittedMail::class, fn (BankTransferProofSubmittedMail $mail) => $mail->hasTo('finance@example.test')
            && $mail->hasCc('records@example.test')
            && $mail->hasCc('audit@example.test'));
    }

    public function test_uploading_proof_skips_email_when_no_recipient_is_configured(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['payments.bank_transfer.pop_notification_emails' => []]);

        [$applicant, $application] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        Mail::assertNothingQueued();
    }

    public function test_mail_failure_does_not_fail_proof_upload(): void
    {
        Storage::fake('local');
        config(['payments.bank_transfer.pop_notification_emails' => ['finance@example.test']]);

        [$applicant, $application] = $this->makePaymentReadyApplication();

        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('mail down'));

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();
        $this->assertSame(PaymentStatus::AwaitingFinanceReview, $payment->status);
    }

    public function test_pending_proof_locks_applicant_editing_until_finance_rejects(): void
    {
        Storage::fake('local');
        config(['payments.bank_transfer.pop_notification_emails' => []]);

        [$applicant, $application, , $qualification] = $this->makePaymentReadyApplication();

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('proof.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertFalse($applicant->can('update', $application->fresh()));

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/initiate-card")
            ->assertSessionHasErrors(['payment']);

        $payment = Payment::query()->where('application_id', $application->id)->latest('id')->firstOrFail();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        app(PaymentProofReviewService::class)->reject($payment, $finance, 'Proof is unreadable');

        $this->assertTrue($applicant->fresh()->can('update', $application->fresh()));

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/documents", [
                'document_type' => 'transcript',
                'qualification_id' => $qualification->id,
                'file' => UploadedFile::fake()->create('transcript.pdf', 10, 'application/pdf'),
            ])
            ->assertRedirect();
    }

    public function test_finance_proof_approval_uses_application_submitted_pipeline_and_queues_auto_verification(): void
    {
        Queue::fake();

        [, $application, $invoice, $payment] = $this->makeManualProofPayment();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        app(PaymentProofReviewService::class)->approve($payment, $finance, 'Valid');

        $application->refresh();
        $payment->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);

        Queue::assertPushed(ProcessQualificationAutoVerificationJob::class);
    }

    public function test_finance_proof_approval_dispatches_application_submitted_event_once(): void
    {
        Event::fake([ApplicationSubmitted::class]);

        [, $application, , $payment] = $this->makeManualProofPayment();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $reviews = app(PaymentProofReviewService::class);
        $reviews->approve($payment, $finance, 'Valid');
        $reviews->approve($payment->fresh(), $finance, 'Second click');

        Event::assertDispatchedTimes(ApplicationSubmitted::class, 1);
        $application->refresh();
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
    }

    public function test_finance_rejection_leaves_invoice_unpaid_and_applicant_can_retry_proof_upload(): void
    {
        Storage::fake('local');
        config(['payments.bank_transfer.pop_notification_emails' => []]);

        [$applicant, $application, $invoice, $payment] = $this->makeManualProofPayment();
        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        app(PaymentProofReviewService::class)->reject($payment, $finance, 'Mismatch on deposit slip');

        $payment->refresh();
        $invoice->refresh();

        $this->assertSame(PaymentStatus::Rejected, $payment->status);
        $this->assertSame(InvoiceStatus::Issued, $invoice->status);

        $this->actingAs($applicant)
            ->post("/applicant/applications/{$application->id}/payment/upload-proof", [
                'file' => UploadedFile::fake()->create('retry-proof.pdf', 100, 'application/pdf'),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $payment->refresh();
        $this->assertSame(PaymentStatus::AwaitingFinanceReview, $payment->status);
        $this->assertNull($payment->rejection_reason);
    }

    public function test_finance_reject_requires_reason_and_cannot_reject_confirmed_payment(): void
    {
        [, , , $payment] = $this->makeManualProofPayment();

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        /** @var PaymentProofReviewService $reviews */
        $reviews = $this->app->make(PaymentProofReviewService::class);

        $this->expectException(ValidationException::class);
        $reviews->reject($payment, $finance, '  ');
    }

    public function test_finance_approve_and_reject_notifications_send_emails(): void
    {
        Mail::fake();

        [$applicant, $application, , $payment] = $this->makeManualProofPayment();

        $finance = User::factory()->activated()->create(['applicant_type' => null, 'email' => 'finance@example.test']);
        $finance->assignRole('Finance Officer');

        // Listener tests (run handlers directly, like other notification tests).
        (new SendPaymentProofApprovedNotification)->handle(new PaymentProofApproved($payment, $finance, 'Ok'));
        Mail::assertQueued(PaymentProofApprovedMail::class, fn (PaymentProofApprovedMail $m) => $m->hasTo($applicant->email) && $m->application->is($application));

        (new SendPaymentProofRejectedNotification)->handle(new PaymentProofRejected($payment, $finance, 'Blurry proof'));
        Mail::assertQueued(PaymentProofRejectedMail::class, fn (PaymentProofRejectedMail $m) => $m->hasTo($applicant->email) && $m->application->is($application));
    }

    public function test_finance_can_recheck_cgrate_gateway_status_for_expired_mobile_money_payment(): void
    {
        config([
            'cgrate.enabled' => true,
            'cgrate.username' => 'u',
            'cgrate.password' => 'p',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->cgrateSoapReturn(0, 'Successful', 'MP260623.1058.Z36675'), 200),
        ]);

        [, $application, $invoice] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Expired, [
            'method' => PaymentMethod::MobileMoney,
            'provider' => 'cgrate',
            'provider_reference' => 'ZAQA-9-10-DAHMPMWEM1',
            'expires_at' => now()->subMinute(),
        ]);

        PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'application_id' => $application->id,
            'gateway' => 'cgrate',
            'method' => 'mobile_money',
            'payment_reference' => 'ZAQA-9-10-DAHMPMWEM1',
            'mobile_number' => '0970000000',
            'status' => PaymentAttemptStatus::Expired,
            'currency' => 'ZMW',
            'amount_cents' => (int) $payment->amount_cents,
            'expired_at' => now()->subMinute(),
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->postJson("/admin/finance/payments/{$payment->id}/recheck-gateway")
            ->assertOk()
            ->assertJson([
                'supported' => true,
                'local_status' => PaymentStatus::Expired->value,
                'gateway_status' => PaymentStatus::Confirmed->value,
                'status_changed' => true,
                'will_submit_application' => true,
                'response_code' => 0,
                'response_message' => 'Successful',
                'provider_transaction_id' => 'MP260623.1058.Z36675',
            ]);
    }

    public function test_finance_can_apply_gateway_recheck_to_confirm_payment_and_submit_application(): void
    {
        Queue::fake();

        config([
            'cgrate.enabled' => true,
            'cgrate.username' => 'u',
            'cgrate.password' => 'p',
            'cgrate.base_url' => 'https://example.test',
            'cgrate.soap.endpoint_path' => '/Konik/KonikWs',
            'cgrate.soap.namespace' => 'http://konik.cgrate.com',
        ]);

        Http::fake([
            'https://example.test/Konik/KonikWs' => Http::response($this->cgrateSoapReturn(0, 'Successful', 'MP260623.1058.Z36675'), 200),
        ]);

        [, $application, $invoice] = $this->makePaymentReadyApplication();
        $payment = $this->createPaymentForInvoice($application, $invoice, PaymentStatus::Expired, [
            'method' => PaymentMethod::MobileMoney,
            'provider' => 'cgrate',
            'provider_reference' => 'ZAQA-9-10-DAHMPMWEM1',
            'expires_at' => now()->subMinute(),
        ]);

        $attempt = PaymentAttempt::query()->create([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'application_id' => $application->id,
            'gateway' => 'cgrate',
            'method' => 'mobile_money',
            'payment_reference' => 'ZAQA-9-10-DAHMPMWEM1',
            'mobile_number' => '0970000000',
            'status' => PaymentAttemptStatus::Expired,
            'currency' => 'ZMW',
            'amount_cents' => (int) $payment->amount_cents,
            'expired_at' => now()->subMinute(),
        ]);

        $finance = User::factory()->activated()->create(['applicant_type' => null]);
        $finance->assignRole('Finance Officer');

        $this->actingAs($finance)
            ->from("/admin/finance/payments/{$payment->id}")
            ->post("/admin/finance/payments/{$payment->id}/apply-gateway-recheck", [
                'note' => 'Gateway shows successful payment after local expiry.',
            ])
            ->assertRedirect("/admin/finance/payments/{$payment->id}")
            ->assertSessionHas('success');

        $payment->refresh();
        $attempt->refresh();
        $invoice->refresh();
        $application->refresh();

        $this->assertSame(PaymentStatus::Confirmed, $payment->status);
        $this->assertSame('MP260623.1058.Z36675', $payment->provider_transaction_id);
        $this->assertSame(PaymentAttemptStatus::Confirmed, $attempt->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(ApplicationStatus::Submitted, $application->current_status);
        $this->assertNotNull($application->submitted_at);

        Queue::assertPushed(ProcessQualificationAutoVerificationJob::class);

        $log = AuditLog::query()
            ->where('event_type', 'finance.payment_gateway_recheck_applied')
            ->where('entity_type', Payment::class)
            ->where('entity_id', $payment->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($log);
        $this->assertSame('expired', data_get($log?->before_state, 'status'));
        $this->assertSame('confirmed', data_get($log?->after_state, 'status'));
    }

    private function cgrateSoapReturn(?int $code, string $message, ?string $paymentId = null): string
    {
        $codeXml = $code === null ? '' : '<responseCode>'.$code.'</responseCode>';
        $pidXml = $paymentId ? '<paymentID>'.$paymentId.'</paymentID>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            .'<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
            .'<soapenv:Body>'
            .'<ns2:queryCustomerPaymentResponse xmlns:ns2="http://konik.cgrate.com">'
            .'<return>'
            .$codeXml
            .'<responseMessage>'.$message.'</responseMessage>'
            .$pidXml
            .'</return>'
            .'</ns2:queryCustomerPaymentResponse>'
            .'</soapenv:Body>'
            .'</soapenv:Envelope>';
    }
}
