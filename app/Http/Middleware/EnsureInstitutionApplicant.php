<?php

namespace App\Http\Middleware;

use App\Support\Applications\QualificationHolderIdentityResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureInstitutionApplicant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! QualificationHolderIdentityResolver::applicantIsInstitution($user)) {
            abort(403, 'Multiple Applications is available to institution accounts only.');
        }

        return $next($request);
    }
}
