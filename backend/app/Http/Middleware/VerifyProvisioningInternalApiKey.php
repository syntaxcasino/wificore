<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyProvisioningInternalApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $configured = (string) config('services.provisioning.api_key', '');

        if ($configured === '') {
            return response()->json([
                'success' => false,
                'message' => 'Provisioning internal API key is not configured',
            ], 503);
        }

        $provided = (string) $request->header('X-API-Key', '');
        if ($provided === '' || !hash_equals($configured, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        return $next($request);
    }
}
