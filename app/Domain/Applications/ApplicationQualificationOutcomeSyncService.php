<?php

namespace App\Domain\Applications;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class ApplicationQualificationOutcomeSyncService
{
    /**
     * @return list<string>
     */
    public function terminalQualificationStates(): array
    {
        return [
            VerificationState::ApprovedForCertificate->value,
            VerificationState::Rejected->value,
            VerificationState::CertificateIssued->value,
            VerificationState::Closed->value,
        ];
    }

    public function isTerminalQualification(Qualification $qualification): bool
    {
        $state = $qualification->verification_state?->value ?? (string) ($qualification->verification_state ?? '');

        return $state !== '' && in_array($state, $this->terminalQualificationStates(), true);
    }

    /**
     * @param  EloquentCollection<int, Qualification>|Collection<int, Qualification>  $qualifications
     */
    public function allQualificationsTerminal(EloquentCollection|Collection $qualifications): bool
    {
        if ($qualifications->isEmpty()) {
            return false;
        }

        return $qualifications->every(fn (Qualification $qualification) => $this->isTerminalQualification($qualification));
    }

    public function syncIfNeeded(Application $application, ?User $actor = null): Application
    {
        $application->refresh();
        $application->loadMissing('qualifications');

        if ($this->shouldSkipApplication($application)) {
            return $application;
        }

        if (! $this->allQualificationsTerminal($application->qualifications)) {
            return $application;
        }

        $targetStatus = $this->resolveApplicationStatus($application->qualifications);
        $targetVerificationState = $this->resolveVerificationState($application->qualifications, $targetStatus);

        if (
            $application->current_status === $targetStatus
            && $application->verification_state === $targetVerificationState
        ) {
            return $application;
        }

        return $this->applyResolvedOutcome($application, $targetStatus, $targetVerificationState, $actor);
    }

    private function shouldSkipApplication(Application $application): bool
    {
        $status = $application->current_status?->value ?? (string) $application->current_status;

        return in_array($status, [
            ApplicationStatus::Draft->value,
            ApplicationStatus::PendingPayment->value,
        ], true);
    }

    /**
     * @param  EloquentCollection<int, Qualification>|Collection<int, Qualification>  $qualifications
     */
    public function resolveApplicationStatus(EloquentCollection|Collection $qualifications): ApplicationStatus
    {
        $rejectedCount = $qualifications
            ->filter(fn (Qualification $q) => $q->verification_state === VerificationState::Rejected)
            ->count();

        if ($rejectedCount === $qualifications->count()) {
            return ApplicationStatus::Rejected;
        }

        if ($rejectedCount > 0) {
            return ApplicationStatus::Completed;
        }

        $allCertificatesIssued = $qualifications->every(
            fn (Qualification $q) => in_array($q->verification_state, [VerificationState::CertificateIssued, VerificationState::Closed], true)
        );

        if ($allCertificatesIssued) {
            return ApplicationStatus::Completed;
        }

        $allApprovedPath = $qualifications->every(
            fn (Qualification $q) => in_array($q->verification_state, [
                VerificationState::ApprovedForCertificate,
                VerificationState::CertificateIssued,
                VerificationState::Closed,
            ], true)
        );

        if ($allApprovedPath) {
            return ApplicationStatus::Approved;
        }

        return ApplicationStatus::Completed;
    }

    /**
     * @param  EloquentCollection<int, Qualification>|Collection<int, Qualification>  $qualifications
     */
    public function resolveVerificationState(EloquentCollection|Collection $qualifications, ApplicationStatus $status): VerificationState
    {
        return match ($status) {
            ApplicationStatus::Rejected => VerificationState::Rejected,
            ApplicationStatus::Approved => VerificationState::ApprovedForCertificate,
            ApplicationStatus::CertificateReady => VerificationState::CertificateIssued,
            ApplicationStatus::Completed => $qualifications->contains(
                fn (Qualification $q) => $q->verification_state === VerificationState::CertificateIssued
            ) ? VerificationState::CertificateIssued : VerificationState::Closed,
            default => VerificationState::Closed,
        };
    }

    private function applyResolvedOutcome(
        Application $application,
        ApplicationStatus $targetStatus,
        VerificationState $targetVerificationState,
        ?User $actor,
    ): Application {
        $fromStatus = $application->current_status;
        $now = now();

        $application->forceFill([
            'current_status' => $targetStatus,
            'verification_state' => $targetVerificationState,
        ]);

        if ($targetStatus === ApplicationStatus::Rejected) {
            $application->forceFill([
                'rejected_at' => $application->rejected_at ?? $now,
            ]);
        }

        if (in_array($targetStatus, [ApplicationStatus::Approved, ApplicationStatus::CertificateReady, ApplicationStatus::Completed], true)) {
            $application->forceFill([
                'approved_at' => $application->approved_at ?? $now,
            ]);
        }

        if ($targetStatus === ApplicationStatus::Completed) {
            $application->forceFill([
                'completed_at' => $application->completed_at ?? $now,
            ]);
        }

        $application->save();

        if ($fromStatus !== $targetStatus) {
            ApplicationStatusHistory::create([
                'application_id' => $application->id,
                'from_status' => $fromStatus?->value ?? null,
                'to_status' => $targetStatus->value,
                'changed_by_user_id' => $actor?->id,
                'comment' => 'Application status updated after all qualifications reached a final outcome.',
                'changed_at' => $now,
                'metadata' => [
                    'source' => 'qualification_outcome_sync',
                ],
            ]);
        }

        return $application->fresh();
    }
}
