<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\BillingCategory;
use App\Models\Qualification;
use Illuminate\Support\Carbon;

class QualificationSlaService
{
    public const CLOSED_QUALIFICATION_STATES = [
        VerificationState::ReturnedToApplicant->value,
        VerificationState::ApprovedForCertificate->value,
        VerificationState::Rejected->value,
        VerificationState::CertificateIssued->value,
        VerificationState::Closed->value,
    ];

    public const CLOSED_APPLICATION_STATUSES = [
        ApplicationStatus::Rejected->value,
        ApplicationStatus::CertificateReady->value,
        ApplicationStatus::Completed->value,
    ];

    public function resolveProcessingDays(Qualification $qualification): int
    {
        $qualification->loadMissing('qualificationTypeMaster.billingCategory');

        $category = $qualification->qualificationTypeMaster?->billingCategory;
        if ((bool) $qualification->is_foreign_qualification) {
            $category = BillingCategory::query()
                ->where('code', BillingCategory::CODE_FOREIGN_QUALIFICATIONS)
                ->where('is_active', true)
                ->first() ?? $category;
        }

        $days = (bool) $qualification->is_foreign_qualification
            ? $category?->foreign_processing_days
            : $category?->local_processing_days;

        if ($days !== null) {
            return max(0, (int) $days);
        }

        return (bool) $qualification->is_foreign_qualification ? 60 : 14;
    }

    public function deadlineFor(Qualification $qualification, Carbon $startedAt): Carbon
    {
        return $startedAt->copy()->addDays($this->resolveProcessingDays($qualification));
    }

    public function applyQualificationSla(Qualification $qualification, Carbon $startedAt): Qualification
    {
        $qualification->forceFill([
            'service_started_at' => $startedAt,
            'service_deadline_at' => $this->deadlineFor($qualification, $startedAt),
        ])->save();

        return $qualification->fresh();
    }

    public function applyApplicationSla(Application $application, Carbon $startedAt, bool $forceReset = false): void
    {
        $application->loadMissing('qualifications.qualificationTypeMaster.billingCategory');

        foreach ($application->qualifications as $qualification) {
            if (! $forceReset && $qualification->service_started_at && $qualification->service_deadline_at) {
                continue;
            }

            $this->applyQualificationSla($qualification, $startedAt);
        }

        $this->syncApplicationAggregateDeadline($application);
    }

    public function syncApplicationAggregateDeadline(Application $application): ?Carbon
    {
        $application->load('qualifications');

        $maxDeadline = $application->qualifications
            ->map(fn (Qualification $qualification) => $qualification->service_deadline_at)
            ->filter()
            ->sort()
            ->last();

        $application->forceFill([
            'service_deadline_at' => $maxDeadline,
        ])->save();

        return $maxDeadline;
    }

    public function hasClosedServiceSla(Qualification $qualification): bool
    {
        $vs = $qualification->verification_state;
        if ($vs instanceof VerificationState) {
            if (in_array($vs->value, self::CLOSED_QUALIFICATION_STATES, true)) {
                return true;
            }
        } elseif (is_string($vs) && $vs !== '') {
            if (in_array($vs, self::CLOSED_QUALIFICATION_STATES, true)) {
                return true;
            }
        }

        $application = $qualification->relationLoaded('application')
            ? $qualification->application
            : $qualification->application()->first();

        $cs = $application?->current_status;
        if ($cs instanceof ApplicationStatus) {
            return in_array($cs->value, self::CLOSED_APPLICATION_STATUSES, true);
        }

        return is_string($cs) && in_array($cs, self::CLOSED_APPLICATION_STATUSES, true);
    }
}
