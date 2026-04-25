<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\ApplicationLevel1Completed;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerificationReviewService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly VerificationWorkflowService $workflow,
    ) {
    }

    public function level1Complete(Application $application, User $actor, string $findings): Application
    {
        if ((int) $application->assigned_level1_user_id !== (int) $actor->id) {
            throw ValidationException::withMessages([
                'assignment' => 'This application is not assigned to you.',
            ]);
        }

        $findings = trim($findings);
        if ($findings === '') {
            throw ValidationException::withMessages([
                'findings' => 'Findings are required.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $findings) {
            $application->refresh();

            ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $actor->id,
                'type' => 'review_note',
                'visibility' => 'internal',
                'body' => $findings,
            ]);

            $before = [
                'verification_state' => $application->verification_state?->value ?? null,
            ];

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::UnderLevel2Review,
                toApplicationStatus: null,
                actor: $actor,
                eventType: 'review',
                eventCode: 'review.level1_completed',
                stage: LifecycleStage::Review,
                title: 'Level 1 review completed',
                description: 'Level 1 review was completed and sent to Level 2 for final review.',
                visibility: LifecycleVisibility::Internal,
                comment: null,
                metadata: [
                    'assigned_level1_user_id' => $application->assigned_level1_user_id,
                ],
            );

            $after = [
                'verification_state' => $application->verification_state?->value ?? null,
            ];

            $this->audit->record(
                eventType: 'verification.level1_completed',
                module: 'Verification',
                actionName: 'level1_completed',
                message: 'Level 1 review completed.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'findings' => $findings,
                ],
                actor: $actor,
            );

            if ($application->assigned_by_level2_user_id) {
                $assignedBy = User::query()->find($application->assigned_by_level2_user_id);
                if ($assignedBy) {
                    event(new ApplicationLevel1Completed($application, $actor, $assignedBy, $findings));
                }
            }

            return $application;
        });
    }

    public function level2ReturnToLevel1(Application $application, User $actor, string $comment): Application
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Comment is required.',
            ]);
        }

        if (! $application->assigned_level1_user_id) {
            throw ValidationException::withMessages([
                'assignment' => 'This application has no Level 1 assignee.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $comment) {
            $application->refresh();

            ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $actor->id,
                'type' => 'review_note',
                'visibility' => 'internal',
                'body' => $comment,
            ]);

            $before = [
                'verification_state' => $application->verification_state?->value ?? null,
            ];

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::UnderLevel1Review,
                toApplicationStatus: null,
                actor: $actor,
                eventType: 'review',
                eventCode: 'review.level2_returned_to_level1',
                stage: LifecycleStage::Review,
                title: 'Returned to Level 1',
                description: 'Level 2 returned the application to Level 1 with comments.',
                visibility: LifecycleVisibility::Internal,
                comment: $comment,
                metadata: [
                    'assigned_level1_user_id' => $application->assigned_level1_user_id,
                ],
            );

            $after = [
                'verification_state' => $application->verification_state?->value ?? null,
            ];

            $this->audit->record(
                eventType: 'verification.level2_returned_to_level1',
                module: 'Verification',
                actionName: 'level2_return_to_level1',
                message: 'Application returned to Level 1.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'comment' => $comment,
                ],
                actor: $actor,
            );

            return $application;
        });
    }
}

