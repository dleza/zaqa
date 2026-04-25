<?php

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\AuditLogService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Arr;

class LogUserLoginFailed
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function handle(Failed $event): void
    {
        $user = $event->user;

        $credentials = is_array($event->credentials)
            ? Arr::except($event->credentials, ['password', 'token'])
            : null;

        $this->audit->record(
            eventType: 'identity.login_failed',
            module: 'Identity',
            actionName: 'login_failed',
            message: 'User login failed.',
            entityType: is_object($user) ? $user::class : null,
            entityId: is_object($user) ? (int) $user->getAuthIdentifier() : null,
            metadata: [
                'guard' => $event->guard,
                'credentials' => $credentials,
            ],
            actor: null,
        );
    }
}

