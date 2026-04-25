<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class EnsureCorrelationId
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $request->headers->get('X-Request-Id')
            ?: $request->headers->get('X-Correlation-Id');

        if (! is_string($correlationId) || $correlationId === '' || strlen($correlationId) > 64) {
            $correlationId = (string) Str::uuid();
        }

        $request->attributes->set('correlation_id', $correlationId);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $correlationId);

        return $response;
    }
}

