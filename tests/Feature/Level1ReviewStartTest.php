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

class Level1ReviewStartTest extends TestCase
{
    use RefreshDatabase;

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

    private function makeLevel1Officer(): User
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->assignRole('Verification Officer Level 1');

        return $user;
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
            'application_number' => 'ZAQA-L1S-'.Str::upper(Str::random(6)),
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
            'verification_reference_number' => 'ZAQA-L1S-'.Str::upper(Str::random(8)),
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

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    private function kpiValue(array $kpis, string $key): int
    {
        $card = collect($kpis)->firstWhere('key', $key);
        $this->assertNotNull($card, "Missing KPI card: {$key}");

        return (int) $card['value'];
    }

    public function test_show_transitions_assigned_qualification_to_under_level1_review(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $qualification = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($l1)
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertOk();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);

        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'verification.level1_review_started',
            'entity_type' => Qualification::class,
            'entity_id' => $qualification->id,
            'actor_user_id' => $l1->id,
        ]);
    }

    public function test_show_is_idempotent_when_already_under_level1_review(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $qualification = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::UnderLevel1Review,
        ]);

        $this->actingAs($l1)
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertOk();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);

        $this->assertSame(
            0,
            AuditLog::query()
                ->where('event_type', 'verification.level1_review_started')
                ->where('entity_id', $qualification->id)
                ->count()
        );
    }

    public function test_level2_viewing_another_officers_assignment_does_not_start_review(): void
    {
        $l1 = $this->makeLevel1Officer();
        $l2 = $this->makeLevel2Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $qualification = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($l2)
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertOk();

        $qualification->refresh();
        $this->assertSame(VerificationState::AssignedToLevel1, $qualification->verification_state);
    }

    public function test_edit_also_starts_review_for_assigned_officer(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $qualification = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'assigned_at' => now(),
        ]);

        $this->actingAs($l1)
            ->get("/admin/verification/qualifications/{$qualification->id}/edit")
            ->assertOk();

        $qualification->refresh();
        $this->assertSame(VerificationState::UnderLevel1Review, $qualification->verification_state);
    }

    public function test_opening_assigned_qualification_updates_in_review_dashboard_metric(): void
    {
        $l1 = $this->makeLevel1Officer();
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);
        $qualification = $this->makeQualification($this->makeSubmittedApplication($applicant), [
            'assigned_verifier_id' => $l1->id,
            'assigned_at' => now(),
        ]);

        $dashboardBefore = $this->inertiaProps($this->actingAs($l1)->get('/admin/dashboard'));
        $this->assertSame(0, $this->kpiValue($dashboardBefore['kpis'], 'l1_in_review'));

        $this->actingAs($l1)
            ->get("/admin/verification/qualifications/{$qualification->id}")
            ->assertOk();

        $dashboardAfter = $this->inertiaProps($this->actingAs($l1)->get('/admin/dashboard'));
        $this->assertSame(1, $this->kpiValue($dashboardAfter['kpis'], 'l1_in_review'));
    }
}
