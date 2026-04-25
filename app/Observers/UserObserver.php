<?php

namespace App\Observers;

use App\Domain\Audit\AuditLogService;
use App\Models\User;

class UserObserver
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function created(User $user): void
    {
        $this->audit->record(
            eventType: 'identity.user_created',
            module: 'Identity',
            actionName: 'user_created',
            message: 'User created.',
            entityType: $user::class,
            entityId: (int) $user->getAuthIdentifier(),
            afterState: [
                'id' => (int) $user->getAuthIdentifier(),
                'name' => $user->name,
                'email' => $user->email,
            ],
        );
    }

    public function updated(User $user): void
    {
        $changes = $user->getChanges();

        unset($changes['updated_at']);

        if ($changes === []) {
            return;
        }

        $original = $user->getOriginal();

        $before = [];
        $after = [];

        foreach ($changes as $key => $newValue) {
            if ($key === 'remember_token') {
                continue;
            }

            if ($key === 'password') {
                $before[$key] = '[redacted]';
                $after[$key] = '[redacted]';
                continue;
            }

            $before[$key] = $original[$key] ?? null;
            $after[$key] = $newValue;
        }

        if ($before === [] && $after === []) {
            return;
        }

        $this->audit->record(
            eventType: 'identity.user_updated',
            module: 'Identity',
            actionName: 'user_updated',
            message: 'User updated.',
            entityType: $user::class,
            entityId: (int) $user->getAuthIdentifier(),
            beforeState: $before,
            afterState: $after,
            metadata: [
                'changed_keys' => array_values(array_unique(array_merge(array_keys($before), array_keys($after)))),
            ],
        );
    }
}

