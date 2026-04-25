<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\Registered;

class LogUserRegistered
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Registered $event): void
    {
        $user = $event->user;

        $this->audit->record(
            eventType: 'identity.registered',
            module: 'Identity',
            actionName: 'registered',
            message: 'User registered.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            actor: is_object($user) ? $user : null,
        );
    }
}

