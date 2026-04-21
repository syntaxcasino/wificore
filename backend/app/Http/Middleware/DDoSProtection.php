<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class DDoSProtection
{
    /**
     * Rate limiting for DDoS protection — applies to ALL requests (auth or not).
     *
     * GAP-08: Removed early-return for authenticated users; a stolen/brute-forced
     *         token must not bypass rate limiting.
     * GAP-09: Replaced O(n) PHP-array-in-Redis with atomic Redis INCR + EXPIRE
     *         sliding window (O(1) per request, no serialisation overhead).
     *
     * Thresholds:
     *   - 120 requests / 60 s  → block for 30 s (sustained flood)
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $blockKey = "ddos:blocked:{$ip}";

        // ── Check existing block ────────────────────────────────────────────
        if (Cache::has($blockKey)) {
            $blockedUntil = Cache::get($blockKey);
            $retryAfter = is_numeric($blockedUntil)
                ? max(0, $blockedUntil - now()->timestamp)
                : 30;

            Log::warning('DDoS: Blocked IP attempted access', [
                'ip' => $ip,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'retry_after' => $retryAfter,
            ]);

            return response()->json([
                'message' => 'Access denied. Your IP has been temporarily blocked due to suspicious activity.',
                'retry_after' => $retryAfter,
                'blocked_until' => date('Y-m-d H:i:s', $blockedUntil ?: now()->addSeconds(30)->timestamp),
            ], 403);
        }

        // ── Atomic sliding-window counter (GAP-09) ──────────────────────────
        // Uses Redis INCR so the operation is O(1) and race-condition free.
        $countKey = "ddos:count:{$ip}";
        try {
            $redis = Redis::connection()->client();
            $count = $redis->incr($countKey);
            if ($count === 1) {
                $redis->expire($countKey, 60); // 60-second window
            }
        } catch (\Throwable $e) {
            // Redis unavailable — fail open rather than blocking all traffic
            Log::warning('DDoS: Redis counter unavailable, skipping check', ['error' => $e->getMessage()]);
            return $next($request);
        }

        // ── Enforce threshold ───────────────────────────────────────────────
        if ($count > 120) {
            $blockedUntil = now()->addSeconds(30)->timestamp;
            Cache::put($blockKey, $blockedUntil, 30);

            Log::alert('DDoS: IP blocked for excessive requests', [
                'ip' => $ip,
                'requests_per_minute' => $count,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'blocked_until' => date('Y-m-d H:i:s', $blockedUntil),
            ]);

            return response()->json([
                'message' => 'Access denied. Your IP has been temporarily blocked due to excessive requests.',
                'retry_after' => 30,
                'blocked_until' => date('Y-m-d H:i:s', $blockedUntil),
            ], 403);
        }

        return $next($request);
    }
}
