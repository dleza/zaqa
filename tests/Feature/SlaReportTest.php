<?php

namespace Tests\Feature;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SlaReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_sla_report_requires_permission(): void
    {
        $user = User::factory()->activated()->create(['applicant_type' => null]);
        $user->givePermissionTo('dashboard.view');
        $this->actingAs($user);

        $this->get('/admin/reports/sla')->assertForbidden();
    }

    public function test_sla_report_aggregates_on_time_vs_late_and_groups_by_level2_actor(): void
    {
        $viewer = User::factory()->activated()->create(['applicant_type' => null]);
        $viewer->assignRole('Verification Officer Level 2');
        $this->actingAs($viewer);

        $level2 = User::factory()->activated()->create(['applicant_type' => null]);

        $now = now();

        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        $onTime = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-ONTIME',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Approved,
            'verification_state' => VerificationState::ApprovedForCertificate,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => $now->copy()->subDays(5),
            'service_deadline_at' => $now->copy()->subDays(1),
            'approved_at' => $now->copy()->subDays(2),
        ]);

        $late = Application::query()->create([
            'uuid' => (string) Str::uuid(),
            'application_number' => 'ZAQA-VER-LATE',
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Rejected,
            'verification_state' => VerificationState::Rejected,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => $now->copy()->subDays(10),
            'service_deadline_at' => $now->copy()->subDays(6),
            'rejected_at' => $now->copy()->subDays(1),
        ]);

        ApplicationStatusHistory::create([
            'application_id' => $onTime->id,
            'from_status' => ApplicationStatus::InProgress->value,
            'to_status' => ApplicationStatus::Approved->value,
            'changed_by_user_id' => $level2->id,
            'comment' => 'Approved',
            'changed_at' => $onTime->approved_at,
            'metadata' => [],
        ]);

        ApplicationStatusHistory::create([
            'application_id' => $late->id,
            'from_status' => ApplicationStatus::InProgress->value,
            'to_status' => ApplicationStatus::Rejected->value,
            'changed_by_user_id' => $level2->id,
            'comment' => 'Rejected',
            'changed_at' => $late->rejected_at,
            'metadata' => [],
        ]);

        $res = $this->get('/admin/reports/sla?range=last30');
        $res->assertOk();
        $res->assertInertia(fn ($page) => $page
            ->component('Admin/Reports/Sla', shouldExist: false)
            ->where('overall.decisions_total', 2)
            ->where('overall.on_time', 1)
            ->where('overall.late', 1)
            ->has('level2', 1)
            ->where('level2.0.decisions_total', 2)
            ->where('level2.0.approved', 1)
            ->where('level2.0.rejected', 1)
        );
    }
}

