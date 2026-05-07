<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PppoeUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * PPPoE Portal Authentication Middleware
 * 
 * Validates PPPoE portal tokens and loads the PPPoE user
 * into the request context. Designed to be tenant-agnostic.
 */
class PppoePortalAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->extractToken($request);

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required',
            ], 401);
        }

        // Validate token and find PPPoE user
        $pppoeUser = $this->validateToken($token);

        if (!$pppoeUser) {
            Log::warning('Invalid PPPoE portal token', [
                'ip' => $request->ip(),
                'token_preview' => substr($token, 0, 10) . '...',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid or expired token',
            ], 401);
        }

        // Attach PPPoE user to request for downstream use
        $request->attributes->set('pppoe_user', $pppoeUser);
        
        // Set tenant context for proper data isolation
        $request->attributes->set('tenant_id', $pppoeUser->tenant_id);

        return $next($request);
    }

    /**
     * Extract token from request header or query parameter
     */
    private function extractToken(Request $request): ?string
    {
        // Check Authorization header first
        $header = $request->header('Authorization');
        if ($header && str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }

        // Fallback to query parameter (for SSE/EventSource)
        return $request->query('portal_token');
    }

    /**
     * Validate portal token and return PPPoE user if valid
     */
    private function validateToken(string $token): ?PppoeUser
    {
        // Token format: base64(user_id|timestamp|signature)
        $decoded = base64_decode($token, true);
        if (!$decoded) {
            return null;
        }

        $parts = explode('|', $decoded);
        if (count($parts) !== 3) {
            return null;
        }

        [$userId, $timestamp, $signature] = $parts;

        // Check token expiration (24 hours)
        if (time() - (int)$timestamp > 86400) {
            return null;
        }

        // Find PPPoE user
        $pppoeUser = PppoeUser::find($userId);
        if (!$pppoeUser || !$pppoeUser->portal_password) {
            return null;
        }

        // Verify signature
        $expectedSignature = hash_hmac('sha256', $userId . '|' . $timestamp, $pppoeUser->portal_password);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $pppoeUser;
    }
}
