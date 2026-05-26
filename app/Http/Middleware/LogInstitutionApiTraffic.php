<?php

namespace App\Http\Middleware;

use App\Models\InstitutionApiClient;
use App\Models\InstitutionIntegrationLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogInstitutionApiTraffic
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('institution_api_log_started_at', microtime(true));
        $request->attributes->set('institution_api_log_request_payload', $this->sanitizeRequestPayload($request));

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $actor = $request->user();
        if (! $actor instanceof InstitutionApiClient) {
            return;
        }

        $startedAt = $request->attributes->get('institution_api_log_started_at');
        $latencyMs = is_numeric($startedAt) ? (int) round((microtime(true) - (float) $startedAt) * 1000) : null;

        $statusCode = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : null;

        $status = match (true) {
            $statusCode >= 200 && $statusCode < 300 => 'success',
            $statusCode === 422 => 'validation_failed',
            in_array($statusCode, [401, 403], true) => 'unauthorized',
            $statusCode === 429 => 'throttled',
            default => 'failed',
        };

        $requestPayload = $request->attributes->get('institution_api_log_request_payload');
        $responsePayload = $this->sanitizeResponsePayload($response);

        try {
            InstitutionIntegrationLog::query()->create([
                'awarding_institution_id' => (int) $actor->awarding_institution_id,
                'institution_api_client_id' => (int) $actor->id,
                'endpoint' => '/'.ltrim((string) $request->path(), '/'),
                'method' => strtoupper((string) $request->method()),
                'correlation_id' => (string) ($request->attributes->get('correlation_id') ?? ''),
                'status_code' => $statusCode,
                'status' => $status,
                'request_payload' => is_array($requestPayload) ? $requestPayload : null,
                'response_payload' => is_array($responsePayload) ? $responsePayload : null,
                'error_message' => $statusCode >= 500 ? 'Server error' : null,
                'latency_ms' => $latencyMs,
                'ip_address' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // Never fail the request because of logging.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeRequestPayload(Request $request): array
    {
        // Never log headers (bearer tokens).
        $payload = $request->all();

        // Batch payloads can be large; keep only a summary.
        if ($request->is('api/institution/*/learner-records/batch')) {
            $count = is_array($payload['records'] ?? null) ? count($payload['records']) : null;
            return ['records_count' => $count];
        }

        $payload = $this->maskSensitiveFields($payload);

        // Cap size by trimming very long strings.
        array_walk_recursive($payload, function (&$v) {
            if (is_string($v) && strlen($v) > 512) {
                $v = substr($v, 0, 512).'…';
            }
        });

        return $payload;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function sanitizeResponsePayload(Response $response): ?array
    {
        $contentType = $response->headers->get('Content-Type');
        if (! is_string($contentType) || ! str_contains($contentType, 'application/json')) {
            return null;
        }

        $content = method_exists($response, 'getContent') ? $response->getContent() : null;
        if (! is_string($content) || $content === '') {
            return null;
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded)) {
                return null;
            }

            $decoded = $this->maskSensitiveFields($decoded);

            // Avoid storing huge search responses; keep high-level summary.
            if (isset($decoded['data']['items']) && is_array($decoded['data']['items']) && count($decoded['data']['items']) > 20) {
                $decoded['data']['items'] = array_slice($decoded['data']['items'], 0, 20);
            }

            return $decoded;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function maskSensitiveFields(array $payload): array
    {
        foreach (['nrc_number', 'passport_no'] as $field) {
            if (isset($payload[$field]) && is_string($payload[$field])) {
                $payload[$field] = $this->maskString($payload[$field]);
            }
        }

        return $payload;
    }

    private function maskString(string $value): string
    {
        $v = trim($value);
        if ($v === '') {
            return $v;
        }
        $len = strlen($v);
        if ($len <= 4) {
            return str_repeat('*', $len);
        }

        return substr($v, 0, 2).str_repeat('*', $len - 4).substr($v, -2);
    }
}

