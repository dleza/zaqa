<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\Verified;

class LogEmailVerified
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Verified $event): void
    {
        $user = $event->user;

        $this->audit->record(
            eventType: 'identity.email_verified',
            module: 'Identity',
            actionName: 'email_verified',
            message: 'User email verified.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            actor: is_object($user) ? $user : null,
        );
    }
}

