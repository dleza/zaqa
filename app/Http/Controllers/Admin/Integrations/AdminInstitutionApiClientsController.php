<?php

namespace App\Http\Controllers\Admin\Integrations;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Integrations\IssueInstitutionApiTokenRequest;
use App\Http\Requests\Admin\Integrations\EmailInstitutionApiTokenRequest;
use App\Http\Requests\Admin\Integrations\RotateInstitutionApiTokenRequest;
use App\Domain\Audit\AuditLogService;
use App\Mail\InstitutionApiTokenIssuedMail;
use App\Http\Requests\Admin\Integrations\StoreInstitutionApiClientRequest;
use App\Models\AwardingInstitution;
use App\Models\InstitutionApiClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;
use App\Domain\Notifications\OutboundMailService;
use App\Domain\InstitutionIntegrations\InstitutionPullIntegrationService;
use App\Http\Requests\Admin\Integrations\EmailInstitutionPullLookupTokenRequest;
use App\Http\Requests\Admin\Integrations\UpdateClientPullIntegrationRequest;
use App\Mail\InstitutionPullLookupTokenIssuedMail;
use App\Models\InstitutionIntegration;

class AdminInstitutionApiClientsController extends Controller
{
    /**
     * @return array<int,string>
     */
    private function availableAbilities(): array
    {
        return [
            'learner-records:write',
            'learner-records:read',
            'learner-records:lookup',
            'learner-records:batch',
            'learner-records:status',
            'verification-records:lookup',
        ];
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $q = trim((string) $request->query('q', ''));
        $institutionId = $request->query('awarding_institution_id');
        $active = $request->query('is_active');

        $clients = InstitutionApiClient::query()
            ->with(['awardingInstitution:id,name'])
            ->withCount('tokens')
            ->when($institutionId, fn ($qq) => $qq->where('awarding_institution_id', (int) $institutionId))
            ->when(is_string($active) && $active !== '', fn ($qq) => $qq->where('is_active', (bool) ((int) $active)))
            ->when($q !== '', fn ($qq) => $qq->where(function ($w) use ($q) {
                $w->where('name', 'like', "%{$q}%")
                    ->orWhereHas('awardingInstitution', fn ($ai) => $ai->where('name', 'like', "%{$q}%"));
            }))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (InstitutionApiClient $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'awarding_institution' => $c->awardingInstitution ? ['id' => $c->awardingInstitution->id, 'name' => $c->awardingInstitution->name] : null,
                'is_active' => (bool) $c->is_active,
                'scopes' => is_array($c->scopes) ? array_values($c->scopes) : [],
                'tokens_count' => (int) $c->tokens_count,
                'last_used_at' => optional($c->last_used_at)->toIso8601String(),
                'created_at' => optional($c->created_at)->toIso8601String(),
            ]);

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/Integrations/InstitutionApiClients/Index', [
            'clients' => $clients,
            'institutions' => $institutions,
            'filters' => [
                'q' => $q,
                'awarding_institution_id' => is_string($institutionId) ? $institutionId : null,
                'is_active' => is_string($active) ? $active : null,
            ],
            'abilities' => $this->availableAbilities(),
            'can' => [
                'manage' => true,
            ],
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $institutions = AwardingInstitution::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (AwardingInstitution $i) => ['id' => $i->id, 'name' => $i->name])
            ->values();

        return Inertia::render('Admin/Integrations/InstitutionApiClients/Create', [
            'institutions' => $institutions,
            'abilities' => $this->availableAbilities(),
        ]);
    }

    public function store(StoreInstitutionApiClientRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $scopes = is_array($validated['scopes'] ?? null) ? array_values(array_unique(array_filter($validated['scopes']))) : [];
        $allowed = $this->availableAbilities();
        $scopes = array_values(array_intersect($scopes, $allowed));

        $client = InstitutionApiClient::query()->create([
            'awarding_institution_id' => (int) $validated['awarding_institution_id'],
            'name' => (string) $validated['name'],
            'contact_name' => isset($validated['contact_name']) ? (string) $validated['contact_name'] : null,
            'contact_email' => isset($validated['contact_email']) ? (string) $validated['contact_email'] : null,
            'is_active' => (bool) $validated['is_active'],
            'scopes' => $scopes !== [] ? $scopes : $allowed,
            'notes' => isset($validated['notes']) ? (string) $validated['notes'] : null,
            'created_by_user_id' => $request->user()?->id,
        ]);

        return redirect()
            ->route('admin.integrations.institution_api_clients.show', $client)
            ->with('success', 'Institution API client created.');
    }

    public function show(Request $request, InstitutionApiClient $institutionApiClient): Response
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $institutionApiClient->loadMissing(['awardingInstitution.integration']);

        $tokens = $institutionApiClient->tokens()
            ->orderByDesc('id')
            ->get()
            ->map(fn (PersonalAccessToken $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'abilities' => is_array($t->abilities) ? array_values($t->abilities) : [],
                'last_used_at' => optional($t->last_used_at)->toIso8601String(),
                'expires_at' => optional($t->expires_at)->toIso8601String(),
                'created_at' => optional($t->created_at)->toIso8601String(),
            ])
            ->values();

        $pullIntegrationService = app(InstitutionPullIntegrationService::class);
        $awardingInstitution = $institutionApiClient->awardingInstitution;

        return Inertia::render('Admin/Integrations/InstitutionApiClients/Show', [
            'client' => [
                'id' => $institutionApiClient->id,
                'name' => $institutionApiClient->name,
                'contact_name' => $institutionApiClient->contact_name,
                'contact_email' => $institutionApiClient->contact_email,
                'awarding_institution' => $institutionApiClient->awardingInstitution ? [
                    'id' => $institutionApiClient->awardingInstitution->id,
                    'name' => $institutionApiClient->awardingInstitution->name,
                ] : null,
                'is_active' => (bool) $institutionApiClient->is_active,
                'scopes' => is_array($institutionApiClient->scopes) ? array_values($institutionApiClient->scopes) : [],
                'last_used_at' => optional($institutionApiClient->last_used_at)->toIso8601String(),
                'token_last_sent_at' => optional($institutionApiClient->token_last_sent_at)->toIso8601String(),
                'token_rotated_at' => optional($institutionApiClient->token_rotated_at)->toIso8601String(),
                'notes' => $institutionApiClient->notes,
                'revoked_at' => optional($institutionApiClient->revoked_at)->toIso8601String(),
                'created_at' => optional($institutionApiClient->created_at)->toIso8601String(),
                'updated_at' => optional($institutionApiClient->updated_at)->toIso8601String(),
            ],
            'tokens' => $tokens,
            'abilities' => $this->availableAbilities(),
            'flash_token' => $request->session()->get('institution_api_plaintext_token'),
            'flash_token_abilities' => $request->session()->get('institution_api_plaintext_token_abilities'),
            'pull_integration' => $awardingInstitution
                ? $pullIntegrationService->summary($awardingInstitution->integration)
                : $pullIntegrationService->summary(null),
            'pull_integration_urls' => [
                'save' => route('admin.integrations.institution_api_clients.pull_integration.update', $institutionApiClient),
                'generate_token' => route('admin.integrations.institution_api_clients.pull_integration.generate_token', $institutionApiClient),
                'test' => route('admin.integrations.institution_api_clients.pull_integration.test', $institutionApiClient),
                'email_token' => route('admin.integrations.institution_api_clients.pull_integration.email_token', $institutionApiClient),
            ],
            'flash_pull_lookup_token' => $request->session()->get('institution_pull_lookup_plaintext_token'),
        ]);
    }

    public function updatePullIntegration(
        UpdateClientPullIntegrationRequest $request,
        InstitutionApiClient $institutionApiClient,
        InstitutionPullIntegrationService $pullIntegration,
        AuditLogService $audit,
    ): RedirectResponse {
        $institutionApiClient->loadMissing('awardingInstitution');
        $institution = $institutionApiClient->awardingInstitution;
        if (! $institution instanceof AwardingInstitution) {
            return back()->with('error', 'Awarding institution is not configured for this client.');
        }

        $integration = $pullIntegration->savePullSettings($institution, $request->validated());

        $audit->record(
            eventType: 'institution_integration.updated',
            module: 'Integrations',
            actionName: 'institution_pull_integration_updated',
            message: 'Institution pull integration settings updated from API client page.',
            entityType: InstitutionIntegration::class,
            entityId: (int) $integration->id,
            metadata: [
                'awarding_institution_id' => (int) $institution->id,
                'institution_api_client_id' => (int) $institutionApiClient->id,
                'supports_pull' => (bool) $integration->supports_pull,
                'lookup_url_set' => (bool) ($integration->lookup_url),
            ],
        );

        return redirect()
            ->route('admin.integrations.institution_api_clients.show', $institutionApiClient)
            ->with('success', 'Pull lookup integration saved.');
    }

    public function generatePullLookupToken(
        Request $request,
        InstitutionApiClient $institutionApiClient,
        InstitutionPullIntegrationService $pullIntegration,
        AuditLogService $audit,
    ): RedirectResponse {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $institutionApiClient->loadMissing('awardingInstitution');
        $institution = $institutionApiClient->awardingInstitution;
        if (! $institution instanceof AwardingInstitution) {
            return back()->with('error', 'Awarding institution is not configured for this client.');
        }

        $integration = $pullIntegration->resolveIntegration($institution);
        if (! $integration->exists) {
            $integration->save();
        }

        $plainTextToken = $pullIntegration->generateBearerToken($integration);

        $audit->record(
            eventType: 'institution_integration.token_generated',
            module: 'Integrations',
            actionName: 'institution_pull_lookup_token_generated',
            message: 'Institution pull lookup token generated.',
            entityType: InstitutionIntegration::class,
            entityId: (int) $integration->id,
            metadata: [
                'awarding_institution_id' => (int) $institution->id,
                'institution_api_client_id' => (int) $institutionApiClient->id,
            ],
        );

        $request->session()->flash('institution_pull_lookup_plaintext_token', $plainTextToken);

        return redirect()
            ->route('admin.integrations.institution_api_clients.show', $institutionApiClient)
            ->with('success', 'Pull lookup token generated. Copy it now; it will not be shown again.');
    }

    public function testPullIntegration(
        InstitutionApiClient $institutionApiClient,
        InstitutionPullIntegrationService $pullIntegration,
    ): RedirectResponse {
        abort_unless(request()->user()?->can('institution_api.manage'), 403);

        $institutionApiClient->loadMissing('awardingInstitution.integration');
        $integration = $institutionApiClient->awardingInstitution?->integration;
        if (! $integration || ! $integration->lookup_url) {
            return back()->with('error', 'Lookup URL is not configured.');
        }

        $result = $pullIntegration->testConnection($integration);

        return $result['success']
            ? back()->with('success', $result['message'])
            : back()->with('error', $result['message']);
    }

    public function emailPullLookupToken(
        EmailInstitutionPullLookupTokenRequest $request,
        InstitutionApiClient $institutionApiClient,
        AuditLogService $audit,
    ): RedirectResponse {
        if (! $institutionApiClient->contact_email) {
            return back()->with('error', 'Client contact email is not set.');
        }

        $token = (string) $request->validated('token');
        $institutionApiClient->loadMissing('awardingInstitution.integration');
        $lookupUrl = $institutionApiClient->awardingInstitution?->integration?->lookup_url;

        app(OutboundMailService::class)->queue(
            mailable: new InstitutionPullLookupTokenIssuedMail(
                client: $institutionApiClient->loadMissing('awardingInstitution'),
                plainTextToken: $token,
                lookupUrl: is_string($lookupUrl) ? $lookupUrl : null,
            ),
            to: (string) $institutionApiClient->contact_email,
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => (string) $institutionApiClient->contact_email,
                'subject' => 'ZAQA pull lookup token',
                'template_key' => 'institution_pull_lookup_token_issued',
            ],
        );

        $audit->record(
            eventType: 'institution_integration.token_emailed',
            module: 'Integrations',
            actionName: 'institution_pull_lookup_token_emailed',
            message: 'Institution pull lookup token email sent.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $institutionApiClient->id,
            metadata: [
                'awarding_institution_id' => (int) $institutionApiClient->awarding_institution_id,
                'to' => $institutionApiClient->contact_email,
            ],
        );

        return back()->with('success', 'Pull lookup token email sent to institution contact.');
    }

    public function issueToken(IssueInstitutionApiTokenRequest $request, InstitutionApiClient $institutionApiClient): RedirectResponse
    {
        $validated = $request->validated();

        if (! $institutionApiClient->is_active) {
            return back()->with('error', 'Client is disabled.');
        }

        $allowed = is_array($institutionApiClient->scopes) && $institutionApiClient->scopes !== []
            ? array_values($institutionApiClient->scopes)
            : $this->availableAbilities();

        $requested = is_array($validated['abilities'] ?? null) ? array_values($validated['abilities']) : [];
        $abilities = array_values(array_intersect($requested, $allowed));
        if ($abilities === []) {
            return back()->with('error', 'No valid abilities selected for this client.');
        }

        $expiresAt = null;
        if (isset($validated['expires_in_days']) && is_numeric($validated['expires_in_days'])) {
            $expiresAt = now()->addDays((int) $validated['expires_in_days']);
        }

        $newToken = $institutionApiClient->createToken((string) $validated['token_name'], $abilities, $expiresAt);

        // Show the token only once (flash for next request render).
        $request->session()->flash('institution_api_plaintext_token', $newToken->plainTextToken);
        $request->session()->flash('institution_api_plaintext_token_abilities', $abilities);

        app(AuditLogService::class)->record(
            eventType: 'institution_api.token_created',
            module: 'Integrations',
            actionName: 'institution_api_token_created',
            message: 'Institution API token created.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $institutionApiClient->id,
            metadata: [
                'awarding_institution_id' => (int) $institutionApiClient->awarding_institution_id,
                'abilities' => $abilities,
            ],
        );

        return redirect()
            ->route('admin.integrations.institution_api_clients.show', $institutionApiClient)
            ->with('success', 'Token generated. Copy it now; it will not be shown again.');
    }

    public function rotateToken(
        RotateInstitutionApiTokenRequest $request,
        InstitutionApiClient $institutionApiClient,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validated();

        if (! $institutionApiClient->is_active) {
            return back()->with('error', 'Client is disabled.');
        }

        $allowed = is_array($institutionApiClient->scopes) && $institutionApiClient->scopes !== []
            ? array_values($institutionApiClient->scopes)
            : $this->availableAbilities();

        $requested = is_array($validated['abilities'] ?? null) ? array_values($validated['abilities']) : [];
        $abilities = array_values(array_intersect($requested, $allowed));
        if ($abilities === []) {
            return back()->with('error', 'No valid abilities selected for this client.');
        }

        $expiresAt = null;
        if (isset($validated['expires_in_days']) && is_numeric($validated['expires_in_days'])) {
            $expiresAt = now()->addDays((int) $validated['expires_in_days']);
        }

        // Revoke all existing tokens.
        $institutionApiClient->tokens()->delete();

        $newToken = $institutionApiClient->createToken((string) $validated['token_name'], $abilities, $expiresAt);
        $institutionApiClient->forceFill(['token_rotated_at' => now()])->save();

        $audit->record(
            eventType: 'institution_api.token_rotated',
            module: 'Integrations',
            actionName: 'institution_api_token_rotated',
            message: 'Institution API token rotated.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $institutionApiClient->id,
            metadata: [
                'awarding_institution_id' => (int) $institutionApiClient->awarding_institution_id,
                'abilities' => $abilities,
            ],
        );

        $request->session()->flash('institution_api_plaintext_token', $newToken->plainTextToken);
        $request->session()->flash('institution_api_plaintext_token_abilities', $abilities);

        return redirect()
            ->route('admin.integrations.institution_api_clients.show', $institutionApiClient)
            ->with('success', 'Token rotated. Copy it now; it will not be shown again.');
    }

    public function emailLatestToken(
        EmailInstitutionApiTokenRequest $request,
        InstitutionApiClient $institutionApiClient,
        AuditLogService $audit,
    ): RedirectResponse {
        if (! $institutionApiClient->contact_email) {
            return back()->with('error', 'Client contact email is not set.');
        }

        $token = (string) $request->validated('token');
        $abilities = (array) $request->validated('abilities');

        app(OutboundMailService::class)->queue(
            mailable: new InstitutionApiTokenIssuedMail(
                client: $institutionApiClient->loadMissing('awardingInstitution'),
                plainTextToken: $token,
                abilities: $abilities,
            ),
            to: (string) $institutionApiClient->contact_email,
            logContext: [
                'user_id' => null,
                'application_id' => null,
                'email' => (string) $institutionApiClient->contact_email,
                'subject' => 'ZAQA institution API token',
                'template_key' => 'institution_api_token_issued',
            ],
        );

        $institutionApiClient->forceFill(['token_last_sent_at' => now()])->save();

        $audit->record(
            eventType: 'institution_api.token_emailed',
            module: 'Integrations',
            actionName: 'institution_api_token_emailed',
            message: 'Institution API token email sent.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $institutionApiClient->id,
            metadata: [
                'awarding_institution_id' => (int) $institutionApiClient->awarding_institution_id,
                'to' => $institutionApiClient->contact_email,
                'abilities' => $abilities,
            ],
        );

        return back()->with('success', 'Token email sent to institution contact.');
    }

    public function revokeToken(Request $request, InstitutionApiClient $institutionApiClient, int $tokenId): RedirectResponse
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $token = $institutionApiClient->tokens()->whereKey($tokenId)->first();
        if ($token) {
            $token->delete();
        }

        app(AuditLogService::class)->record(
            eventType: 'institution_api.token_revoked',
            module: 'Integrations',
            actionName: 'institution_api_token_revoked',
            message: 'Institution API token revoked.',
            entityType: InstitutionApiClient::class,
            entityId: (int) $institutionApiClient->id,
            metadata: [
                'awarding_institution_id' => (int) $institutionApiClient->awarding_institution_id,
                'token_id' => $tokenId,
            ],
        );

        return back()->with('success', 'Token revoked.');
    }

    public function disable(Request $request, InstitutionApiClient $institutionApiClient): RedirectResponse
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $institutionApiClient->forceFill([
            'is_active' => false,
            'revoked_at' => now(),
            'revoked_by_user_id' => $request->user()?->id,
        ])->save();

        $institutionApiClient->tokens()->delete();

        return back()->with('success', 'Client disabled and all tokens revoked.');
    }

    public function enable(Request $request, InstitutionApiClient $institutionApiClient): RedirectResponse
    {
        abort_unless($request->user()?->can('institution_api.manage'), 403);

        $institutionApiClient->forceFill([
            'is_active' => true,
            'revoked_at' => null,
        ])->save();

        return back()->with('success', 'Client enabled.');
    }
}
