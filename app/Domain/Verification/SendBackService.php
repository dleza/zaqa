<?php

namespace App\Domain\Verification;

use App\Domain\Audit\AuditLogService;
use App\Domain\Verification\Events\ApplicationSentBackToApplicant;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SendBackService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly VerificationWorkflowService $workflow,
    ) {
    }

    public function sendBackToApplicant(Application $application, User $actor, string $comment): Application
    {
        $comment = trim($comment);
        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'Comment is required.',
            ]);
        }

        return DB::transaction(function () use ($application, $actor, $comment) {
            $application->refresh();

            ApplicationComment::create([
                'application_id' => $application->id,
                'author_user_id' => $actor->id,
                'type' => 'send_back',
                'visibility' => 'applicant_visible',
                'body' => $comment,
            ]);

            $before = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'sent_back_at' => optional($application->sent_back_at)?->toIso8601String(),
            ];

            $application->forceFill([
                'sent_back_at' => now(),
            ])->save();

            $application = $this->workflow->transition(
                application: $application,
                toVerificationState: VerificationState::ReturnedToApplicant,
                toApplicationStatus: ApplicationStatus::SentBack,
                actor: $actor,
                eventType: 'review',
                eventCode: 'review.sent_back_to_applicant',
                stage: LifecycleStage::SentBack,
                title: 'Sent back for amendments',
                description: 'ZAQA requires additional information or corrections. Please review the comment and resubmit.',
                visibility: LifecycleVisibility::Both,
                comment: $comment,
                metadata: [
                    'sent_back_by_user_id' => $actor->id,
                ],
            );

            $after = [
                'current_status' => $application->current_status?->value ?? null,
                'verification_state' => $application->verification_state?->value ?? null,
                'sent_back_at' => optional($application->sent_back_at)?->toIso8601String(),
            ];

            $this->audit->record(
                eventType: 'verification.sent_back_to_applicant',
                module: 'Verification',
                actionName: 'sent_back',
                message: 'Application sent back to applicant.',
                entityType: Application::class,
                entityId: $application->id,
                beforeState: $before,
                afterState: $after,
                metadata: [
                    'comment' => $comment,
                ],
                actor: $actor,
            );

            event(new ApplicationSentBackToApplicant($application, $actor, $comment));

            return $application;
        });
    }
}

