<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AuthenticateSseToken
 *
 * EventSource (SSE) cannot set custom headers, so the Bearer token must be
 * passed as a query parameter (?token=...). This middleware promotes the
 * query param to the Authorization header before Sanctum's auth guard runs,
 * so auth:sanctum works normally downstream.
 *
 * SECURITY: Only active on SSE routes. The token is never logged or stored.
 */
class AuthenticateSseToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->query('token');

        if ($token && !$request->bearerToken()) {
            $request->headers->set('Authorization', 'Bearer ' . $token);
        }

        return $next($request);
    }
}
