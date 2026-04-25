<?php

namespace App\Domain\Verification;

use App\Domain\Tracking\ApplicationLifecycleService;
use App\Enums\ApplicationStatus;
use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Enums\VerificationState;
use App\Models\Application;
use App\Models\ApplicationStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class VerificationWorkflowService
{
    public function __construct(
        private readonly ApplicationLifecycleService $lifecycle,
    ) {
    }

    /**
     * Transition verification_state (internal) and optionally application current_status (applicant-facing),
     * while recording status history + lifecycle events where appropriate.
     */
    public function transition(
        Application $application,
        VerificationState $toVerificationState,
        ?ApplicationStatus $toApplicationStatus,
        User $actor,
        string $eventType,
        string $eventCode,
        LifecycleStage $stage,
        string $title,
        ?string $description,
        LifecycleVisibility $visibility,
        ?string $comment = null,
        ?array $metadata = null,
    ): Application {
        return DB::transaction(function () use ($application, $toVerificationState, $toApplicationStatus, $actor, $eventType, $eventCode, $stage, $title, $description, $visibility, $comment, $metadata) {
            $application->refresh();

            $fromStatus = $application->current_status;

            $application->forceFill([
                'verification_state' => $toVerificationState,
            ]);

            if ($toApplicationStatus) {
                $application->forceFill([
                    'current_status' => $toApplicationStatus,
                ]);
            }

            $application->save();

            if ($toApplicationStatus && $fromStatus !== $toApplicationStatus) {
                ApplicationStatusHistory::create([
                    'application_id' => $application->id,
                    'from_status' => $fromStatus?->value ?? null,
                    'to_status' => $toApplicationStatus->value,
                    'changed_by_user_id' => $actor->id,
                    'comment' => $title,
                    'changed_at' => now(),
                    'metadata' => $metadata,
                ]);
            }

            $this->lifecycle->event(
                application: $application,
                eventType: $eventType,
                eventCodeBase: $eventCode,
                stage: $stage,
                title: $title,
                description: $description,
                visibility: $visibility,
                actor: $actor,
                comment: $comment,
                metadata: $metadata,
                occurredAt: now(),
            );

            return $application;
        });
    }
}

