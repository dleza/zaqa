<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\PasswordReset;

class LogPasswordReset
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(PasswordReset $event): void
    {
        $user = $event->user;

        $this->audit->record(
            eventType: 'identity.password_reset',
            module: 'Identity',
            actionName: 'password_reset',
            message: 'User password reset.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            actor: is_object($user) ? $user : null,
        );
    }
}

