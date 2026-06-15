<?php

namespace Tests\Feature;

use App\Enums\ApplicantType;
use App\Models\ApplicantProfile;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApplicantPaymentsPageTest extends TestCase
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

    public function test_payments_page_shows_empty_state_when_none_exist(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);

        $this->actingAs($user);

        $this->get('/applicant/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Payments', false)
                ->has('payments', 0)
                ->where('summary.count', 0)
            );
    }

    public function test_payments_page_only_shows_authenticated_users_payments(): void
    {
        $userA = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        $userB = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);

        ApplicantProfile::create([
            'user_id' => $userA->id,
            'first_name' => 'A',
            'surname' => 'User',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'email' => $userA->email,
            'phone_primary' => $userA->phone_primary,
        ]);
        ApplicantProfile::create([
            'user_id' => $userB->id,
            'first_name' => 'B',
            'surname' => 'User',
            'nrc_number' => '222222/22/2',
            'passport_number' => null,
            'email' => $userB->email,
            'phone_primary' => $userB->phone_primary,
        ]);

        // Create minimal applications + invoices + payments directly (no factories in this repo).
        $now = Carbon::now();

        $appA = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'APP-A-0001',
            'applicant_user_id' => $userA->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'draft',
            'verification_state' => 'awaiting_assignment',
            'is_foreign' => false,
        ]);
        $invA = Invoice::query()->create([
            'application_id' => $appA->id,
            'invoice_number' => 'INV-A-0001',
            'currency' => 'ZMW',
            'amount_cents' => 12300,
            'status' => 'paid',
            'issued_at' => $now,
            'paid_at' => $now,
        ]);
        $payA = Payment::query()->create([
            'application_id' => $appA->id,
            'invoice_id' => $invA->id,
            'method' => 'card',
            'status' => 'confirmed',
            'currency' => 'ZMW',
            'amount_cents' => 12300,
            'provider' => 'test',
            'provider_reference' => 'TX-A-123',
            'confirmed_at' => $now,
            'initiated_at' => $now,
            'last_status_at' => $now,
        ]);

        $appB = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'APP-B-0001',
            'applicant_user_id' => $userB->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'draft',
            'verification_state' => 'awaiting_assignment',
            'is_foreign' => false,
        ]);
        $invB = Invoice::query()->create([
            'application_id' => $appB->id,
            'invoice_number' => 'INV-B-0001',
            'currency' => 'ZMW',
            'amount_cents' => 99900,
            'status' => 'paid',
            'issued_at' => $now,
            'paid_at' => $now,
        ]);
        Payment::query()->create([
            'application_id' => $appB->id,
            'invoice_id' => $invB->id,
            'method' => 'card',
            'status' => 'confirmed',
            'currency' => 'ZMW',
            'amount_cents' => 99900,
            'provider' => 'test',
            'provider_reference' => 'TX-B-999',
            'confirmed_at' => $now,
            'initiated_at' => $now,
            'last_status_at' => $now,
        ]);

        $this->actingAs($userA);

        $this->get('/applicant/payments')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/Payments', false)
                ->has('payments', 1)
                ->where('payments.0.invoice.invoice_number', $invA->invoice_number)
                ->where('payments.0.application.application_number', $appA->application_number)
                ->where('payments.0.provider_reference', $payA->provider_reference)
            );
    }

    public function test_applicant_can_view_payment_detail_for_own_application(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);
        $now = Carbon::now();
        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'APP-VIEW-PAY-1',
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'draft',
            'verification_state' => 'awaiting_assignment',
            'is_foreign' => false,
        ]);
        $inv = Invoice::query()->create([
            'application_id' => $app->id,
            'invoice_number' => 'INV-VIEW-1',
            'currency' => 'ZMW',
            'amount_cents' => 10000,
            'status' => 'paid',
            'issued_at' => $now,
            'paid_at' => $now,
        ]);
        $payment = Payment::query()->create([
            'application_id' => $app->id,
            'invoice_id' => $inv->id,
            'method' => 'card',
            'status' => 'confirmed',
            'currency' => 'ZMW',
            'amount_cents' => 10000,
            'provider' => 'test',
            'provider_reference' => 'TX-VIEW',
            'confirmed_at' => $now,
            'initiated_at' => $now,
            'last_status_at' => $now,
        ]);

        $this->actingAs($user);

        $this->get('/applicant/payments/'.$payment->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/PaymentShow', false)
                ->where('payment.id', $payment->id)
                ->where('payment.application.application_number', $app->application_number)
                ->where('payment.invoice.invoice_number', $inv->invoice_number)
                ->has('document')
                ->where('document.receipt_number_display', 'ZQ '.$payment->id)
                ->where('document.is_official_receipt', true)
            );
    }

    public function test_applicant_cannot_view_payment_for_another_users_application(): void
    {
        $userA = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        $userB = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        foreach ([$userA, $userB] as $u) {
            ApplicantProfile::create([
                'user_id' => $u->id,
                'first_name' => 'X',
                'surname' => 'Y',
                'nrc_number' => '111111/11/1',
                'passport_number' => null,
                'email' => $u->email,
                'phone_primary' => $u->phone_primary,
            ]);
        }
        $now = Carbon::now();
        $appB = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'APP-OTHER',
            'applicant_user_id' => $userB->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'draft',
            'verification_state' => 'awaiting_assignment',
            'is_foreign' => false,
        ]);
        $invB = Invoice::query()->create([
            'application_id' => $appB->id,
            'invoice_number' => 'INV-OTHER',
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'status' => 'issued',
            'issued_at' => $now,
            'paid_at' => null,
        ]);
        $payB = Payment::query()->create([
            'application_id' => $appB->id,
            'invoice_id' => $invB->id,
            'method' => 'card',
            'status' => 'initiated',
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'provider' => 'test',
            'provider_reference' => 'TX-OTHER',
            'initiated_at' => $now,
            'last_status_at' => $now,
        ]);

        $this->actingAs($userA);

        $this->get('/applicant/payments/'.$payB->id)->assertForbidden();
    }

    public function test_applicant_can_view_invoice_detail(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => ApplicantType::Individual]);
        ApplicantProfile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'surname' => 'Doe',
            'nrc_number' => '111111/11/1',
            'passport_number' => null,
            'email' => $user->email,
            'phone_primary' => $user->phone_primary,
        ]);
        $now = Carbon::now();
        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'APP-INV-SHOW',
            'applicant_user_id' => $user->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'certificate',
            'current_status' => 'draft',
            'verification_state' => 'awaiting_assignment',
            'is_foreign' => false,
        ]);
        $inv = Invoice::query()->create([
            'application_id' => $app->id,
            'invoice_number' => 'INV-SHOW-1',
            'currency' => 'ZMW',
            'amount_cents' => 8000,
            'status' => 'issued',
            'issued_at' => $now,
            'paid_at' => null,
        ]);

        $this->actingAs($user);

        $this->get('/applicant/invoices/'.$inv->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Applicant/InvoiceShow', false)
                ->where('invoice.invoice_number', $inv->invoice_number)
                ->has('invoice.payments')
                ->has('document')
                ->where('document.invoice_number', $inv->invoice_number)
                ->has('document.line_items')
            );
    }
}

