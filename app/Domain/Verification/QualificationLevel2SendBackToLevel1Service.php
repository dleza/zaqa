<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Documents\ApplicantDocumentService;
use App\Domain\Tracking\ApplicationLifecycleService;
use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Domain\Verification\Events\QualificationSentBackToLevel1ByLevel2;
use App\Enums\DocumentType;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QualificationLevel2SendBackToLevel1Service
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly ApplicationLifecycleService $lifecycle,
        private readonly ApplicantDocumentService $documents,
        private readonly QualificationAutoAssignmentService $level1AutoAssignment,
        private readonly QualificationLevel2ReviewLockService $locks,
    ) {}

    public function sendBackToLevel1(
        Qualification $qualification,
        User $actor,
        string $comment,
        ?UploadedFile $attachment = null,
    ): Qualification {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Correction comment is required.',
            ]);
        }

        return DB::transaction(function () use ($qualification, $actor, $comment, $attachment) {
            $qualification->refresh();
            $qualification->loadMissing('application');
            $application = $qualification->application;

            $this->assertCanSendBackToLevel1($qualification, $actor);

            if ($qualification->verification_state === VerificationState::AutoVerifiedPendingLevel2) {
                $this->locks->clearLock($qualification);
            }

            $before = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'level2_review_owner_id' => $qualification->level2_review_owner_id,
                'returned_to_level1_at' => optional($qualification->returned_to_level1_at)?->toIso8601String(),
                'level1_correction_cycle' => (int) ($qualification->level1_correction_cycle ?? 0),
            ];

            $previousCycle = (int) ($qualification->level1_correction_cycle ?? 0);
            $nextCycle = $previousCycle + 1;

            ApplicationComment::create([
                'application_id' => $application->id,
                'qualification_id' => $qualification->id,
                'author_user_id' => $actor->id,
                'type' => 'level2_send_back_to_level1',
                'visibility' => 'internal',
                'body' => $comment,
            ]);

            $attachmentDocumentId = null;
            if ($attachment instanceof UploadedFile && $attachment->isValid()) {
                $document = $this->documents->upload(
                    $application,
                    DocumentType::Level2SendBackToLevel1Attachment,
                    $attachment,
                    $actor,
                    $qualification,
                );
                $attachmentDocumentId = $document->id;
            }

            $targetLevel1 = $this->resolveLevel1Target($qualification);
            $assignedLevel1Id = null;
            $assignmentFallback = null;

            if ($targetLevel1) {
                $this->assignLevel1ForCorrection($qualification, $actor, $targetLevel1);
                $assignedLevel1Id = (int) $targetLevel1->id;
            } else {
                $qualification->forceFill([
                    'verification_state' => VerificationState::AwaitingAssignment,
                    'assigned_verifier_id' => null,
                    'assigned_at' => null,
                    'level2_review_owner_id' => null,
                    'level2_review_locked_by' => null,
                    'level2_review_locked_at' => null,
                ])->save();

                $autoResult = $this->level1AutoAssignment->autoAssign(
                    $qualification->fresh(),
                    $actor,
                    'Level 2 returned for Level 1 correction',
                );

                $qualification->refresh();
                if ($autoResult->assigned && $autoResult->assigneeUserId) {
                    $assignedLevel1Id = (int) $autoResult->assigneeUserId;
                    $qualification->forceFill([
                        'verification_state' => VerificationState::UnderLevel1Review,
                    ])->save();
                } else {
                    $assignmentFallback = $autoResult->failureReason ?? 'No Level 1 officer available; routed to assignment pool.';
                }
            }

            $qualification->forceFill([
                'returned_to_level1_at' => now(),
                'returned_to_level1_by_user_id' => $actor->id,
                'returned_to_level1_to_user_id' => $assignedLevel1Id,
                'level2_return_target_user_id' => $actor->id,
                'level1_correction_cycle' => $nextCycle,
                'level2_review_owner_id' => null,
                'level2_review_locked_by' => null,
                'level2_review_locked_at' => null,
            ])->save();

            $qualification->refresh();
            $assignedLevel1 = $assignedLevel1Id ? User::query()->find($assignedLevel1Id) : null;

            $after = [
                'verification_state' => $qualification->verification_state?->value ?? null,
                'assigned_verifier_id' => $qualification->assigned_verifier_id,
                'level2_review_owner_id' => $qualification->level2_review_owner_id,
                'returned_to_level1_at' => optional($qualification->returned_to_level1_at)?->toIso8601String(),
                'returned_to_level1_to_user_id' => $qualification->returned_to_level1_to_user_id,
                'level1_correction_cycle' => (int) $qualification->level1_correction_cycle,
            ];

            $this->lifecycle->event(
                application: $application,
                eventType: 'verification',
                eventCodeBase: 'verification.level2_sent_back_to_level1.q'.$qualification->id.'.'.$nextCycle,
                stage: LifecycleStage::Review,
                title: 'Returned to Level 1 for correction',
                description: 'Level 2 returned this qualification to Level 1 for internal review correction.',
                visibility: LifecycleVisibility::Internal,
                actor: $actor,
                comment: $comment,
                metadata: [
                    'qualification_id' => $qualification->id,
                    'level2_actor_user_id' => $actor->id,
                    'assigned_level1_user_id' => $assignedLevel1Id,
                    'correction_cycle' => $nextCycle,
                    'attachment_document_id' => $attachmentDocumentId,
                    'assignment_fallback' => $assignmentFallback,
                ],
                occurredAt: now(),
            );

            $this->audit->record(
                eventType: 'verification.level2_sent_back_to_level1',
                module: 'Verification',
                actionName: 'level2_sent_back_to_level1',
                message: 'Level 2 returned qualification to Level 1 for correction.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'comment' => $comment,
                    'level2_actor_user_id' => $actor->id,
                    'assigned_level1_user_id' => $assignedLevel1Id,
                    'correction_cycle' => $nextCycle,
                    'attachment_document_id' => $attachmentDocumentId,
                    'assignment_fallback' => $assignmentFallback,
                ],
                actor: $actor,
            );

            event(new QualificationSentBackToLevel1ByLevel2(
                $qualification,
                $actor,
                $comment,
                $assignedLevel1,
            ));

            return $qualification;
        });
    }

    private function assertCanSendBackToLevel1(Qualification $qualification, User $actor): void
    {
        $vs = $qualification->verification_state;
        $allowedStates = [
            VerificationState::UnderLevel2Review,
            VerificationState::AutoVerifiedPendingLevel2,
        ];

        if (! $vs instanceof VerificationState || ! in_array($vs, $allowedStates, true)) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification cannot be sent back to Level 1 in its current state.',
            ]);
        }

        if ($qualification->returned_to_level1_at !== null
            && in_array($vs, [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review], true)) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification is already with Level 1 for correction.',
            ]);
        }

        if (! $qualification->reviewed_at) {
            throw ValidationException::withMessages([
                'qualification' => 'Level 1 review must be completed before sending back for correction.',
            ]);
        }

        $blocked = [
            VerificationState::ReturnedToApplicant,
            VerificationState::ApprovedForCertificate,
            VerificationState::Rejected,
            VerificationState::CertificateIssued,
            VerificationState::Closed,
        ];

        if ($vs instanceof VerificationState && in_array($vs, $blocked, true)) {
            throw ValidationException::withMessages([
                'qualification' => 'This qualification cannot be sent back in its current state.',
            ]);
        }

        if ($vs === VerificationState::AutoVerifiedPendingLevel2) {
            $this->locks->assertActorHoldsLockOrIsSuperAdmin($qualification, $actor);
        }
    }

    private function resolveLevel1Target(Qualification $qualification): ?User
    {
        $candidates = array_values(array_filter([
            (int) ($qualification->level1_review_completed_by_user_id ?? 0),
            (int) ($qualification->assigned_verifier_id ?? 0),
        ], fn (int $id) => $id > 0));

        foreach (array_unique($candidates) as $userId) {
            $user = User::query()->find($userId);
            if ($this->isEligibleLevel1Officer($user)) {
                return $user;
            }
        }

        return null;
    }

    private function isEligibleLevel1Officer(?User $user): bool
    {
        if (! $user || ! $user->is_active) {
            return false;
        }
        if ($user->applicant_type !== null) {
            return false;
        }
        if (! $user->can('verification.level1.process')) {
            return false;
        }

        return $user->hasRole('Verification Officer Level 1');
    }

    private function assignLevel1ForCorrection(Qualification $qualification, User $assignedBy, User $assignedTo): void
    {
        $qualification->loadMissing('application');
        $application = $qualification->application;
        $previousAssigneeId = (int) ($qualification->assigned_verifier_id ?? 0);
        $previousAssignee = $previousAssigneeId > 0 && $previousAssigneeId !== (int) $assignedTo->id
            ? User::query()->whereKey($previousAssigneeId)->first()
            : null;

        QualificationAssignment::query()
            ->where('qualification_id', $qualification->id)
            ->whereNull('unassigned_at')
            ->lockForUpdate()
            ->update(['unassigned_at' => now()]);

        QualificationAssignment::create([
            'qualification_id' => $qualification->id,
            'assigned_by_user_id' => $assignedBy->id,
            'assigned_to_user_id' => $assignedTo->id,
            'comment' => 'Returned by Level 2 for Level 1 correction.',
            'assigned_at' => now(),
            'unassigned_at' => null,
        ]);

        $qualification->forceFill([
            'assigned_verifier_id' => $assignedTo->id,
            'assigned_at' => now(),
            'verification_state' => VerificationState::UnderLevel1Review,
            'assignment_source' => 'level2_send_back',
            'level2_review_owner_id' => null,
            'level2_review_locked_by' => null,
            'level2_review_locked_at' => null,
        ])->save();

        event(new QualificationAssignedToVerifier(
            $qualification->fresh(),
            $assignedBy,
            $assignedTo,
            'Returned by Level 2 for Level 1 correction.',
            $previousAssignee,
        ));
    }
}
