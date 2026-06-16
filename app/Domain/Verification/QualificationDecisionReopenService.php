<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\QualificationCertificate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationDecisionReopenService
{
    public const INTENDED_RECONSIDER_APPROVAL = 'reconsider_for_approval';

    public const INTENDED_RECONSIDER_REJECTION = 'reconsider_for_rejection';

    public const INTENDED_FURTHER_REVIEW = 'reopen_for_further_review';

    /**
     * @return list<string>
     */
    public static function intendedActions(): array
    {
        return [
            self::INTENDED_RECONSIDER_APPROVAL,
            self::INTENDED_RECONSIDER_REJECTION,
            self::INTENDED_FURTHER_REVIEW,
        ];
    }

    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationCertificateService $certificates,
        private readonly QualificationLevel2ReviewLockService $locks,
    ) {}

    public function reopen(
        Qualification $qualification,
        User $actor,
        string $reason,
        string $intendedAction,
    ): Qualification {
        if (! $actor->can('verification.decision.reopen')) {
            throw ValidationException::withMessages([
                'authorization' => 'You do not have permission to reopen Level 2 decisions.',
            ]);
        }

        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Provide a reason for reopening the Level 2 decision.',
            ]);
        }

        if (! in_array($intendedAction, self::intendedActions(), true)) {
            throw ValidationException::withMessages([
                'intended_action' => 'Select a valid intended review action.',
            ]);
        }

        return DB::transaction(function () use ($qualification, $actor, $reason, $intendedAction) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $application = $qualification->application;
            if (! $application instanceof Application) {
                throw ValidationException::withMessages([
                    'qualification' => 'Application not found for this qualification.',
                ]);
            }

            if ($this->certificates->findActiveCertificate($qualification)) {
                throw ValidationException::withMessages([
                    'qualification' => 'An active certificate must be revoked before reopening the Level 2 decision.',
                ]);
            }

            $revokedCertificates = QualificationCertificate::query()
                ->where('qualification_id', $qualification->id)
                ->where('status', QualificationCertificate::STATUS_REVOKED)
                ->orderByDesc('id')
                ->get();

            if ($revokedCertificates->isEmpty()) {
                throw ValidationException::withMessages([
                    'qualification' => 'Reopen is only available after a certificate has been revoked.',
                ]);
            }

            $previousState = $qualification->verification_state;
            if (! $previousState instanceof VerificationState
                || ! in_array($previousState, [
                    VerificationState::CertificateIssued,
                    VerificationState::ApprovedForCertificate,
                    VerificationState::Rejected,
                ], true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'This qualification is not in a final decision state that can be reopened.',
                ]);
            }

            $before = $qualification->only([
                'verification_state',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::UnderLevel2Review,
                'level2_review_owner_id' => $actor->id,
                'level2_review_locked_by' => null,
                'level2_review_locked_at' => null,
            ])->save();

            $this->locks->clearLock($qualification);

            ApplicationComment::create([
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'author_user_id' => $actor->id,
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => 'Level 2 decision reopened after certificate revocation. Intended action: '
                    .str_replace('_', ' ', $intendedAction).'. Reason: '.$reason,
            ]);

            $revokedCertificateIds = $revokedCertificates->pluck('id')->all();
            $previousDecisionSummary = $this->summarizePreviousDecision($previousState, $revokedCertificates->first());

            $this->lifecycle->event(
                application: $application,
                eventType: 'verification',
                eventCodeBase: 'verification.level2_decision_reopened.q'.$qualification->id,
                stage: LifecycleStage::Review,
                title: 'Level 2 decision reopened',
                description: 'Level 2 decision reopened after certificate revocation.',
                visibility: LifecycleVisibility::Internal,
                actor: $actor,
                comment: $reason,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'intended_action' => $intendedAction,
                    'previous_qualification_state' => $previousState->value,
                    'revoked_certificate_ids' => $revokedCertificateIds,
                    'previous_decision_summary' => $previousDecisionSummary,
                ],
                occurredAt: now(),
            );

            $after = $qualification->only([
                'verification_state',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $this->audit->record(
                eventType: 'verification.level2_decision_reopened_after_certificate_revocation',
                module: 'Verification',
                actionName: 'level2_decision_reopened_after_certificate_revocation',
                message: 'Level 2 decision reopened after certificate revocation.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'reason' => $reason,
                    'intended_action' => $intendedAction,
                    'previous_qualification_state' => $previousState->value,
                    'new_qualification_state' => VerificationState::UnderLevel2Review->value,
                    'revoked_certificate_ids' => $revokedCertificateIds,
                    'previous_decision_summary' => $previousDecisionSummary,
                ],
                actor: $actor,
            );

            return $qualification;
        });
    }

    public function canReopen(Qualification $qualification): bool
    {
        if ($this->certificates->findActiveCertificate($qualification)) {
            return false;
        }

        $hasRevoked = QualificationCertificate::query()
            ->where('qualification_id', $qualification->id)
            ->where('status', QualificationCertificate::STATUS_REVOKED)
            ->exists();

        if (! $hasRevoked) {
            return false;
        }

        $state = $qualification->verification_state;

        return $state instanceof VerificationState
            && in_array($state, [
                VerificationState::CertificateIssued,
                VerificationState::ApprovedForCertificate,
                VerificationState::Rejected,
            ], true);
    }

    private function summarizePreviousDecision(VerificationState $state, ?QualificationCertificate $latestRevoked): string
    {
        $stateLabel = match ($state) {
            VerificationState::Rejected => 'rejected',
            VerificationState::ApprovedForCertificate => 'approved for certificate',
            VerificationState::CertificateIssued => 'certificate issued',
            default => $state->value,
        };

        if (! $latestRevoked instanceof QualificationCertificate) {
            return $stateLabel;
        }

        $typeLabel = $latestRevoked->isRejectionCertificate() ? 'rejection notice' : 'verification certificate';

        return $stateLabel.' (revoked '.$typeLabel.' '.$latestRevoked->certificate_number.')';
    }
}
