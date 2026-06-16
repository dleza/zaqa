<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
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

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    public function test_super_admin_dashboard_loads_with_kpis_and_charts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 10:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create([
            'first_name' => 'Martin',
            'last_name' => 'Test',
            'name' => 'Martin Test',
            'applicant_type' => null,
        ]);
        $admin->assignRole('Super Admin');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-DASH-001',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AwaitingAssignment,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(10),
        ]);

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $response->assertOk();
        $response->assertInertia(fn ($page) => $page->component('Admin/Dashboard', shouldExist: false));

        $props = $this->inertiaProps($response);
        $this->assertNotEmpty($props['kpis']);
        $this->assertNotEmpty($props['charts']);
        $this->assertFalse($props['empty']);
        $this->assertStringContainsString('Martin', $props['meta']['greeting_line']);
        $this->assertStringStartsWith('Good morning', $props['meta']['greeting_line']);

        $submittedToday = collect($props['kpis'])->firstWhere('key', 'applications_submitted_today');
        $this->assertNotNull($submittedToday);
        $this->assertSame(1, (int) $submittedToday['value']);
    }

    public function test_greeting_uses_first_name_when_set(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 14:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create([
            'first_name' => 'Asha',
            'name' => 'Asha Banda',
            'applicant_type' => null,
        ]);
        $admin->assignRole('Super Admin');

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);
        $this->assertSame('Good afternoon, Asha', $props['meta']['greeting_line']);
    }

    public function test_finance_user_receives_only_finance_dashboard_sections(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 12:00:00', config('app.timezone')));

        $finance = User::factory()->activated()->create(['applicant_type' => null, 'name' => 'Finance User']);
        $finance->assignRole('Finance Officer');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-DASH-FIN',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'application_id' => $app->id,
            'invoice_number' => 'INV-DASH-1',
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'status' => InvoiceStatus::Issued,
            'issued_at' => now(),
            'metadata' => [],
        ]);

        Payment::query()->create([
            'application_id' => $app->id,
            'invoice_id' => $invoice->id,
            'method' => PaymentMethod::MobileMoney,
            'status' => PaymentStatus::Confirmed,
            'currency' => 'ZMW',
            'amount_cents' => 5000,
            'confirmed_at' => now(),
        ]);

        $response = $this->actingAs($finance)->get('/admin/dashboard');
        $response->assertOk();
        $props = $this->inertiaProps($response);

        $chartKeys = collect($props['charts'])->pluck('key')->all();
        $this->assertContains('finance_revenue_week', $chartKeys);
        $this->assertNotContains('applications_submitted_week', $chartKeys);
        $this->assertNotContains('audit_events_week', $chartKeys);

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('invoices_today', $kpiKeys);
        $this->assertNotContains('applications_total', $kpiKeys);
    }

    public function test_level_one_user_does_not_receive_audit_or_finance_charts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 11:00:00', config('app.timezone')));

        $l1 = User::factory()->activated()->create(['applicant_type' => null]);
        $l1->assignRole('Verification Officer Level 1');

        $response = $this->actingAs($l1)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);

        $chartKeys = collect($props['charts'])->pluck('key')->all();
        $this->assertContains('verification_pool_submissions_week', $chartKeys);
        $this->assertContains('verification_l1_completed_week', $chartKeys);
        $this->assertContains('verification_l1_assigned_by_state', $chartKeys);
        $this->assertNotContains('applications_submitted_week', $chartKeys);
        $this->assertNotContains('applications_by_status', $chartKeys);
        $this->assertNotContains('finance_revenue_week', $chartKeys);
        $this->assertNotContains('audit_events_week', $chartKeys);

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('l1_total_assigned_30d', $kpiKeys);
        $this->assertContains('l1_total_processed_30d', $kpiKeys);
        $this->assertNotContains('applications_total', $kpiKeys);
        $this->assertNotContains('applications_submitted_today', $kpiKeys);
        $this->assertNotContains('verification_overdue', $kpiKeys);
        $this->assertNotContains('l1_total_assigned_ever', $kpiKeys);
        $this->assertSame('level1_assigned', $props['meta']['dashboard_scope']);
    }

    public function test_level_one_dashboard_counts_only_qualifications_assigned_to_officer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));
        $today = now()->toDateString();

        $l1 = User::factory()->activated()->create(['applicant_type' => null]);
        $l1->assignRole('Verification Officer Level 1');
        $otherL1 = User::factory()->activated()->create(['applicant_type' => null]);
        $otherL1->assignRole('Verification Officer Level 1');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $appToday = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L1-TODAY',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(10),
        ]);

        $qualToday = \App\Models\Qualification::query()->create([
            'application_id' => $appToday->id,
            'verification_reference_number' => 'ZAQA-Q-L1-TODAY',
            'assigned_verifier_id' => $l1->id,
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Jane Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '111111/11/1',
            'title_of_qualification' => 'Diploma in Testing',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        \App\Models\QualificationAssignment::query()->create([
            'qualification_id' => $qualToday->id,
            'assigned_by_user_id' => $otherL1->id,
            'assigned_to_user_id' => $l1->id,
            'assigned_at' => now()->subDay(),
        ]);

        $appOther = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L1-OTHER',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->addDays(10),
        ]);

        \App\Models\Qualification::query()->create([
            'application_id' => $appOther->id,
            'verification_reference_number' => 'ZAQA-Q-L1-OTHER',
            'assigned_verifier_id' => $otherL1->id,
            'awarding_institution_name' => 'Other Institution',
            'qualification_holder_name' => 'John Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '222222/22/2',
            'title_of_qualification' => 'Other Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $props = $this->inertiaProps($this->actingAs($l1)->get('/admin/dashboard'));

        $totalAssigned = collect($props['kpis'])->firstWhere('key', 'l1_total_assigned_30d');
        $this->assertNotNull($totalAssigned);
        $this->assertSame(1, (int) $totalAssigned['value']);
        $this->assertSame('/admin/reports/my-performance?range=last30', $totalAssigned['href']);

        $submittedToday = collect($props['kpis'])->firstWhere('key', 'l1_assigned_submitted_today');
        $this->assertNotNull($submittedToday);
        $this->assertSame(1, (int) $submittedToday['value']);
        $this->assertStringContainsString($today, (string) $submittedToday['href']);

        $recent = collect($props['queues'])->firstWhere('key', 'recent_submissions');
        $this->assertNotNull($recent);
        $this->assertCount(1, $recent['items']);
        $this->assertSame('/admin/verification/qualifications/'.$qualToday->id, $recent['items'][0]['href']);
    }

    public function test_level_one_officer_can_open_my_performance_report(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $l1 = User::factory()->activated()->create(['applicant_type' => null, 'name' => 'Demo L1']);
        $l1->assignRole('Verification Officer Level 1');

        $this->actingAs($l1)
            ->get('/admin/reports/my-performance?range=last30')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Reports/Level1Performance')
                ->where('dashboard.summary.assigned', 0)
                ->where('dashboard.summary.processed', 0)
            );

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');

        $this->actingAs($l2)->get('/admin/reports/my-performance')->assertForbidden();
    }

    public function test_auditor_receives_audit_data_not_finance_or_applications_kpis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 09:00:00', config('app.timezone')));

        AuditLog::query()->create([
            'actor_user_id' => null,
            'actor_name_snapshot' => 'System',
            'event_type' => 'test.event',
            'module' => 'Test',
            'entity_type' => null,
            'entity_id' => null,
            'action_name' => 'ping',
            'message' => 'Dashboard audit visibility test',
        ]);

        $auditor = User::factory()->activated()->create(['applicant_type' => null]);
        $auditor->assignRole('Auditor');

        $response = $this->actingAs($auditor)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);

        $chartKeys = collect($props['charts'])->pluck('key')->all();
        $this->assertContains('audit_events_week', $chartKeys);
        $this->assertNotContains('finance_revenue_week', $chartKeys);

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('audit_events_today', $kpiKeys);
        $this->assertNotContains('revenue_today', $kpiKeys);
    }

    public function test_weekly_chart_labels_are_seven_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 15:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);

        $weekChart = collect($props['charts'])->firstWhere('key', 'applications_submitted_week');
        $this->assertNotNull($weekChart);
        $this->assertCount(7, $weekChart['labels']);
        $this->assertCount(7, $weekChart['values']);
    }

    public function test_weekly_submission_counts_match_created_applications(): void
    {
        $monday = Carbon::parse('2026-04-20 12:00:00', config('app.timezone'))->startOfWeek(Carbon::MONDAY);
        Carbon::setTestNow($monday->copy()->addDays(2)->setTime(12, 0));

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        foreach ([0, 1, 2] as $i) {
            Application::query()->create([
                'uuid' => (string) Str::uuid(),
                'application_number' => 'ZAQA-WEEK-'.$i,
                'applicant_user_id' => $applicant->id,
                'applicant_type' => 'individual',
                'service_type' => 'verification',
                'qualification_category' => 'diploma',
                'current_status' => ApplicationStatus::Submitted,
                'is_foreign' => false,
                'metadata' => [],
                'submitted_at' => $monday->copy()->addDays($i)->setTime(10, 0),
            ]);
        }

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);
        $weekChart = collect($props['charts'])->firstWhere('key', 'applications_submitted_week');
        $this->assertNotNull($weekChart);

        $idxMon = 0;
        $idxTue = 1;
        $idxWed = 2;
        $this->assertSame(1, (int) $weekChart['values'][$idxMon]);
        $this->assertSame(1, (int) $weekChart['values'][$idxTue]);
        $this->assertSame(1, (int) $weekChart['values'][$idxWed]);
    }
}
