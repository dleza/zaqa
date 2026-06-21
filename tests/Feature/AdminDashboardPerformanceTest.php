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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminDashboardPerformanceTest extends TestCase
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

    private function inertiaProps($response): array
    {
        $page = $response->viewData('page');

        return json_decode(json_encode($page), true)['props'];
    }

    public function test_level1_dashboard_counts_are_scoped_to_current_user(): void
    {
        $l1 = User::factory()->activated()->create(['applicant_type' => null]);
        $l1->assignRole('Verification Officer Level 1');
        $other = User::factory()->activated()->create(['applicant_type' => null]);
        $other->assignRole('Verification Officer Level 1');

        $app = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => '2026-000301',
            'applicant_user_id' => User::factory()->activated()->create(['applicant_type' => 'individual'])->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'verification_state' => VerificationState::AssignedToLevel1,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now(),
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => '2026-000301-01',
            'awarding_institution_name' => 'Institution',
            'qualification_holder_name' => 'Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '123456/78/9',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => $l1->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        Qualification::query()->create([
            'application_id' => $app->id,
            'verification_reference_number' => '2026-000301-02',
            'awarding_institution_name' => 'Institution',
            'qualification_holder_name' => 'Other Holder',
            'country_name_other' => 'Zambia',
            'nrc_passport_number' => '987654/32/1',
            'title_of_qualification' => 'Diploma',
            'award_date' => now()->subYear()->toDateString(),
            'qualification_type' => 'L6',
            'verification_state' => VerificationState::AssignedToLevel1,
            'assigned_verifier_id' => $other->id,
            'is_foreign_qualification' => false,
            'transcript_required' => false,
        ]);

        $response = $this->actingAs($l1)->get('/admin/dashboard?range=last30');
        $response->assertOk();

        $assigned = collect($this->inertiaProps($response)['kpis'] ?? [])
            ->firstWhere('key', 'l1_assigned_to_me');

        $this->assertNotNull($assigned);
        $this->assertSame(1, (int) ($assigned['value'] ?? 0));
    }

    public function test_super_admin_dashboard_responds_without_loading_unbounded_collections(): void
    {
        $admin = User::factory()->activated()->create(['applicant_type' => null]);
        $admin->assignRole('Super Admin');

        for ($i = 0; $i < 20; $i++) {
            $app = Application::query()->create([
                'uuid' => (string) Str::uuid(),
                'application_number' => sprintf('2026-%06d', 500 + $i),
                'applicant_user_id' => User::factory()->activated()->create(['applicant_type' => 'individual'])->id,
                'applicant_type' => 'individual',
                'service_type' => 'verification',
                'qualification_category' => 'diploma',
                'current_status' => ApplicationStatus::Submitted,
                'verification_state' => VerificationState::AwaitingAssignment,
                'is_foreign' => false,
                'metadata' => [],
                'submitted_at' => now()->subDays($i % 10),
            ]);

            Qualification::query()->create([
                'application_id' => $app->id,
                'verification_reference_number' => sprintf('2026-%06d-01', 500 + $i),
                'awarding_institution_name' => 'Institution',
                'qualification_holder_name' => 'Holder '.$i,
                'country_name_other' => 'Zambia',
                'nrc_passport_number' => '123456/78/9',
                'title_of_qualification' => 'Diploma',
                'award_date' => now()->subYear()->toDateString(),
                'qualification_type' => 'L6',
                'verification_state' => VerificationState::AwaitingAssignment,
                'is_foreign_qualification' => false,
                'transcript_required' => false,
            ]);
        }

        DB::enableQueryLog();
        $response = $this->actingAs($admin)->get('/admin/dashboard?range=7');
        $response->assertOk();
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(120, $queryCount);

        $submittedKpi = collect($this->inertiaProps($response)['kpis'] ?? [])
            ->first(fn (array $kpi) => ($kpi['key'] ?? '') === 'applications_submitted_period');

        if ($submittedKpi !== null) {
            $this->assertSame('period', $submittedKpi['metric_scope'] ?? null);
        }
    }
}
