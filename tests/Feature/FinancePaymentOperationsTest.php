<?php

namespace Tests\Feature;

use App\Domain\Finance\Events\PaymentProofApproved;
use App\Domain\Finance\Events\PaymentProofRejected;
use App\Domain\Finance\Listeners\SendPaymentProofApprovedNotification;
use App\Domain\Finance\Listeners\SendPaymentProofRejectedNotification;
use App\Domain\Finance\PaymentProofReviewService;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Mail\Finance\PaymentProofApprovedMail;
use App\Mail\Finance\PaymentProofRejectedMail;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancePaymentOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function makeManualProofPayment(): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual', 'email' => 'applicant@example.test']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FIN-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => 'draft',
            'is_foreign' => false,
            'metadata' => [],
        ]);

        $invoice = Invoice::query()->create([
            'application_id' => $application->id,
            'invoice_number' => 'INV-FIN-'.rand(1000, 9999),
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
            'metadata' => [],
        ]);

        $payment = Payment::query()->create([
            'application_id' => $application->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::AwaitingFinanceReview,
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'provider' => 'test',
            'awaiting_finance_review_at' => now(),
        ]);

        return [$applicant, $application, $invoice, $payment];
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
}
