<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\QualificationAutoAssignmentService;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\Qualification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AutoVerifiedQualificationReviewService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly QualificationLevel2ReviewLockService $locks,
        private readonly QualificationAutoAssignmentService $autoAssignments,
    ) {}

    public function sendToManualReview(Qualification $qualification, User $actor): Qualification
    {
        $qualification = DB::transaction(function () use ($qualification, $actor) {
            $qualification->refresh();
            $qualification->loadMissing('application');

            $application = $qualification->application;
            if (! $application instanceof Application) {
                throw ValidationException::withMessages([
                    'qualification' => 'Application not found for this qualification.',
                ]);
            }

            if ($qualification->verification_state !== VerificationState::AutoVerifiedPendingLevel2) {
                throw ValidationException::withMessages([
                    'qualification' => 'This qualification is not awaiting Level 2 auto-verification review.',
                ]);
            }

            $this->locks->assertActorHoldsLockOrIsSuperAdmin($qualification, $actor);

            $before = $qualification->only([
                'verification_state',
                'assigned_verifier_id',
                'assigned_at',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $qualification->forceFill([
                'verification_state' => VerificationState::AwaitingAssignment,
                'assigned_verifier_id' => null,
                'assigned_at' => null,
                'level2_review_owner_id' => null,
            ])->save();

            $this->locks->clearLock($qualification);

            $after = $qualification->only([
                'verification_state',
                'assigned_verifier_id',
                'assigned_at',
                'level2_review_owner_id',
                'level2_review_locked_by',
                'level2_review_locked_at',
            ]);

            $this->lifecycle->event(
                application: $application,
                eventType: 'auto_verification',
                eventCodeBase: 'auto_verification.sent_to_manual_review.q'.$qualification->id,
                stage: LifecycleStage::Review,
                title: 'Manual review required',
                description: 'ZAQA routed an auto-verified qualification to the manual verification queue.',
                visibility: LifecycleVisibility::Both,
                actor: $actor,
                comment: null,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'from_state' => VerificationState::AutoVerifiedPendingLevel2->value,
                    'to_state' => VerificationState::AwaitingAssignment->value,
                ],
                occurredAt: now(),
            );

            $this->audit->record(
                eventType: 'auto_verification.sent_to_manual_review',
                module: 'Verification',
                actionName: 'sent_to_manual_review',
                message: 'Auto-verified qualification routed to manual review.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                ],
                actor: $actor,
            );

            return $qualification;
        });

        // After routing to manual review queue, attempt auto-assignment to Level 1 (category-based).
        if ($qualification->verification_state === VerificationState::AwaitingAssignment && ! $qualification->assigned_verifier_id) {
            $this->autoAssignments->autoAssign($qualification, actor: $actor, reason: 'sent_to_manual_review');
        }

        return $qualification->refresh();
    }
}
