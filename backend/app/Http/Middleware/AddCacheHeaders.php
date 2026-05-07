<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Add HTTP Cache Headers Middleware
 *
 * Adds appropriate cache-control headers for API responses and static assets
 * to improve performance and reduce server load.
 */
class AddCacheHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip if response is not successful
        if (!$response->isSuccessful() && !$response->isRedirection()) {
            return $response;
        }

        // Don't cache authenticated mutable requests
        if ($request->isMethod('POST') || $request->isMethod('PUT') || $request->isMethod('DELETE') || $request->isMethod('PATCH')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, proxy-revalidate');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
            return $response;
        }

        // Cache public/static data endpoints
        if ($request->is('api/packages') || $request->is('api/public/*')) {
            $response->headers->set('Cache-Control', 'public, max-age=300'); // 5 minutes
            return $response;
        }

        // Cache reference data with longer TTL
        if ($request->is('api/settings') || $request->is('api/departments') || $request->is('api/positions')) {
            $response->headers->set('Cache-Control', 'private, max-age=60'); // 1 minute for authenticated
            return $response;
        }

        // Dashboard stats - short cache
        if ($request->is('api/dashboard/stats') || $request->is('api/tenant/dashboard/stats')) {
            $response->headers->set('Cache-Control', 'private, max-age=30'); // 30 seconds
            return $response;
        }

        // Lists with pagination - moderate cache
        if ($request->is('api/users') || $request->is('api/routers') || $request->is('api/vouchers')) {
            $response->headers->set('Cache-Control', 'private, max-age=15'); // 15 seconds
            return $response;
        }

        // Default for API - minimal cache to prevent stale data in real-time system
        if ($request->is('api/*')) {
            $response->headers->set('Cache-Control', 'private, no-cache, must-revalidate');
            return $response;
        }

        return $response;
    }
}
