<?php

namespace App\Domain\Tracking;

use App\Enums\LifecycleStage;
use App\Enums\LifecycleVisibility;
use App\Models\Application;
use App\Models\ApplicationLifecycleEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ApplicationLifecycleService
{
    /**
     * Record (create or update) a milestone-style lifecycle event.
     * The table enforces uniqueness by (application_id, event_code).
     *
     * @param array<string,mixed>|null $metadata
     */
    public function milestone(
        Application $application,
        string $eventType,
        string $eventCode,
        LifecycleStage $stage,
        string $title,
        ?string $description,
        LifecycleVisibility $visibility,
        ?User $actor = null,
        ?string $comment = null,
        ?array $metadata = null,
        ?\DateTimeInterface $occurredAt = null,
    ): ApplicationLifecycleEvent {
        $occurredAt ??= now();

        return DB::transaction(function () use ($application, $eventType, $eventCode, $stage, $title, $description, $visibility, $actor, $comment, $metadata, $occurredAt) {
            $statusSnapshot = $application->current_status?->value ?? (string) $application->current_status;

            /** @var ApplicationLifecycleEvent $event */
            $event = ApplicationLifecycleEvent::query()->updateOrCreate(
                [
                    'application_id' => $application->id,
                    'event_code' => $eventCode,
                ],
                [
                    'event_type' => $eventType,
                    'stage' => $stage,
                    'status_snapshot' => $statusSnapshot !== '' ? $statusSnapshot : null,
                    'title' => $title,
                    'description' => $description,
                    'actor_user_id' => $actor?->id,
                    'actor_name_snapshot' => $actor?->name,
                    'actor_role' => null,
                    'visibility' => $visibility,
                    'comment' => $comment,
                    'metadata' => $metadata,
                    'occurred_at' => $occurredAt,
                ],
            );

            return $event;
        });
    }

    /**
     * Record a repeatable event by suffixing the event code.
     *
     * @param array<string,mixed>|null $metadata
     */
    public function event(
        Application $application,
        string $eventType,
        string $eventCodeBase,
        LifecycleStage $stage,
        string $title,
        ?string $description,
        LifecycleVisibility $visibility,
        ?User $actor = null,
        ?string $comment = null,
        ?array $metadata = null,
        ?\DateTimeInterface $occurredAt = null,
    ): ApplicationLifecycleEvent {
        $occurredAt ??= now();
        $suffix = $occurredAt->format('YmdHisv');

        return $this->milestone(
            application: $application,
            eventType: $eventType,
            eventCode: $eventCodeBase.'.'.$suffix,
            stage: $stage,
            title: $title,
            description: $description,
            visibility: $visibility,
            actor: $actor,
            comment: $comment,
            metadata: $metadata,
            occurredAt: $occurredAt,
        );
    }
}

