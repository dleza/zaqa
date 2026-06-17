<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\AuditLog;
use App\Models\Qualification;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class Level2DashboardMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const L2_KPI_KEYS = [
        'l2_total_qualifications',
        'l2_processed',
        'l2_with_level1',
        'l2_awaiting_level1_assignment',
        'l2_with_level2',
        'l2_awaiting_level2_assignment',
        'l2_auto_verified_awaiting',
        'l2_assigned_to_me',
        'l2_overdue_local',
        'l2_overdue_foreign',
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

    private function makeLevel2Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 2');

        return $user;
    }

    private function makeSubmittedApplication(User $applicant, array $overrides = []): Application
    {
        return Application::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-L2M-'.Str::upper(Str::random(6)),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ], $overrides));
    }

    private function makeQualification(Application $app, array $overrides = []): Qualification
    {
        return Qualification::query()->create(array_merge([
            'application_id' => $app->id,
            'verification_reference_number' => 'ZAQA-L2M-'.Str::upper(Str::random(8)),
            'awarding_institution_name' => 'Test Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => Str::random(6).'/11/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::UnderLevel2Review,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ], $overrides));
    }

    private function dashboardProps(User $l2, string $query = ''): array
    {
        return $this->inertiaProps($this->actingAs($l2)->get('/admin/dashboard'.$query));
    }

    private function dashboardKpis(User $l2, string $query = ''): array
    {
        return $this->dashboardProps($l2, $query)['kpis'];
    }

    private function kpiValue(array $kpis, string $key): int
    {
        $card = collect($kpis)->firstWhere('key', $key);
        $this->assertNotNull($card, "Missing KPI card: {$key}");

        return (int) $card['value'];
    }

    public function test_level_two_dashboard_shows_all_required_cards_with_friendly_labels(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $this->makeQualification($this->makeSubmittedApplication($applicant));

        $kpis = $this->dashboardKpis($l2);
        $keys = collect($kpis)->pluck('key')->all();

        foreach (self::L2_KPI_KEYS as $expectedKey) {
            $this->assertContains($expectedKey, $keys);
        }

        $this->assertSame('With Level 1', collect($kpis)->firstWhere('key', 'l2_with_level1')['label']);
        $this->assertSame('Awaiting Level 1 assignment', collect($kpis)->firstWhere('key', 'l2_awaiting_level1_assignment')['label']);
        $this->assertSame('With Level 2', collect($kpis)->firstWhere('key', 'l2_with_level2')['label']);
        $this->assertSame('Awaiting Level 2 assignment', collect($kpis)->firstWhere('key', 'l2_awaiting_level2_assignment')['label']);
    }

    public function test_with_level1_counts_only_records_assigned_to_level1(): void
    {
        $l2 = $this->makeLevel2Officer();
        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => $level1->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $level1->id,
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2), 'l2_with_level1'));
    }

    public function test_with_level1_does_not_count_awaiting_assignment_records(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => null,
        ]);

        $this->assertSame(0, $this->kpiValue($this->dashboardKpis($l2), 'l2_with_level1'));
        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2), 'l2_awaiting_level1_assignment'));
    }

    public function test_awaiting_level1_assignment_counts_records_without_assigned_level1_officer(): void
    {
        $l2 = $this->makeLevel2Officer();
        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => null,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => $level1->id,
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2), 'l2_awaiting_level1_assignment'));
    }

    public function test_with_level2_counts_only_manual_records_with_owner(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'level2_review_owner_id' => $l2->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant));
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => $otherL2->id,
            'level2_review_locked_at' => now(),
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2), 'l2_with_level2'));
    }

    public function test_awaiting_level2_assignment_counts_manual_records_without_owner(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant));
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'level2_review_owner_id' => $l2->id,
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2), 'l2_awaiting_level2_assignment'));
    }

    public function test_auto_verified_records_are_excluded_from_awaiting_level2_assignment(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant));
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
        ]);

        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2), 'l2_awaiting_level2_assignment'));
        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2), 'l2_auto_verified_awaiting'));
    }

    public function test_assigned_to_me_counts_only_logged_in_level2_officer(): void
    {
        $l2 = $this->makeLevel2Officer();
        $otherL2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'level2_review_owner_id' => $l2->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'level2_review_owner_id' => $otherL2->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
            'level2_review_locked_by' => $l2->id,
            'level2_review_locked_at' => now(),
        ]);

        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2), 'l2_assigned_to_me'));
        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($otherL2), 'l2_assigned_to_me'));
    }

    public function test_auto_verified_awaiting_l2_counts_auto_verified_pending_level2(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::UnderLevel2Review,
        ]);

        $kpis = $this->dashboardKpis($l2);
        $this->assertSame(1, $this->kpiValue($kpis, 'l2_auto_verified_awaiting'));
        $this->assertStringContainsString('Subset', collect($kpis)->firstWhere('key', 'l2_auto_verified_awaiting')['hint']);
    }

    public function test_workflow_chart_uses_friendly_stage_labels(): void
    {
        $l2 = $this->makeLevel2Officer();
        $level1 = User::factory()->activated()->create(['applicant_type' => null]);
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::UnderLevel1Review,
            'assigned_verifier_id' => $level1->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'level2_review_owner_id' => $l2->id,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant));
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::AutoVerifiedPendingLevel2,
        ]);

        $chart = collect($this->dashboardProps($l2)['charts'])
            ->firstWhere('key', 'verification_l2_workflow_by_state');

        $this->assertNotNull($chart);
        $this->assertSame('Current qualifications by workflow stage', $chart['title']);
        $this->assertContains('Awaiting Level 1 assignment', $chart['labels']);
        $this->assertContains('With Level 1', $chart['labels']);
        $this->assertContains('Awaiting Level 2 assignment', $chart['labels']);
        $this->assertContains('With Level 2', $chart['labels']);
        $this->assertContains('Auto-verified awaiting L2', $chart['labels']);
    }

    public function test_local_and_foreign_overdue_are_mutually_exclusive(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'service_deadline_at' => now()->subDay(),
        ]), [
            'is_foreign_qualification' => false,
            'service_deadline_at' => now()->subDay(),
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'service_deadline_at' => now()->subDay(),
        ]), [
            'is_foreign_qualification' => true,
            'service_deadline_at' => now()->subDay(),
        ]);

        $kpis = $this->dashboardKpis($l2);
        $this->assertSame(1, $this->kpiValue($kpis, 'l2_overdue_local'));
        $this->assertSame(1, $this->kpiValue($kpis, 'l2_overdue_foreign'));
    }

    public function test_processed_respects_selected_date_range(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $app = $this->makeSubmittedApplication($applicant);

        $recent = $this->makeQualification($app, ['verification_reference_number' => 'ZAQA-L2M-RECENT']);
        $old = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_reference_number' => 'ZAQA-L2M-OLD',
        ]);

        $recentLog = AuditLog::query()->create([
            'actor_user_id' => $l2->id,
            'actor_name_snapshot' => $l2->name,
            'event_type' => 'verification.qualification_approved',
            'module' => 'Verification',
            'entity_type' => Qualification::class,
            'entity_id' => $recent->id,
            'action_name' => 'qualification_approved',
            'message' => 'Approved',
        ]);
        $recentLog->forceFill(['created_at' => now()->subDays(3)])->save();

        $oldLog = AuditLog::query()->create([
            'actor_user_id' => $l2->id,
            'actor_name_snapshot' => $l2->name,
            'event_type' => 'verification.qualification_rejected',
            'module' => 'Verification',
            'entity_type' => Qualification::class,
            'entity_id' => $old->id,
            'action_name' => 'qualification_rejected',
            'message' => 'Rejected',
        ]);
        $oldLog->forceFill(['created_at' => now()->subDays(20)])->save();

        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2, '?range=30'), 'l2_processed'));
        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2, '?range=7'), 'l2_processed'));
    }

    public function test_total_qualifications_includes_terminal_processed_states(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::UnderLevel2Review,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::ApprovedForCertificate,
        ]);
        $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'verification_state' => VerificationState::Rejected,
        ]);

        $this->assertSame(3, $this->kpiValue($this->dashboardKpis($l2, '?range=30'), 'l2_total_qualifications'));
        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2, '?range=30'), 'l2_awaiting_level2_assignment'));
    }

    public function test_total_qualifications_respects_selected_date_range(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'submitted_at' => now()->subDays(3),
        ]));
        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'submitted_at' => now()->subDays(20),
        ]));

        $this->assertSame(2, $this->kpiValue($this->dashboardKpis($l2, '?range=30'), 'l2_total_qualifications'));
        $this->assertSame(1, $this->kpiValue($this->dashboardKpis($l2, '?range=7'), 'l2_total_qualifications'));
    }

    public function test_queue_metrics_use_current_queue_subtitles_and_ignore_date_range(): void
    {
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $this->makeQualification($this->makeSubmittedApplication($applicant, [
            'submitted_at' => now()->subDays(40),
        ]), [
            'verification_state' => VerificationState::AwaitingAssignment,
        ]);

        $kpis30 = $this->dashboardKpis($l2, '?range=30');
        $kpis7 = $this->dashboardKpis($l2, '?range=7');

        $awaitingLevel1_30 = collect($kpis30)->firstWhere('key', 'l2_awaiting_level1_assignment');
        $awaitingLevel1_7 = collect($kpis7)->firstWhere('key', 'l2_awaiting_level1_assignment');

        $this->assertSame(1, (int) $awaitingLevel1_30['value']);
        $this->assertSame(1, (int) $awaitingLevel1_7['value']);
        $this->assertStringContainsString('waiting for Level 1 officer', $awaitingLevel1_30['hint']);
        $this->assertSame('current_queue', $awaitingLevel1_30['metric_scope'] ?? null);

        $total30 = collect($kpis30)->firstWhere('key', 'l2_total_qualifications');
        $total7 = collect($kpis7)->firstWhere('key', 'l2_total_qualifications');
        $this->assertStringContainsString('open + processed intake', $total30['hint']);
        $this->assertStringContainsString('Last 7 days', $total7['hint']);
        $this->assertSame('period', $total30['metric_scope'] ?? null);
    }
}
