<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Domain\Audit\AuditLogService;
use App\Domain\InstitutionIntegrations\InstitutionPullIntegrationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integrations\UpdateInstitutionIntegrationRequest;
use App\Models\AwardingInstitution;
use App\Models\InstitutionIntegration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminInstitutionIntegrationsController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $q = trim((string) $request->query('q', ''));
        $pull = $request->query('supports_pull');

        $institutions = AwardingInstitution::query()
            ->with(['integration'])
            ->when($q !== '', fn ($qq) => $qq->where('name', 'like', "%{$q}%"))
            ->when(is_string($pull) && $pull !== '', fn ($qq) => $qq->whereHas('integration', fn ($i) => $i->where('supports_pull', (bool) ((int) $pull))))
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (AwardingInstitution $i) => [
                'id' => (int) $i->id,
                'name' => $i->name,
                'integration' => $i->integration ? [
                    'id' => (int) $i->integration->id,
                    'is_active' => (bool) $i->integration->is_active,
                    'supports_push' => (bool) $i->integration->supports_push,
                    'supports_pull' => (bool) $i->integration->supports_pull,
                    'lookup_url' => $i->integration->lookup_url,
                    'auth_type' => $i->integration->auth_type,
                    'driver' => $i->integration->driver,
                    'last_success_at' => optional($i->integration->last_success_at)->toIso8601String(),
                    'last_failure_at' => optional($i->integration->last_failure_at)->toIso8601String(),
                ] : null,
                'edit_url' => route('admin.integrations.institution_integrations.edit', $i),
            ]);

        return Inertia::render('Admin/Integrations/InstitutionIntegrations/Index', [
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'supports_pull' => is_string($pull) ? $pull : null,
            ],
        ]);
    }

    public function edit(Request $request, AwardingInstitution $awardingInstitution): Response
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $awardingInstitution->loadMissing('integration');
        $integration = $awardingInstitution->integration;

        return Inertia::render('Admin/Integrations/InstitutionIntegrations/Edit', [
            'institution' => ['id' => (int) $awardingInstitution->id, 'name' => $awardingInstitution->name],
            'integration' => $integration ? [
                'id' => (int) $integration->id,
                'is_active' => (bool) $integration->is_active,
                'supports_push' => (bool) $integration->supports_push,
                'supports_pull' => (bool) $integration->supports_pull,
                'lookup_url' => $integration->lookup_url,
                'auth_type' => $integration->auth_type ?? 'none',
                'request_method' => $integration->request_method,
                'timeout_seconds' => (int) $integration->timeout_seconds,
                'retry_attempts' => (int) $integration->retry_attempts,
                'rate_limit_per_minute' => $integration->rate_limit_per_minute,
                'driver' => $integration->driver ?? 'generic_rest',
                'has_credentials' => (bool) ($integration->credentials && $integration->credentials !== []),
            ] : [
                'id' => null,
                'is_active' => true,
                'supports_push' => true,
                'supports_pull' => false,
                'lookup_url' => null,
                'auth_type' => 'none',
                'request_method' => 'POST',
                'timeout_seconds' => 15,
                'retry_attempts' => 2,
                'rate_limit_per_minute' => null,
                'driver' => 'generic_rest',
                'has_credentials' => false,
            ],
            'save_url' => route('admin.integrations.institution_integrations.update', $awardingInstitution),
            'test_url' => route('admin.integrations.institution_integrations.test', $awardingInstitution),
        ]);
    }

    public function update(
        UpdateInstitutionIntegrationRequest $request,
        AwardingInstitution $awardingInstitution,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validated();

        $awardingInstitution->loadMissing('integration');
        $integration = $awardingInstitution->integration ?: new InstitutionIntegration(['awarding_institution_id' => $awardingInstitution->id]);

        $credentials = is_array($integration->credentials) ? $integration->credentials : [];
        $authType = $validated['auth_type'] ?? 'none';

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
            'supports_push' => (bool) $validated['supports_push'],
            'supports_pull' => (bool) $validated['supports_pull'],
            'lookup_url' => $validated['lookup_url'] ?? null,
            'auth_type' => $authType,
            'credentials' => $credentials !== [] ? $credentials : null,
            'request_method' => strtoupper((string) $validated['request_method']),
            'timeout_seconds' => (int) $validated['timeout_seconds'],
            'retry_attempts' => (int) $validated['retry_attempts'],
            'rate_limit_per_minute' => isset($validated['rate_limit_per_minute']) ? (int) $validated['rate_limit_per_minute'] : null,
            'driver' => $validated['driver'] ?? 'generic_rest',
        ]);
        $integration->save();

        $audit->record(
            eventType: 'institution_integration.updated',
            module: 'Integrations',
            actionName: 'institution_integration_updated',
            message: 'Institution pull integration settings updated.',
            entityType: InstitutionIntegration::class,
            entityId: (int) $integration->id,
            metadata: [
                'awarding_institution_id' => (int) $awardingInstitution->id,
                'supports_pull' => (bool) $integration->supports_pull,
                'lookup_url_set' => (bool) ($integration->lookup_url),
            ],
        );

        return redirect()
            ->route('admin.integrations.institution_integrations.edit', $awardingInstitution)
            ->with('success', 'Integration settings saved.');
    }

    public function test(Request $request, AwardingInstitution $awardingInstitution, InstitutionPullIntegrationService $pullIntegration): RedirectResponse
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $awardingInstitution->loadMissing('integration');
        $integration = $awardingInstitution->integration;
        if (! $integration || ! $integration->lookup_url) {
            return back()->with('error', 'Lookup URL is not configured.');
        }

        $result = $pullIntegration->testConnection($integration);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }
}

