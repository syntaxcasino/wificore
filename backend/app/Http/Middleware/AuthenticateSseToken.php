<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
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
        $existingBearer = $request->bearerToken();

        Log::debug('SseAuthMiddleware: Processing request', [
            'has_token_param' => !empty($token),
            'has_bearer_header' => !empty($existingBearer),
            'url' => $request->url(),
            'path' => $request->path(),
            'method' => $request->method(),
        ]);

        // Only set from query param if no Authorization header already present
        if ($token && !$existingBearer) {
            $request->headers->set('Authorization', 'Bearer ' . $token);

            // Flush the auth guard cache. Global middleware (e.g. EnforceSubdomainTenantBinding)
            // may have already called Auth::check() before this middleware ran, causing
            // Sanctum's RequestGuard to cache "no user". Forgetting guards forces
            // auth:sanctum downstream to re-resolve from the newly-set Authorization header.
            Auth::forgetGuards();

            Log::debug('SseAuthMiddleware: Set Authorization header from query param', [
                'token_prefix' => substr($token, 0, 20) . '...',
            ]);
        }

        $response = $next($request);

        // Log 401 responses for debugging
        if ($response->getStatusCode() === 401) {
            Log::warning('SseAuthMiddleware: Request returned 401', [
                'auth_header_set' => !empty($request->header('Authorization')),
                'user_authenticated' => auth()->check(),
                'user_id' => auth()->id(),
                'token_from_query' => !empty($token),
                'token_from_header' => !empty($existingBearer),
            ]);
        }

        return $response;
    }
}
