<?php

namespace App\Http\Middleware;

use App\Models\InstitutionApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstitutionApiClient
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();

        if (! $actor instanceof InstitutionApiClient) {
            abort(401, 'Institution API token required.');
        }

        if (! $actor->is_active || $actor->revoked_at) {
            abort(403, 'Institution API client is disabled.');
        }

        $institution = $actor->awardingInstitution;
        if (! $institution || ! $institution->is_active) {
            abort(403, 'Awarding institution is inactive.');
        }

        // Track usage on the client (separate from Sanctum token last_used_at).
        $actor->forceFill(['last_used_at' => now()])->save();

        $request->attributes->set('institution_api_client_id', (int) $actor->id);
        $request->attributes->set('awarding_institution_id', (int) $actor->awarding_institution_id);

        return $next($request);
    }
}

