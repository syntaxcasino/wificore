<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class VerifyWireGuardWebhookSignature
{
    private const MAX_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.wireguard.webhook_hmac_secret', '');
        if ($secret === '') {
            Log::critical('WireGuard webhook HMAC secret is not configured');
            return response()->json([
                'success' => false,
                'message' => 'WireGuard webhook secret is not configured',
            ], 503);
        }

        $timestamp = $request->header('X-WireGuard-Timestamp');
        $signatureHeader = (string) $request->header('X-WireGuard-Signature', '');

        if (!$timestamp || $signatureHeader === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing webhook signature headers',
            ], 401);
        }

        if (!ctype_digit((string) $timestamp)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook timestamp',
            ], 401);
        }

        $ts = (int) $timestamp;
        if (abs(time() - $ts) > self::MAX_SKEW_SECONDS) {
            return response()->json([
                'success' => false,
                'message' => 'Webhook timestamp is outside allowed window',
            ], 401);
        }

        $provided = $this->normalizeSignature($signatureHeader);
        if ($provided === null) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature format',
            ], 401);
        }

        $payload = $timestamp . "\n" . $request->getContent();
        $expected = hash_hmac('sha256', $payload, $secret);

        if (!hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid webhook signature',
            ], 401);
        }

        return $next($request);
    }

    private function normalizeSignature(string $signature): ?string
    {
        $signature = trim($signature);
        if (str_starts_with($signature, 'sha256=')) {
            $signature = substr($signature, 7);
        }

        if ($signature === '' || !ctype_xdigit($signature)) {
            return null;
        }

        return strtolower($signature);
    }
}
