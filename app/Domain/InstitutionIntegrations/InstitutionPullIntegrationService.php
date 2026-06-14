<?php

namespace App\Domain\InstitutionIntegrations;

use App\Models\AwardingInstitution;
use App\Models\InstitutionIntegration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class InstitutionPullIntegrationService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(?InstitutionIntegration $integration): array
    {
        if (! $integration) {
            return [
                'id' => null,
                'is_active' => true,
                'supports_pull' => false,
                'lookup_url' => null,
                'auth_type' => 'bearer_token',
                'request_method' => 'POST',
                'timeout_seconds' => 15,
                'retry_attempts' => 2,
                'rate_limit_per_minute' => null,
                'driver' => 'generic_rest',
                'has_credentials' => false,
                'last_success_at' => null,
                'last_failure_at' => null,
            ];
        }

        return [
            'id' => (int) $integration->id,
            'is_active' => (bool) $integration->is_active,
            'supports_pull' => (bool) $integration->supports_pull,
            'lookup_url' => $integration->lookup_url,
            'auth_type' => $integration->auth_type ?? 'bearer_token',
            'request_method' => $integration->request_method ?? 'POST',
            'timeout_seconds' => (int) ($integration->timeout_seconds ?? 15),
            'retry_attempts' => (int) ($integration->retry_attempts ?? 2),
            'rate_limit_per_minute' => $integration->rate_limit_per_minute,
            'driver' => $integration->driver ?? 'generic_rest',
            'has_credentials' => (bool) ($integration->credentials && $integration->credentials !== []),
            'last_success_at' => optional($integration->last_success_at)->toIso8601String(),
            'last_failure_at' => optional($integration->last_failure_at)->toIso8601String(),
        ];
    }

    public function resolveIntegration(AwardingInstitution $institution): InstitutionIntegration
    {
        $institution->loadMissing('integration');

        if ($institution->integration instanceof InstitutionIntegration) {
            return $institution->integration;
        }

        return new InstitutionIntegration([
            'awarding_institution_id' => (int) $institution->id,
            'is_active' => true,
            'supports_push' => true,
            'supports_pull' => false,
            'auth_type' => 'bearer_token',
            'request_method' => 'POST',
            'timeout_seconds' => 15,
            'retry_attempts' => 2,
            'driver' => 'generic_rest',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function savePullSettings(AwardingInstitution $institution, array $validated): InstitutionIntegration
    {
        $integration = $this->resolveIntegration($institution);
        $credentials = is_array($integration->credentials) ? $integration->credentials : [];
        $authType = (string) ($validated['auth_type'] ?? 'bearer_token');

        if ($authType === 'bearer_token' && isset($validated['bearer_token']) && trim((string) $validated['bearer_token']) !== '') {
            $credentials = ['bearer_token' => (string) $validated['bearer_token']];
        }
        if ($authType === 'basic') {
            $u = trim((string) ($validated['basic_username'] ?? ''));
            $p = trim((string) ($validated['basic_password'] ?? ''));
            if ($u !== '' && $p !== '') {
                $credentials = ['basic_username' => $u, 'basic_password' => $p];
            }
        }
        if ($authType === 'none') {
            $credentials = [];
        }

        $integration->fill([
            'is_active' => (bool) $validated['is_active'],
            'supports_push' => $integration->exists ? (bool) $integration->supports_push : true,
            'supports_pull' => (bool) $validated['supports_pull'],
            'lookup_url' => $validated['lookup_url'] ?? null,
            'auth_type' => $authType,
            'credentials' => $credentials !== [] ? $credentials : null,
            'request_method' => strtoupper((string) ($validated['request_method'] ?? 'POST')),
            'timeout_seconds' => (int) ($validated['timeout_seconds'] ?? 15),
            'retry_attempts' => (int) ($validated['retry_attempts'] ?? 2),
            'rate_limit_per_minute' => isset($validated['rate_limit_per_minute']) ? (int) $validated['rate_limit_per_minute'] : null,
            'driver' => $validated['driver'] ?? 'generic_rest',
        ]);
        $integration->save();

        return $integration->fresh();
    }

    public function generateBearerToken(InstitutionIntegration $integration): string
    {
        $plainTextToken = Str::random(64);

        $integration->fill([
            'auth_type' => 'bearer_token',
            'credentials' => ['bearer_token' => $plainTextToken],
            'supports_pull' => true,
        ]);
        $integration->save();

        return $plainTextToken;
    }

    /**
     * @return array{success: bool, message: string, http_status: int|null}
     */
    public function testConnection(InstitutionIntegration $integration): array
    {
        if (! $integration->lookup_url) {
            return [
                'success' => false,
                'message' => 'Lookup URL is not configured.',
                'http_status' => null,
            ];
        }

        try {
            $pending = Http::timeout((int) ($integration->timeout_seconds ?? 15))->connectTimeout(5);
            if ($integration->auth_type === 'bearer_token') {
                $token = is_array($integration->credentials) ? ($integration->credentials['bearer_token'] ?? null) : null;
                if (is_string($token) && $token !== '') {
                    $pending = $pending->withToken($token);
                }
            }
            if ($integration->auth_type === 'basic') {
                $u = is_array($integration->credentials) ? ($integration->credentials['basic_username'] ?? null) : null;
                $p = is_array($integration->credentials) ? ($integration->credentials['basic_password'] ?? null) : null;
                if (is_string($u) && is_string($p) && $u !== '' && $p !== '') {
                    $pending = $pending->withBasicAuth($u, $p);
                }
            }

            $method = strtoupper((string) ($integration->request_method ?? 'POST'));
            if ($method === 'POST') {
                $resp = $pending
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                        'X-Request-Id' => 'connectivity-test',
                    ])
                    ->post($integration->lookup_url, [
                        'correlation_id' => 'connectivity-test',
                        'student_id' => '0000000',
                    ]);
            } else {
                $resp = $pending->head($integration->lookup_url);
                if ($resp->status() >= 400) {
                    $resp = $pending->get($integration->lookup_url);
                }
            }

            $status = (int) $resp->status();

            return $resp->successful()
                ? ['success' => true, 'message' => 'Connection test succeeded (HTTP '.$status.').', 'http_status' => $status]
                : ['success' => false, 'message' => 'Connection test failed (HTTP '.$status.').', 'http_status' => $status];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Connection test failed: '.$e->getMessage(),
                'http_status' => null,
            ];
        }
    }
}
