<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceOfficerDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function financeUser(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Finance Officer');

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    public function test_finance_officer_cannot_access_verification_pool(): void
    {
        $this->actingAs($this->financeUser())
            ->get('/admin/verification/pool')
            ->assertForbidden();
    }

    public function test_finance_officer_cannot_access_qualification_review_page(): void
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FIN-REVIEW',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);
        $qualification = Qualification::query()->create([
            'application_id' => $application->id,
            'awarding_institution_name' => 'School',
            'qualification_holder_name' => 'Jane',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'is_foreign_qualification' => false,
        ]);

        $this->actingAs($this->financeUser())
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertForbidden();
    }

    public function test_finance_officer_can_access_payments_report_and_export(): void
    {
        $finance = $this->financeUser();

        $this->actingAs($finance)
            ->get('/admin/reports/payments?range=last30')
            ->assertOk();

        $this->actingAs($finance)
            ->get('/admin/reports/payments/export?range=last30&format=csv')
            ->assertOk();

        $this->actingAs($finance)
            ->get('/admin/reports/applications?range=last30')
            ->assertForbidden();
    }

    public function test_finance_dashboard_metrics_respect_selected_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        $finance = $this->financeUser();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        foreach ([5, 20] as $daysAgo) {
            $app = Application::query()->create([
                'uuid' => (string) Str::uuid(),
                'application_number' => 'ZAQA-FIN-RANGE-'.$daysAgo,
                'applicant_user_id' => $applicant->id,
                'applicant_type' => 'individual',
                'service_type' => 'verification',
                'qualification_category' => 'diploma',
                'current_status' => ApplicationStatus::Submitted,
                'is_foreign' => $daysAgo === 20,
                'metadata' => [],
                'submitted_at' => now()->subDays($daysAgo),
            ]);
            $invoice = Invoice::query()->create([
                'application_id' => $app->id,
                'invoice_number' => 'INV-RANGE-'.$daysAgo,
                'currency' => 'ZMW',
                'amount_cents' => $daysAgo === 5 ? 10000 : 20000,
                'status' => InvoiceStatus::Paid,
                'issued_at' => now()->subDays($daysAgo),
                'paid_at' => now()->subDays($daysAgo),
                'is_foreign_snapshot' => $daysAgo === 20,
                'fee_label_snapshot' => $daysAgo === 5 ? 'Local verification fee' : 'Foreign verification fee',
                'metadata' => [],
            ]);
            Payment::query()->create([
                'application_id' => $app->id,
                'invoice_id' => $invoice->id,
                'method' => PaymentMethod::MobileMoney,
                'status' => PaymentStatus::Confirmed,
                'currency' => 'ZMW',
                'amount_cents' => $invoice->amount_cents,
                'confirmed_at' => now()->subDays($daysAgo),
            ]);
        }

        $props30 = $this->inertiaProps($this->actingAs($finance)->get('/admin/dashboard?range=30'));
        $revenue30 = collect($props30['kpis'])->firstWhere('key', 'revenue_period');
        $this->assertSame(30000, (int) $revenue30['value']);

        $local30 = collect($props30['kpis'])->firstWhere('key', 'revenue_local_qualifications');
        $foreign30 = collect($props30['kpis'])->firstWhere('key', 'revenue_foreign_qualifications');
        $this->assertSame(10000, (int) $local30['value']);
        $this->assertSame(20000, (int) $foreign30['value']);

        $props7 = $this->inertiaProps($this->actingAs($finance)->get('/admin/dashboard?range=7'));
        $revenue7 = collect($props7['kpis'])->firstWhere('key', 'revenue_period');
        $this->assertSame(10000, (int) $revenue7['value']);
    }

    public function test_finance_dashboard_excludes_pending_payments_from_revenue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        $finance = $this->financeUser();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FIN-PENDING',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => false,
            'metadata' => [],
        ]);
        $invoice = Invoice::query()->create([
            'application_id' => $app->id,
            'invoice_number' => 'INV-PENDING',
            'currency' => 'ZMW',
            'amount_cents' => 99900,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now()->subDay(),
            'is_foreign_snapshot' => false,
            'fee_label_snapshot' => 'Local verification fee',
            'metadata' => [],
        ]);
        Payment::query()->create([
            'application_id' => $app->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::AwaitingFinanceReview,
            'currency' => 'ZMW',
            'amount_cents' => 99900,
            'initiated_at' => now()->subDay(),
            'last_status_at' => now()->subDay(),
        ]);
        Payment::query()->create([
            'application_id' => $app->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'confirmed_at' => now()->subDay(),
        ]);

        $props = $this->inertiaProps($this->actingAs($finance)->get('/admin/dashboard?range=30'));
        $revenue = collect($props['kpis'])->firstWhere('key', 'revenue_period');
        $this->assertSame(5000, (int) $revenue['value']);

        $feeRows = collect($props['finance_breakdowns']['revenue_by_fee_structure'] ?? []);
        $this->assertSame(5000, (int) $feeRows->firstWhere('label', 'Local verification fee')['amount_cents']);
    }

    public function test_finance_hero_quick_actions_do_not_include_verification_pool(): void
    {
        $props = $this->inertiaProps($this->actingAs($this->financeUser())->get('/admin/dashboard'));
        $hrefs = collect($props['quick_actions'])->pluck('href')->all();

        $this->assertNotContains('/admin/verification/pool', $hrefs);
        $this->assertContains('/admin/finance/payment-proofs', $hrefs);
        $this->assertContains('/admin/reports/payments', $hrefs);
    }
}
