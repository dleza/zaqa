<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuditLogService
{
    public function record(
        string $eventType,
        string $module,
        string $actionName,
        string $message,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $beforeState = null,
        ?array $afterState = null,
        ?array $metadata = null,
        ?Authenticatable $actor = null,
        ?string $correlationId = null,
    ): AuditLog {
        $actor ??= Auth::user();

        $request = $this->currentRequest();

        return AuditLog::create([
            'actor_user_id' => $actor?->getAuthIdentifier(),
            'actor_name_snapshot' => $this->actorNameSnapshot($actor),
            'event_type' => $eventType,
            'module' => $module,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action_name' => $actionName,
            'message' => $message,
            'before_state' => $beforeState,
            'after_state' => $afterState,
            'metadata' => $metadata,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            'correlation_id' => $correlationId ?? $this->correlationIdFromRequest($request),
        ]);
    }

    private function actorNameSnapshot(?Authenticatable $actor): ?string
    {
        if (! $actor) {
            return null;
        }

        $name = data_get($actor, 'name');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        $email = data_get($actor, 'email');
        if (is_string($email) && $email !== '') {
            return $email;
        }

        return null;
    }

    private function correlationIdFromRequest(?Request $request): ?string
    {
        if (! $request) {
            return null;
        }

        $attribute = $request->attributes->get('correlation_id');
        if (is_string($attribute) && $attribute !== '') {
            return $attribute;
        }

        $header = $request->headers->get('X-Request-Id')
            ?: $request->headers->get('X-Correlation-Id');

        return is_string($header) && $header !== '' ? $header : null;
    }

    private function currentRequest(): ?Request
    {
        try {
            $request = request();
        } catch (\Throwable) {
            return null;
        }

        return $request instanceof Request ? $request : null;
    }
}

