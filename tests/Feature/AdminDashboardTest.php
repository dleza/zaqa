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
use App\Models\Qualification;
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

        $submitted = collect($props['kpis'])->firstWhere('key', 'applications_total');
        $this->assertNotNull($submitted);
        $this->assertSame(1, (int) $submitted['value']);
        $this->assertSame(30, (int) $props['meta']['date_range']['selected']);
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
        $this->assertContains('invoices_issued', $kpiKeys);
        $this->assertContains('payments_confirmed', $kpiKeys);
        $this->assertNotContains('applications_total', $kpiKeys);
        $this->assertSame(30, (int) $props['meta']['date_range']['selected']);
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
        $this->assertContains('verification_l1_assigned_by_state', $chartKeys);
        $this->assertNotContains('verification_l1_completed_week', $chartKeys);
        $this->assertNotContains('applications_submitted_week', $chartKeys);
        $this->assertNotContains('applications_by_status', $chartKeys);
        $this->assertNotContains('finance_revenue_week', $chartKeys);
        $this->assertNotContains('audit_events_week', $chartKeys);

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('l1_total_assigned_30d', $kpiKeys);
        $this->assertContains('l1_total_processed_30d', $kpiKeys);
        $this->assertNotContains('applications_total', $kpiKeys);
        $this->assertContains('l1_pending_assigned', $kpiKeys);
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
        $this->assertNotContains('revenue_period', $kpiKeys);
    }

    public function test_dashboard_defaults_to_last_thirty_days_and_invalid_range_falls_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $defaultProps = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard'));
        $this->assertSame(30, (int) $defaultProps['meta']['date_range']['selected']);
        $this->assertCount(2, $defaultProps['meta']['date_range']['options']);

        $sevenProps = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard?range=7'));
        $this->assertSame(7, (int) $sevenProps['meta']['date_range']['selected']);

        $invalidProps = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard?range=99'));
        $this->assertSame(30, (int) $invalidProps['meta']['date_range']['selected']);
    }

    public function test_range_chart_labels_match_selected_window(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-26 15:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $props7 = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard?range=7'));
        $weekChart7 = collect($props7['charts'])->firstWhere('key', 'applications_submitted_week');
        $this->assertNotNull($weekChart7);
        $this->assertCount(7, $weekChart7['labels']);

        $props30 = $this->inertiaProps($this->actingAs($admin)->get('/admin/dashboard'));
        $weekChart30 = collect($props30['charts'])->firstWhere('key', 'applications_submitted_week');
        $this->assertNotNull($weekChart30);
        $this->assertCount(30, $weekChart30['labels']);
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

        $response = $this->actingAs($admin)->get('/admin/dashboard?range=7');
        $props = $this->inertiaProps($response);
        $weekChart = collect($props['charts'])->firstWhere('key', 'applications_submitted_week');
        $this->assertNotNull($weekChart);
        $this->assertSame(3, array_sum($weekChart['values']));
    }

    public function test_level_two_dashboard_shows_qualification_metrics_not_application_totals(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-DASH',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
            'service_deadline_at' => now()->subDay(),
        ]);

        foreach (['Q1', 'Q2', 'Q3'] as $index => $suffix) {
            Qualification::query()->create([
                'application_id' => $app->id,
                'verification_reference_number' => 'ZAQA-L2-'.$suffix,
                'assigned_verifier_id' => null,
                'level2_review_owner_id' => $index === 0 ? $l2->id : null,
                'awarding_institution_name' => 'Test Institution',
                'qualification_holder_name' => 'Holder '.$suffix,
                'country_name_other' => 'Zambia',
                'nrc_passport_number' => '11111'.$index.'/11/1',
                'title_of_qualification' => 'Diploma '.$suffix,
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type' => 'L6',
                'verification_state' => $index === 2
                    ? VerificationState::AssignedToLevel1
                    : VerificationState::UnderLevel2Review,
                'is_foreign_qualification' => false,
                'transcript_required' => false,
                'service_deadline_at' => now()->subDay(),
            ]);
        }

        $props = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'));

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('l2_total_qualifications', $kpiKeys);
        $this->assertContains('l2_pending', $kpiKeys);
        $this->assertContains('l2_processed', $kpiKeys);
        $this->assertContains('l2_assigned_to_me', $kpiKeys);
        $this->assertContains('l2_ready_for_review', $kpiKeys);
        $this->assertContains('l2_unassigned', $kpiKeys);
        $this->assertContains('l2_auto_verified_awaiting', $kpiKeys);
        $this->assertContains('l2_overdue_qualifications', $kpiKeys);
        $this->assertNotContains('applications_total', $kpiKeys);
        $this->assertNotContains('pending_verification', $kpiKeys);
        $this->assertNotContains('verification_overdue', $kpiKeys);
        $this->assertNotContains('l2_level1_queue', $kpiKeys);
        $this->assertSame('level2_qualifications', $props['meta']['dashboard_scope']);

        $total = collect($props['kpis'])->firstWhere('key', 'l2_total_qualifications');
        $this->assertSame('Total qualifications', $total['label']);
        $this->assertSame(3, (int) $total['value']);

        $assigned = collect($props['kpis'])->firstWhere('key', 'l2_assigned_to_me');
        $this->assertSame(1, (int) $assigned['value']);

        $ready = collect($props['kpis'])->firstWhere('key', 'l2_ready_for_review');
        $this->assertSame('Ready for Level 2', $ready['label']);
        $this->assertSame(2, (int) $ready['value']);

        $pending = collect($props['kpis'])->firstWhere('key', 'l2_pending');
        $this->assertSame(2, (int) $pending['value']);

        $unassigned = collect($props['kpis'])->firstWhere('key', 'l2_unassigned');
        $this->assertGreaterThanOrEqual(1, (int) $unassigned['value']);

        $overdue = collect($props['kpis'])->firstWhere('key', 'l2_overdue_qualifications');
        $this->assertSame(3, (int) $overdue['value']);

        $chartKeys = collect($props['charts'])->pluck('key')->all();
        $this->assertContains('verification_l2_workflow_by_state', $chartKeys);
        $this->assertNotContains('applications_submitted_week', $chartKeys);

        $kpiKeysFinance = collect($props['kpis'])->pluck('key')->all();
        $this->assertNotContains('revenue_period', $kpiKeysFinance);
        $this->assertNotContains('invoices_issued', $kpiKeysFinance);
    }

    public function test_level_two_assigned_to_me_includes_returned_correction_owned_by_officer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 11:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $otherL2 = User::factory()->activated()->create(['applicant_type' => null]);
        $otherL2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-RETURN',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Resubmitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now()->subWeek(),
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-MINE',
            'level2_review_owner_id' => $l2->id,
            'send_back_by_user_id' => $l2->id,
            'send_back_reopen_level' => 'level2',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Returned Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '333333/33/3',
            'title_of_qualification' => 'Returned Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-OTHER',
            'level2_review_owner_id' => $otherL2->id,
            'awarding_institution_name' => 'Other Institution',
            'qualification_holder_name' => 'Other Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '444444/44/4',
            'title_of_qualification' => 'Other Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $props = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'));
        $assigned = collect($props['kpis'])->firstWhere('key', 'l2_assigned_to_me');
        $this->assertSame(1, (int) $assigned['value']);
        $this->assertSame('/admin/verification/assigned-to-me', $assigned['href']);
    }

    public function test_level_two_assigned_to_me_counts_auto_verified_lock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 12:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-AUTO',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-LOCK',
            'level2_review_locked_by' => $l2->id,
            'level2_review_locked_at' => now(),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Auto Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '555555/55/5',
            'title_of_qualification' => 'Auto Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $props = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'));
        $assigned = collect($props['kpis'])->firstWhere('key', 'l2_assigned_to_me');
        $this->assertSame(1, (int) $assigned['value']);

        $auto = collect($props['kpis'])->firstWhere('key', 'l2_auto_verified_awaiting');
        $this->assertSame(1, (int) $auto['value']);
    }

    public function test_super_admin_still_receives_application_dashboard_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 09:00:00', config('app.timezone')));

        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        $response = $this->actingAs($admin)->get('/admin/dashboard');
        $props = $this->inertiaProps($response);

        $kpiKeys = collect($props['kpis'])->pluck('key')->all();
        $this->assertContains('applications_total', $kpiKeys);
        $this->assertNotContains('l2_total_qualifications', $kpiKeys);
        $this->assertContains('l2_level1_queue', $kpiKeys);
        $this->assertSame('default', $props['meta']['dashboard_scope']);
    }

    public function test_level_two_processed_uses_audit_decision_timestamp(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-PROC',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        $qual = Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-PROC-Q',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Processed Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '666666/66/6',
            'title_of_qualification' => 'Processed Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        AuditLog::query()->create([
            'actor_user_id' => $l2->id,
            'actor_name_snapshot' => $l2->name,
            'event_type' => 'verification.qualification_approved',
            'module' => 'Verification',
            'entity_type' => Qualification::class,
            'entity_id' => $qual->id,
            'action_name' => 'qualification_approved',
            'message' => 'Approved',
            'created_at' => now()->subDay(),
        ]);

        $props = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'));
        $processed = collect($props['kpis'])->firstWhere('key', 'l2_processed');
        $this->assertSame(1, (int) $processed['value']);
    }

    public function test_level_two_pending_count_respects_date_range(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $recentApp = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-RECENT',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now()->subDays(3),
        ]);

        Qualification::query()->create([
            'application_id' => $recentApp->id,
            'verification_reference_number' => 'ZAQA-L2-RECENT-Q',
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Recent Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '777777/77/7',
            'title_of_qualification' => 'Recent Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $oldApp = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-OLD',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now()->subDays(20),
        ]);

        Qualification::query()->create([
            'application_id' => $oldApp->id,
            'verification_reference_number' => 'ZAQA-L2-OLD-Q',
            'awarding_institution_name' => 'Old Institution',
            'qualification_holder_name' => 'Old Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '888888/88/8',
            'title_of_qualification' => 'Old Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $props30 = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard?range=30'));
        $this->assertSame(2, (int) collect($props30['kpis'])->firstWhere('key', 'l2_pending')['value']);

        $props7 = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard?range=7'));
        $this->assertSame(1, (int) collect($props7['kpis'])->firstWhere('key', 'l2_pending')['value']);
    }

    public function test_level_two_unassigned_counts_only_level2_reviewable_without_owner_or_lock(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));

        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2-UNASSIGNED',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-OWNED',
            'level2_review_owner_id' => $l2->id,
            'awarding_institution_name' => 'Owned Institution',
            'qualification_holder_name' => 'Owned Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '101010/10/1',
            'title_of_qualification' => 'Owned Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-OPEN',
            'awarding_institution_name' => 'Open Institution',
            'qualification_holder_name' => 'Open Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '202020/20/2',
            'title_of_qualification' => 'Open Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2-AUTO-OPEN',
            'awarding_institution_name' => 'Auto Institution',
            'qualification_holder_name' => 'Auto Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '303030/30/3',
            'title_of_qualification' => 'Auto Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $props = $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'));
        $unassigned = collect($props['kpis'])->firstWhere('key', 'l2_unassigned');
        $this->assertSame(2, (int) $unassigned['value']);
    }
}
