<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class Level1DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const L1_KPI_KEYS = [
        'l1_assigned_to_me',
        'l1_in_review',
        'l1_completed',
        'l1_returned_by_level2',
        'l1_my_overdue',
        'l1_awaiting_applicant_correction',
        'l1_sent_back_to_applicant',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Carbon::setTestNow(Carbon::parse('2026-06-16 10:00:00', config('app.timezone')));
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

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
    }

    private function makeSubmittedApplication(User $applicant, array $overrides = []): Application
    {
        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L1M-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ], $overrides));
    }

    private function makeQualification(Application $app, array $overrides = []): Qualification
    {
        return Qualification::query()->create(array_merge([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L1M-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ], $overrides));
    }

    private function dashboardProps(User $l1, string $query = ''): array
    {
        return $this->inertiaProps($this->actingAs($l1)->get('/admin/dashboard'.$query));
    }

    private function kpiValue(array $kpis, string $key): int
    {
        $card = collect($kpis)->firstWhere('key', $key);
        $this->assertNotNull($card, "Missing KPI card: {$key}");

        return (int) $card['value'];
    }

    public function test_level_one_dashboard_shows_personal_cards_only(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
        ]);

        $props = $this->dashboardProps($l1);
        $keys = collect($props['kpis'])->pluck('key')->all();

        foreach (self::L1_KPI_KEYS as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }

        $this->assertNotContains('applications_total', $keys);
        $this->assertNotContains('pending_verification', $keys);
        $this->assertNotContains('verification_overdue', $keys);
        $this->assertNotContains('l2_total_qualifications', $keys);
        $this->assertNotContains('revenue_period', $keys);
        $this->assertNotContains('certificates_path', $keys);
        $this->assertSame('level1_assigned', $props['meta']['dashboard_scope']);
        $this->assertNotNull($props['meta']['l1_metrics_explainer']);
    }

    public function test_assigned_to_me_counts_only_current_user_level1_assignments(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $otherL1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_assigned_to_me'));
        $this->assertSame(1, $this->kpiValue($this->dashboardProps($otherL1)['kpis'], 'l1_assigned_to_me'));
    }

    public function test_in_review_counts_only_under_level1_review_for_current_user(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_in_review'));
    }

    public function test_assigned_to_me_still_includes_assigned_before_review_is_started(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::AssignedToLevel1,
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_assigned_to_me'));
        $this->assertSame(0, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_in_review'));
    }

    public function test_completed_counts_only_current_user_completions_in_selected_range(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'level1_review_completed_by_user_id' => $l1->id,
            'reviewed_at' => now()->subDays(3),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $otherL1->id,
            'level1_review_completed_by_user_id' => $otherL1->id,
            'reviewed_at' => now()->subDays(3),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'level1_review_completed_by_user_id' => $l1->id,
            'reviewed_at' => now()->subDays(20),
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardProps($l1, '?range=30')['kpis'], 'l1_completed'));
        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1, '?range=7')['kpis'], 'l1_completed'));
    }

    public function test_returned_by_level2_counts_only_records_returned_to_current_officer(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $l2 = User::factory()->activated()->create(['applicant_type' => null]);
        $l2->assignRole('Verification Officer Level 2');
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'returned_to_level1_to_user_id' => $l1->id,
            'returned_to_level1_by_user_id' => $l2->id,
            'returned_to_level1_at' => now()->subDay(),
            'level1_correction_cycle' => 1,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $otherL1->id,
            'verification_state' => VerificationState::UnderLevel1Review,
            'returned_to_level1_to_user_id' => $otherL1->id,
            'returned_to_level1_by_user_id' => $l2->id,
            'returned_to_level1_at' => now()->subDay(),
            'level1_correction_cycle' => 1,
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_returned_by_level2'));
    }

    public function test_my_overdue_counts_only_overdue_records_assigned_to_current_user(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'service_deadline_at' => now()->subDay(),
        ]), [
            'assigned_verifier_id' => $l1->id,
            'service_deadline_at' => now()->subDay(),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'service_deadline_at' => now()->subDay(),
        ]), [
            'assigned_verifier_id' => $otherL1->id,
            'service_deadline_at' => now()->subDay(),
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_my_overdue'));
    }

    public function test_awaiting_applicant_correction_counts_only_records_sent_back_by_current_user(): void
    {
        $l1 = $this->makeLevel1Officer();
        $otherL1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => null,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $l1->id,
            'returned_to_applicant_at' => now()->subDay(),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => null,
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $otherL1->id,
            'returned_to_applicant_at' => now()->subDay(),
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1)['kpis'], 'l1_awaiting_applicant_correction'));
    }

    public function test_sent_back_to_applicant_respects_selected_date_range(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::ReturnedToApplicant,
            'send_back_by_user_id' => $l1->id,
            'returned_to_applicant_at' => now()->subDays(3),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::ApprovedForCertificate,
            'send_back_by_user_id' => $l1->id,
            'returned_to_applicant_at' => now()->subDays(20),
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardProps($l1, '?range=30')['kpis'], 'l1_sent_back_to_applicant'));
        $this->assertSame(1, $this->kpiValue($this->dashboardProps($l1, '?range=7')['kpis'], 'l1_sent_back_to_applicant'));
    }

    public function test_level_one_charts_are_personal_not_global(): void
    {
        $l1 = $this->makeLevel1Officer();
        $props = $this->dashboardProps($l1);
        $chartKeys = collect($props['charts'])->pluck('key')->all();

        $this->assertContains('verification_l1_workload_by_status', $chartKeys);
        $this->assertContains('verification_l1_completed_week', $chartKeys);
        $this->assertNotContains('verification_pool_submissions_week', $chartKeys);
        $this->assertNotContains('applications_submitted_week', $chartKeys);
        $this->assertNotContains('finance_revenue_week', $chartKeys);
    }

    public function test_level_one_quick_actions_exclude_global_admin_links(): void
    {
        $l1 = $this->makeLevel1Officer();
        $actions = collect($this->dashboardProps($l1)['quick_actions']);

        $this->assertTrue($actions->contains(fn ($a) => $a['href'] === '/admin/verification/assigned-to-me'));
        $this->assertFalse($actions->contains(fn ($a) => $a['href'] === '/admin/verification/pool'));
        $this->assertFalse($actions->contains(fn ($a) => $a['href'] === '/admin/finance/payment-proofs'));
        $this->assertFalse($actions->contains(fn ($a) => $a['href'] === '/admin/users'));
    }
}
