<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DDoSProtection
{
    /**
     * Aggressive rate limiting for DDoS protection
     * Blocks IPs that make too many requests in a short time
     */
    public function handle(Request $request, Closure $next): Response
    {
        $ip = $request->ip();
        $blockKey = "ddos:blocked:{$ip}";
        $requestKey = "ddos:requests:{$ip}";
        
        // Skip DDoS protection for authenticated users (they have their own rate limiting)
        if ($request->user()) {
            return $next($request);
        }
        
        // Check if IP is already blocked
        if (Cache::has($blockKey)) {
            // Get the actual expiration time from cache
            $blockedUntil = Cache::get($blockKey);
            $retryAfter = is_numeric($blockedUntil) 
                ? max(0, $blockedUntil - now()->timestamp)
                : 900;
            
            Log::warning('DDoS: Blocked IP attempted access', [
                'ip' => $ip,
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'retry_after' => $retryAfter
            ]);
            
            return response()->json([
                'message' => 'Access denied. Your IP has been temporarily blocked due to suspicious activity.',
                'retry_after' => $retryAfter,
                'blocked_until' => date('Y-m-d H:i:s', $blockedUntil ?: now()->addMinutes(15)->timestamp)
            ], 403);
        }
        
        // Track requests per minute
        $requests = Cache::get($requestKey, []);
        $now = now()->timestamp;
        
        // Remove requests older than 1 minute
        $requests = array_filter($requests, function($timestamp) use ($now) {
            return ($now - $timestamp) < 60;
        });
        
        // Add current request
        $requests[] = $now;
        Cache::put($requestKey, $requests, now()->addMinutes(2));
        
        // Check if threshold exceeded (100 requests per minute)
        if (count($requests) > 100) {
            // Block IP for 15 minutes
            $blockedUntil = now()->addMinutes(15)->timestamp;
            Cache::put($blockKey, $blockedUntil, now()->addMinutes(15));
            
            Log::alert('DDoS: IP blocked for excessive requests', [
                'ip' => $ip,
                'requests_per_minute' => count($requests),
                'path' => $request->path(),
                'user_agent' => $request->userAgent(),
                'blocked_until' => now()->addMinutes(15)->toDateTimeString()
            ]);
            
            return response()->json([
                'message' => 'Access denied. Your IP has been temporarily blocked due to excessive requests.',
                'retry_after' => 900,
                'blocked_until' => date('Y-m-d H:i:s', $blockedUntil)
            ], 403);
        }
        
        // Check for suspicious patterns (rapid-fire requests)
        if (count($requests) >= 20) {
            $recentRequests = array_slice($requests, -20);
            $timeSpan = end($recentRequests) - reset($recentRequests);
            
            // If 20 requests in less than 5 seconds, it's suspicious
            if ($timeSpan < 5) {
                $blockedUntil = now()->addMinutes(15)->timestamp;
                Cache::put($blockKey, $blockedUntil, now()->addMinutes(15));
                
                Log::alert('DDoS: IP blocked for rapid-fire requests', [
                    'ip' => $ip,
                    'requests' => 20,
                    'time_span' => $timeSpan . 's',
                    'blocked_until' => now()->addMinutes(15)->toDateTimeString()
                ]);
                
                return response()->json([
                    'message' => 'Access denied. Suspicious activity detected.',
                    'retry_after' => 900,
                    'blocked_until' => date('Y-m-d H:i:s', $blockedUntil)
                ], 403);
            }
        }
        
        return $next($request);
    }
}
