<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceDashboardMetricsService;
use App\Domain\Payments\InvoiceService;
use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Application;
use App\Models\BillingCategory;
use App\Models\FeeStructure;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Qualification;
use App\Models\QualificationType;
use App\Models\User;
use Database\Seeders\BillingCategoriesSeeder;
use Database\Seeders\FeeStructuresSeeder;
use Database\Seeders\QualificationTypesSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceRevenueByFeeStructureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-01-01 12:00:00', config('app.timezone')));
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(BillingCategoriesSeeder::class);
        $this->seed(QualificationTypesSeeder::class);
        $this->seed(FeeStructuresSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    private function financeUser(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Finance Officer');

        return $user;
    }

    /**
     * @return array{Application, Invoice, list<Qualification>}
     */
    private function applicationWithQualifications(array $qualificationSpecs): array
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-FEE-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Draft,
            'is_foreign' => collect($qualificationSpecs)->contains(fn ($s) => (bool) ($s['is_foreign'] ?? false)),
            'metadata' => [],
        ]);

        $qualifications = [];
        foreach ($qualificationSpecs as $spec) {
            $type = QualificationType::query()->where('zqf_level_code', $spec['zqf'])->firstOrFail();
            $qualifications[] = Qualification::query()->create([
                'application_id' => $application->id,
                'awarding_institution_name' => 'Test Institution',
                'qualification_holder_name' => 'Jane Doe',
                'country_name_other' => ($spec['is_foreign'] ?? false) ? 'United Kingdom' : 'Zambia',
                'nrc_passport_number' => '111111/11/1',
                'title_of_qualification' => $spec['title'] ?? $type->name,
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type' => $type->zqf_level_code,
                'qualification_type_id' => $type->id,
                'is_foreign_qualification' => (bool) ($spec['is_foreign'] ?? false),
                'transcript_required' => false,
            ]);
        }

        $invoice = app(InvoiceService::class)->ensureInvoice($application->fresh()->load('qualifications'), $applicant);

        return [$application, $invoice, $qualifications];
    }

    private function confirmPayment(Invoice $invoice, ?Carbon $confirmedAt = null, ?int $amountCents = null): Payment
    {
        $confirmedAt ??= now();

        return Payment::query()->create([
            'application_id' => $invoice->application_id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => $invoice->currency,
            'amount_cents' => $amountCents ?? (int) $invoice->amount_cents,
            'provider' => 'test',
            'confirmed_at' => $confirmedAt,
            'last_status_at' => $confirmedAt,
        ]);
    }

    public function test_revenue_by_fee_structure_uses_billing_category_labels_from_settings(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([
            ['zqf' => 'L6', 'is_foreign' => false, 'title' => 'Diploma'],
        ]);
        $this->confirmPayment($invoice);

        $localCertsCategory = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail();

        /** @var FinanceDashboardMetricsService $metrics */
        $metrics = app(FinanceDashboardMetricsService::class);
        $rows = $metrics->revenueByFeeStructure(now()->subDays(30), now());

        $this->assertCount(1, $rows);
        $this->assertSame($localCertsCategory->name, $rows[0]['label']);
        $this->assertSame(20000, $rows[0]['amount_cents']);
        $this->assertSame($localCertsCategory->id, $rows[0]['billing_category_id']);
        $this->assertNotNull($rows[0]['fee_structure_id']);
    }

    public function test_multi_qualification_invoice_allocates_revenue_per_fee_structure_line(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([
            ['zqf' => 'L6', 'is_foreign' => false, 'title' => 'Diploma'],
            ['zqf' => 'L7', 'is_foreign' => false, 'title' => 'Degree'],
        ]);
        $this->confirmPayment($invoice);

        $localCerts = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail()->name;
        $localDegrees = BillingCategory::query()->where('code', 'LOCAL_DEGREES')->firstOrFail()->name;

        $rows = collect(app(FinanceDashboardMetricsService::class)->revenueByFeeStructure(now()->subDays(30), now()))
            ->keyBy('label');

        $this->assertSame(20000, (int) $rows[$localCerts]['amount_cents']);
        $this->assertSame(50000, (int) $rows[$localDegrees]['amount_cents']);
    }

    public function test_foreign_qualification_revenue_groups_under_foreign_qualifications_fee_structure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([
            ['zqf' => 'L7', 'is_foreign' => true, 'title' => 'Foreign Degree'],
        ]);
        $this->confirmPayment($invoice);

        $foreignCategory = BillingCategory::query()->where('code', 'FOREIGN_QUALIFICATIONS')->firstOrFail();

        $rows = app(FinanceDashboardMetricsService::class)->revenueByFeeStructure(now()->subDays(30), now());
        $this->assertCount(1, $rows);
        $this->assertSame($foreignCategory->name, $rows[0]['label']);
        $this->assertSame(120000, $rows[0]['amount_cents']);
        $this->assertTrue($rows[0]['is_foreign_snapshot'] ?? false);
    }

    public function test_pending_and_failed_payments_are_excluded_from_fee_structure_revenue(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([
            ['zqf' => 'L6', 'is_foreign' => false],
        ]);

        Payment::query()->create([
            'application_id' => $invoice->application_id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::BankTransfer,
            'status' => PaymentStatus::AwaitingFinanceReview,
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'initiated_at' => now(),
            'last_status_at' => now(),
        ]);

        Payment::query()->create([
            'application_id' => $invoice->application_id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::Card,
            'status' => PaymentStatus::Failed,
            'currency' => $invoice->currency,
            'amount_cents' => $invoice->amount_cents,
            'failed_at' => now(),
            'last_status_at' => now(),
        ]);

        $this->confirmPayment($invoice);

        $rows = app(FinanceDashboardMetricsService::class)->revenueByFeeStructure(now()->subDays(30), now());
        $this->assertCount(1, $rows);
        $this->assertSame(20000, $rows[0]['amount_cents']);
    }

    public function test_fee_structure_revenue_respects_last_seven_and_thirty_day_ranges(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoiceRecent] = $this->applicationWithQualifications([['zqf' => 'L6', 'is_foreign' => false]]);
        $this->confirmPayment($invoiceRecent, now()->subDays(5));

        [, $invoiceOlder] = $this->applicationWithQualifications([['zqf' => 'L7', 'is_foreign' => false]]);
        $this->confirmPayment($invoiceOlder, now()->subDays(20));

        $metrics = app(FinanceDashboardMetricsService::class);
        $rows30 = collect($metrics->revenueByFeeStructure(now()->subDays(30), now()));
        $this->assertSame(70000, $rows30->sum('amount_cents'));

        $rows7 = collect($metrics->revenueByFeeStructure(now()->subDays(7), now()));
        $this->assertSame(20000, $rows7->sum('amount_cents'));
    }

    public function test_historical_invoice_snapshot_reports_against_original_fee_structure_after_fee_change(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([['zqf' => 'L6', 'is_foreign' => false]]);
        $breakdown = (array) data_get($invoice->metadata, 'breakdown', []);
        $originalFeeStructureId = (int) ($breakdown[0]['fee_structure_id'] ?? 0);
        $this->assertGreaterThan(0, $originalFeeStructureId);

        $this->confirmPayment($invoice, now());

        $category = BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->firstOrFail();
        FeeStructure::query()->create([
            'billing_category_id' => $category->id,
            'local_fee_cents' => 99999,
            'foreign_fee_cents' => 199999,
            'currency' => 'ZMW',
            'effective_from' => now()->addSecond(),
            'effective_to' => null,
            'is_active' => true,
            'change_reason' => 'Test fee increase',
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        $rows = app(FinanceDashboardMetricsService::class)->revenueByFeeStructure(now()->subDays(30), now());
        $this->assertCount(1, $rows);
        $this->assertSame($originalFeeStructureId, $rows[0]['fee_structure_id']);
        $this->assertSame(20000, $rows[0]['amount_cents']);
        $this->assertSame($category->name, $rows[0]['label']);
    }

    public function test_finance_dashboard_returns_empty_fee_structure_state_when_no_revenue(): void
    {
        $props = $this->inertiaProps($this->actingAs($this->financeUser())->get('/admin/dashboard?range=30'));
        $this->assertSame([], $props['finance_breakdowns']['revenue_by_fee_structure'] ?? []);
    }

    public function test_finance_dashboard_fee_structure_breakdown_on_dashboard_payload(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $invoice] = $this->applicationWithQualifications([['zqf' => 'L6', 'is_foreign' => false]]);
        $this->confirmPayment($invoice);

        $props = $this->inertiaProps($this->actingAs($this->financeUser())->get('/admin/dashboard?range=30'));
        $rows = $props['finance_breakdowns']['revenue_by_fee_structure'] ?? [];

        $this->assertCount(1, $rows);
        $this->assertSame(
            BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->value('name'),
            $rows[0]['label']
        );
        $this->assertSame(20000, (int) $rows[0]['amount_cents']);
        $this->assertGreaterThan(0, (int) $rows[0]['count']);
    }

    public function test_local_and_foreign_revenue_tiles_remain_separate_from_fee_structure_breakdown(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        [, $localInvoice] = $this->applicationWithQualifications([['zqf' => 'L6', 'is_foreign' => false]]);
        $this->confirmPayment($localInvoice);

        [, $foreignInvoice] = $this->applicationWithQualifications([['zqf' => 'L7', 'is_foreign' => true]]);
        $this->confirmPayment($foreignInvoice);

        $props = $this->inertiaProps($this->actingAs($this->financeUser())->get('/admin/dashboard?range=30'));
        $local = collect($props['kpis'])->firstWhere('key', 'revenue_local_qualifications');
        $foreign = collect($props['kpis'])->firstWhere('key', 'revenue_foreign_qualifications');
        $feeRows = collect($props['finance_breakdowns']['revenue_by_fee_structure'] ?? []);

        $this->assertSame(20000, (int) $local['value']);
        $this->assertSame(120000, (int) $foreign['value']);
        $this->assertCount(2, $feeRows);
        $this->assertSame(140000, $feeRows->sum('amount_cents'));
    }

    public function test_supplementary_payment_allocates_delta_to_amended_fee_structure(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $typeL6 = QualificationType::query()->where('zqf_level_code', 'L6')->firstOrFail();
        $typeL7 = QualificationType::query()->where('zqf_level_code', 'L7')->firstOrFail();

        $application = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-SUP-FEE-'.Str::upper(Str::random(4)),
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
            'awarding_institution_name' => 'Test Uni',
            'qualification_holder_name' => 'Jane',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => $typeL6->zqf_level_code,
            'qualification_type_id' => $typeL6->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $invoiceService = app(InvoiceService::class);
        $primary = $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);
        $primary->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => now()])->save();
        $this->confirmPayment($primary);

        $qualification->forceFill([
            'qualification_type_id' => $typeL7->id,
            'qualification_type' => $typeL7->zqf_level_code,
        ])->save();

        $invoiceService->ensureInvoice($application->fresh()->load('qualifications'), $applicant);
        $supplementary = Invoice::query()
            ->where('application_id', $application->id)
            ->whereNotNull('supplementary_of_invoice_id')
            ->firstOrFail();

        $this->confirmPayment($supplementary);

        $localDegrees = BillingCategory::query()->where('code', 'LOCAL_DEGREES')->firstOrFail()->name;
        $rows = collect(app(FinanceDashboardMetricsService::class)->revenueByFeeStructure(now()->subDays(30), now()))
            ->keyBy('label');

        $this->assertSame(20000, (int) ($rows[BillingCategory::query()->where('code', 'LOCAL_CERTS_DIPLOMAS')->value('name')] ?? ['amount_cents' => 0])['amount_cents']);
        $this->assertSame(30000, (int) ($rows[$localDegrees]['amount_cents'] ?? 0));
    }
}
