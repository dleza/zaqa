<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use Illuminate\Support\Carbon;

class SlaService
{
    public function isOverdue(Application $application, ?Carbon $at = null): bool
    {
        $at ??= now();
        if (! $application->service_deadline_at) {
            return false;
        }

        // Overdue only for open work.
        if ($application->completed_at) {
            return false;
        }

        if ($this->hasClosedServiceSla($application)) {
            return false;
        }

        return $application->service_deadline_at->lt($at);
    }

    /**
     * True when the service SLA clock should no longer run (terminal outcomes),
     * even if {@see Application::$completed_at} was not backfilled.
     */
    public function hasClosedServiceSla(Application $application): bool
    {
        $vs = $application->verification_state;
        if ($vs instanceof VerificationState) {
            if (in_array($vs, [
                VerificationState::CertificateIssued,
                VerificationState::Closed,
                VerificationState::Rejected,
            ], true)) {
                return true;
            }
        } elseif (is_string($vs) && $vs !== '') {
            if (in_array($vs, ['certificate_issued', 'closed', 'rejected'], true)) {
                return true;
            }
        }

        $cs = $application->current_status;
        if ($cs instanceof ApplicationStatus) {
            if (in_array($cs, [
                ApplicationStatus::Rejected,
                ApplicationStatus::CertificateReady,
                ApplicationStatus::Completed,
            ], true)) {
                return true;
            }
        } elseif (is_string($cs) && $cs !== '') {
            if (in_array($cs, ['rejected', 'certificate_ready', 'completed'], true)) {
                return true;
            }
        }

        return false;
    }
}

