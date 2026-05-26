<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\QualificationAssignedToVerifier;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\Qualification;
use App\Models\QualificationAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly VerificationWorkflowService $workflow,
    ) {}

    public function assign(Qualification $qualification, User $level2Actor, User $verifierAssignee, ?string $comment = null): Qualification
    {
        return $this->assignWithContext(
            qualification: $qualification,
            assignedBy: $level2Actor,
            assignedTo: $verifierAssignee,
            comment: $comment,
            context: [],
        );
    }

    /**
     * Assign a qualification to a Level 1 verifier with optional metadata.
     *
     * Context keys:
     * - source: auto|manual|reassigned
     * - category_id: int|null
     * - reason: string|null
     *
     * @param  array<string, mixed>  $context
     */
    public function assignWithContext(Qualification $qualification, User $assignedBy, User $assignedTo, ?string $comment = null, array $context = []): Qualification
    {
        if ($assignedBy->id === $assignedTo->id) {
            throw ValidationException::withMessages([
                'assignee' => 'Level 1 assignee cannot be the assigner.',
            ]);
        }

        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        $source = isset($context['source']) && is_string($context['source']) ? trim((string) $context['source']) : '';
        $categoryId = isset($context['category_id']) && is_numeric($context['category_id']) ? (int) $context['category_id'] : null;
        $reason = isset($context['reason']) && is_string($context['reason']) ? trim((string) $context['reason']) : null;

        return DB::transaction(function () use ($qualification, $assignedBy, $assignedTo, $comment, $source, $categoryId, $reason) {
            $qualification->refresh();
            $qualification->loadMissing('application');
            $application = $qualification->application;
            $previousAssigneeId = (int) ($qualification->assigned_verifier_id ?? 0);
            $previousAssignee = $previousAssigneeId > 0 && $previousAssigneeId !== (int) $assignedTo->id
                ? User::query()->whereKey($previousAssigneeId)->first()
                : null;

            // Close any active assignment history row.
            QualificationAssignment::query()
                ->where('qualification_id', $qualification->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->update(['unassigned_at' => now()]);

            QualificationAssignment::create([
                'qualification_id' => $qualification->id,
                'assigned_by_user_id' => $assignedBy->id,
                'assigned_to_user_id' => $assignedTo->id,
                'comment' => $comment,
                'assigned_at' => now(),
                'unassigned_at' => null,
            ]);

            if ($comment) {
                ApplicationComment::create([
                    'application_id' => $application->id,
                    'author_user_id' => $assignedBy->id,
                    'type' => 'assignment_note',
                    'visibility' => 'internal',
                    'body' => $comment,
                ]);
            }

            $before = $qualification->only([
                'assigned_verifier_id',
                'assigned_at',
                'verification_state',
                'verification_assignment_category_id',
                'assignment_source',
                'assignment_failure_reason',
                'auto_assigned_at',
            ]);

            $qualification->forceFill([
                'assigned_verifier_id' => $assignedTo->id,
                'assigned_at' => now(),
                'verification_state' => VerificationState::AssignedToLevel1,
                'verification_assignment_category_id' => $categoryId ?: $qualification->verification_assignment_category_id,
                'assignment_source' => $source !== '' ? $source : ($previousAssigneeId > 0 ? 'reassigned' : 'manual'),
                'assignment_failure_reason' => null,
                'auto_assigned_at' => ($source === 'auto') ? now() : null,
            ])->save();

            // Keep application status progression simple: when any qualification is assigned,
            // the parent application moves into In Progress if it was newly submitted.
            if (in_array($application->current_status, [ApplicationStatus::Submitted, ApplicationStatus::Resubmitted], true)) {
                $this->workflow->transition(
                    application: $application,
                    toVerificationState: VerificationState::AssignedToLevel1,
                    toApplicationStatus: ApplicationStatus::InProgress,
                    actor: $assignedBy,
                    eventType: 'review',
                    eventCode: 'review.started',
                    stage: LifecycleStage::Review,
                    title: 'Review started',
                    description: 'Your application is now being reviewed by ZAQA.',
                    visibility: LifecycleVisibility::Both,
                    comment: null,
                    metadata: [
                        'qualification_id' => $qualification->id,
                        'assigned_to_user_id' => $assignedTo->id,
                    ],
                );
            }

            $after = $qualification->only([
                'assigned_verifier_id',
                'assigned_at',
                'verification_state',
                'verification_assignment_category_id',
                'assignment_source',
                'assignment_failure_reason',
                'auto_assigned_at',
            ]);

            $this->audit->record(
                eventType: 'verification.assigned',
                module: 'Verification',
                actionName: 'assigned',
                message: 'Qualification assigned to verifier.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                    'assigned_to_user_id' => $assignedTo->id,
                    'assigned_by_user_id' => $assignedBy->id,
                    'comment' => $comment,
                    'assignment_source' => $after['assignment_source'] ?? null,
                    'assignment_category_id' => $after['verification_assignment_category_id'] ?? null,
                    'reason' => $reason,
                ],
                actor: $assignedBy,
            );

            event(new QualificationAssignedToVerifier($qualification, $assignedBy, $assignedTo, $comment, $previousAssignee));

            return $qualification;
        });
    }

    /**
     * Remove the Level 1 assignee and return the qualification to the assignment pool (awaiting Level 1).
     * Only valid while the task is still with Level 1 (not yet with Level 2 for final review).
     */
    public function revokeLevel1Assignment(Qualification $qualification, User $level2Actor, ?string $comment = null): Qualification
    {
        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        return DB::transaction(function () use ($qualification, $level2Actor, $comment) {
            $qualification->refresh();
            $qualification->loadMissing('application', 'assignedVerifier');
            $application = $qualification->application;

            if (! $qualification->assigned_verifier_id) {
                throw ValidationException::withMessages([
                    'qualification' => 'No Level 1 officer is assigned to this task.',
                ]);
            }

            $vs = $qualification->verification_state;
            if (! in_array($vs, [VerificationState::AssignedToLevel1, VerificationState::UnderLevel1Review], true)) {
                throw ValidationException::withMessages([
                    'qualification' => 'This task cannot be unassigned in its current state.',
                ]);
            }

            $previousAssigneeId = (int) $qualification->assigned_verifier_id;

            QualificationAssignment::query()
                ->where('qualification_id', $qualification->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->update(['unassigned_at' => now()]);

            $assigneeLabel = $qualification->assignedVerifier?->name ?? 'Level 1 officer';
            $noteBody = 'Level 1 assignment removed ('.$assigneeLabel.'). Task is awaiting assignment again.';
            if ($comment) {
                $noteBody .= ' '.$comment;
            }
            ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $level2Actor->id,
                'type' => 'assignment_note',
                'visibility' => 'internal',
                'body' => $noteBody,
            ]);

            $before = $qualification->only([
                'assigned_verifier_id',
                'assigned_at',
                'verification_state',
            ]);

            $qualification->forceFill([
                'assigned_verifier_id' => null,
                'assigned_at' => null,
                'verification_state' => VerificationState::AwaitingAssignment,
            ])->save();

            $after = $qualification->only([
                'assigned_verifier_id',
                'assigned_at',
                'verification_state',
            ]);

            $this->audit->record(
                eventType: 'verification.assignment_revoked',
                module: 'Verification',
                actionName: 'assignment_revoked',
                message: 'Level 1 assignment removed; task returned to the assignment pool.',
                entityType: Qualification::class,
                entityId: $qualification->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'application_id' => $application->id,
                    'qualification_id' => $qualification->id,
                    'previous_assigned_to_user_id' => $previousAssigneeId,
                    'revoked_by_user_id' => $level2Actor->id,
                    'comment' => $comment,
                ],
                actor: $level2Actor,
            );

            return $qualification;
        });
    }
}
