<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\Login;

class LogUserLoggedIn
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Login $event): void
    {
        $user = $event->user;

        if (is_object($user)) {
            $user->forceFill(['last_login_at' => now()])->save();
        }

        $this->audit->record(
            eventType: 'identity.login',
            module: 'Identity',
            actionName: 'login',
            message: 'User logged in.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            metadata: [
                'guard' => $event->guard,
                'remember' => $event->remember,
            ],
            actor: is_object($user) ? $user : null,
        );
    }
}
