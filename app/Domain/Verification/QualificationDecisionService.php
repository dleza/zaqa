<?php

namespace App\Domain\Verification;

use App\Domain\Applications\ApplicationOutcomeNotificationDispatcher;
use App\Domain\Audit\AuditLogService;
use App\Domain\Certificates\QualificationCertificateService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationDecisionService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationCertificateService $certificates,
        private readonly QualificationLevel2ReviewLockService $locks,
        private readonly ApplicationOutcomeNotificationDispatcher $outcomeNotifications,
    ) {}

    public function approve(Qualification $qualification, User $actor, ?string $comment = null, bool $issueCertificate = false): Qualification
    {
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        return DB::transaction(function () use ($qualification, $actor, $comment, $issueCertificate) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $application = $qualification->application;
            if (! $application instanceof Application) {
                throw ValidationException::withMessages([
                    'qualification' => 'Application not found for this qualification.',
                ]);
            }

            $vs = $qualification->verification_state;
            if (! in_array($vs, [VerificationState::UnderLevel2Review, VerificationState::AutoVerifiedPendingLevel2], true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'This qualification is not awaiting a Level 2 decision.',
                ]);
            }

            if ($vs === VerificationState::AutoVerifiedPendingLevel2) {
                $this->locks->assertActorHoldsLockOrIsSuperAdmin($qualification, $actor);
            }

            $before = $qualification->only([
                'verification_state',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::ApprovedForCertificate,
                'level2_review_owner_id' => null,
            ])->save();

            if ($vs === VerificationState::AutoVerifiedPendingLevel2) {
                $this->locks->clearLock($qualification);
            }

            if ($comment) {
                ApplicationComment::create([
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                    'author_user_id' => $actor->id,
                    'type' => 'decision_reason',
                    'visibility' => 'internal',
                    'body' => $comment,
                ]);
            }

            $this->lifecycle->event(
                application: $application,
                eventType: 'decision',
                eventCodeBase: 'decision.qualification_approved.q'.$qualification->id,
                stage: LifecycleStage::Decision,
                title: 'Qualification approved',
                description: 'ZAQA approved one qualification item for certificate issuance.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: null,
                metadata: [
                    'qualification_id' => $qualification->id,
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
                eventType: 'verification.qualification_approved',
                module: 'Verification',
                actionName: 'qualification_approved',
                message: 'Qualification approved for certificate.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'comment' => $comment,
                    'issue_certificate' => $issueCertificate,
                ],
                actor: $actor,
            );

            if ($issueCertificate) {
                if (! $actor->can('verification.certificate.issue')) {
                    throw ValidationException::withMessages([
                        'issue_certificate' => 'You do not have permission to issue certificates.',
                    ]);
                }

                $this->certificates->issue($qualification, $actor, reissue: false);
                $qualification->refresh();
            }

            return $qualification;
        });
    }

    public function reject(Qualification $qualification, User $actor, string $reason): Qualification
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => 'Reason is required.',
            ]);
        }

        return DB::transaction(function () use ($qualification, $actor, $reason) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $application = $qualification->application;
            if (! $application instanceof Application) {
                throw ValidationException::withMessages([
                    'qualification' => 'Application not found for this qualification.',
                ]);
            }

            $vs = $qualification->verification_state;
            if (! in_array($vs, [VerificationState::UnderLevel2Review, VerificationState::AutoVerifiedPendingLevel2], true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'This qualification is not awaiting a Level 2 decision.',
                ]);
            }

            if ($vs === VerificationState::AutoVerifiedPendingLevel2) {
                $this->locks->assertActorHoldsLockOrIsSuperAdmin($qualification, $actor);
            }

            $before = $qualification->only([
                'verification_state',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::Rejected,
                'level2_review_owner_id' => null,
            ])->save();

            if ($vs === VerificationState::AutoVerifiedPendingLevel2) {
                $this->locks->clearLock($qualification);
            }

            ApplicationComment::create([
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'author_user_id' => $actor->id,
                'type' => 'decision_reason',
                'visibility' => 'applicant_visible',
                'body' => $reason,
            ]);

            $this->lifecycle->event(
                application: $application,
                eventType: 'decision',
                eventCodeBase: 'decision.qualification_rejected.q'.$qualification->id,
                stage: LifecycleStage::Decision,
                title: 'Qualification rejected',
                description: 'ZAQA rejected one qualification item.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: $reason,
                metadata: [
                    'qualification_id' => $qualification->id,
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
                eventType: 'verification.qualification_rejected',
                module: 'Verification',
                actionName: 'qualification_rejected',
                message: 'Qualification rejected.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'reason' => $reason,
                ],
                actor: $actor,
            );

            $this->outcomeNotifications->notifyQualificationRejected($application, $qualification, $reason);

            return $qualification;
        });
    }
}
