<?php

namespace App\Domain\Verification;

use App\Enums\ApplicationStatus;
use App\Enums\VerificationState;
use Illuminate\Database\Eloquent\Builder;

class VerificationAssignmentQueueScopes
{
    /**
     * Qualifications on submitted applications in the active verification pool.
     *
     * @param  Builder<\App\Models\Qualification>  $query
     */
    public static function applyActiveSubmittedApplicationScope(Builder $query): void
    {
        $query->whereHas('application', function (Builder $application) {
            $application->whereIn('current_status', [
                ApplicationStatus::Submitted,
                ApplicationStatus::Resubmitted,
                ApplicationStatus::InProgress,
                ApplicationStatus::SentBack,
            ])->whereNotNull('submitted_at');
        });
    }

    /**
     * Qualifications waiting for Level 1 officer assignment.
     *
     * @param  Builder<\App\Models\Qualification>  $query
     */
    public static function applyAwaitingLevel1AssignmentScope(Builder $query): void
    {
        self::applyActiveSubmittedApplicationScope($query);

        $query->where(function (Builder $outer) {
            $outer->where('qualifications.verification_state', VerificationState::AwaitingAssignment->value)
                ->orWhere(function (Builder $assigned) {
                    $assigned->whereIn('qualifications.verification_state', [
                        VerificationState::AssignedToLevel1->value,
                        VerificationState::UnderLevel1Review->value,
                    ])->whereNull('qualifications.assigned_verifier_id');
                });
        });
    }

    /**
     * Qualifications waiting for Level 2 assignment (manual owner or unlocked auto-verified).
     *
     * @param  Builder<\App\Models\Qualification>  $query
     */
    public static function applyAwaitingLevel2AssignmentScope(Builder $query): void
    {
        self::applyActiveSubmittedApplicationScope($query);

        $locks = app(QualificationLevel2ReviewLockService::class);
        $threshold = now()->subMinutes($locks->ttlMinutes());

        $query->where(function (Builder $outer) use ($threshold) {
            $outer->where(function (Builder $manual) {
                $manual->where('qualifications.verification_state', VerificationState::UnderLevel2Review->value)
                    ->whereNull('qualifications.level2_review_owner_id');
            })->orWhere(function (Builder $auto) use ($threshold) {
                $auto->where('qualifications.verification_state', VerificationState::AutoVerifiedPendingLevel2->value)
                    ->where(function (Builder $unlocked) use ($threshold) {
                        $unlocked->whereNull('qualifications.level2_review_locked_by')
                            ->orWhereNull('qualifications.level2_review_locked_at')
                            ->orWhere('qualifications.level2_review_locked_at', '<', $threshold);
                    });
            });
        });
    }
}
