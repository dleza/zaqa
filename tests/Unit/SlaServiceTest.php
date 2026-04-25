<?php

namespace Tests\Unit;

use App\Domain\Verification\SlaService;
use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SlaServiceTest extends TestCase
{
    use RefreshDatabase;

    private function baseApplication(): Application
    {
        $applicant = User::factory()->activated()->create(['applicant_type' => 'individual']);

        return Application::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'application_number' => 'ZAQA-SLA-'.rand(1000, 9999),
            'applicant_user_id' => $applicant->id,
            'applicant_type' => 'individual',
            'service_type' => 'verification',
            'qualification_category' => 'diploma',
            'current_status' => ApplicationStatus::Submitted,
            'is_foreign' => false,
            'metadata' => [],
            'submitted_at' => now()->subDays(20),
            'service_deadline_at' => now()->subDay(),
        ]);
    }

    public function test_is_overdue_when_deadline_passed_and_work_open(): void
    {
        $app = $this->baseApplication();
        $app->forceFill([
            'verification_state' => VerificationState::UnderLevel1Review,
        ])->save();

        $svc = new SlaService;

        $this->assertTrue($svc->isOverdue($app));
    }

    public function test_not_overdue_when_completed_at_set(): void
    {
        $app = $this->baseApplication();
        $app->forceFill([
            'verification_state' => VerificationState::CertificateIssued,
            'current_status' => ApplicationStatus::CertificateReady,
            'completed_at' => now(),
        ])->save();

        $svc = new SlaService;

        $this->assertFalse($svc->isOverdue($app));
    }

    public function test_not_overdue_when_certificate_issued_even_without_completed_at(): void
    {
        $app = $this->baseApplication();
        $app->forceFill([
            'verification_state' => VerificationState::CertificateIssued,
            'current_status' => ApplicationStatus::CertificateReady,
            'completed_at' => null,
        ])->save();

        $svc = new SlaService;

        $this->assertFalse($svc->isOverdue($app));
    }

    public function test_not_overdue_when_status_certificate_ready(): void
    {
        $app = $this->baseApplication();
        $app->forceFill([
            'current_status' => ApplicationStatus::CertificateReady,
            'verification_state' => VerificationState::CertificateIssued,
        ])->save();

        $svc = new SlaService;

        $this->assertFalse($svc->isOverdue($app));
    }

    public function test_has_closed_service_sla_is_true_for_rejected(): void
    {
        $app = $this->baseApplication();
        $app->forceFill([
            'current_status' => ApplicationStatus::Rejected,
            'verification_state' => VerificationState::Rejected,
        ])->save();

        $svc = new SlaService;

        $this->assertTrue($svc->hasClosedServiceSla($app));
        $this->assertFalse($svc->isOverdue($app));
    }
}
