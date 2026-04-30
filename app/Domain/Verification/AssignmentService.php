<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\ApplicationAssignedToLevel1;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationAssignment;
use App\Models\ApplicationComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly VerificationWorkflowService $workflow,
    ) {
    }

    public function assign(Application $application, User $level2Actor, User $level1Assignee, ?string $comment = null): Application
    {
        if ($level2Actor->id === $level1Assignee->id) {
            throw ValidationException::withMessages([
                'assignee' => 'Level 1 assignee cannot be the assigner.',
            ]);
        }

        $comment = $comment !== null ? trim($comment) : null;
        if ($comment === '') {
            $comment = null;
        }

        return DB::transaction(function () use ($application, $level2Actor, $level1Assignee, $comment) {
            $application->refresh();

            // Close any active assignment history row.
            ApplicationAssignment::query()
                ->where('application_id', $application->id)
                ->whereNull('unassigned_at')
                ->lockForUpdate()
                ->update(['unassigned_at' => now()]);

            ApplicationAssignment::create([
                'application_id' => $application->id,
                'assigned_by_user_id' => $level2Actor->id,
                'assigned_to_user_id' => $level1Assignee->id,
                'comment' => $comment,
                'assigned_at' => now(),
                'unassigned_at' => null,
            ]);

            if ($comment) {
                ApplicationComment::create([
                    'application_id' => $application->id,
                    'author_user_id' => $level2Actor->id,
                    'type' => 'assignment_note',
                    'visibility' => 'internal',
                    'body' => $comment,
                ]);
            }

            $before = [
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
                'verification_state' => $application->verification_state?->value ?? null,
                'current_status' => $application->current_status?->value ?? null,
            ];

            $application->forceFill([
                'assigned_level1_user_id' => $level1Assignee->id,
                'assigned_by_level2_user_id' => $level2Actor->id,
            ])->save();

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::AssignedToLevel1,
                toApplicationStatus: $application->current_status === ApplicationStatus::Submitted || $application->current_status === ApplicationStatus::Resubmitted
                    ? ApplicationStatus::InProgress
                    : null,
                actor: $level2Actor,
                eventType: 'review',
                eventCode: 'review.assigned_to_level1',
                stage: LifecycleStage::Review,
                title: 'Assigned for review',
                description: 'Your application has been assigned to a verification officer for review.',
                visibility: LifecycleVisibility::Both,
                comment: null,
                metadata: [
                    'assigned_to_user_id' => $level1Assignee->id,
                    'assigned_by_user_id' => $level2Actor->id,
                ],
            );

            // Applicant-visible signal that review has started (helps tracking UX).
            // This is a milestone (unique event_code) so the applicant sees a stable "Under review" state.
            $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::AssignedToLevel1,
                toApplicationStatus: null,
                actor: $level2Actor,
                eventType: 'review',
                eventCode: 'review.started',
                stage: LifecycleStage::Review,
                title: 'Review started',
                description: 'Your application is now being reviewed by ZAQA.',
                visibility: LifecycleVisibility::Both,
                comment: null,
                metadata: [
                    'assigned_to_user_id' => $level1Assignee->id,
                ],
            );

            $after = [
                'assigned_level1_user_id' => $application->assigned_level1_user_id,
                'assigned_by_level2_user_id' => $application->assigned_by_level2_user_id,
                'verification_state' => $application->verification_state?->value ?? null,
                'current_status' => $application->current_status?->value ?? null,
            ];

            $this->audit->record(
                eventType: 'verification.assigned',
                module: 'Verification',
                actionName: 'assigned',
                message: 'Application assigned to Level 1 reviewer.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'assigned_to_user_id' => $level1Assignee->id,
                    'assigned_by_user_id' => $level2Actor->id,
                    'comment' => $comment,
                ],
                actor: $level2Actor,
            );

            event(new ApplicationAssignedToLevel1($application, $level2Actor, $level1Assignee, $comment));

            return $application;
        });
    }
}
