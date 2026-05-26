<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ForceSanctumBearerToken
{
    /**
     * For institution integration endpoints, we want bearer tokens to take precedence
     * over any existing session-authenticated `web` user.
     *
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        config(['sanctum.guard' => []]);

        return $next($request);
    }
}

