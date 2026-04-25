<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\Logout;

class LogUserLoggedOut
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Logout $event): void
    {
        $user = $event->user;

        $this->audit->record(
            eventType: 'identity.logout',
            module: 'Identity',
            actionName: 'logout',
            message: 'User logged out.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            metadata: [
                'guard' => $event->guard,
            ],
            actor: is_object($user) ? $user : null,
        );
    }
}

