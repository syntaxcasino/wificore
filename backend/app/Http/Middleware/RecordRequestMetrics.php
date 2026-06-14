<?php

namespace App\Http\Middleware;

use App\Services\MetricsService;
use App\Services\SystemMetricsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RecordRequestMetrics
{
    /**
     * Record rolling API latency metrics without touching long-lived streams.
     * Also increments the TPS (transactions per second) counter.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $start = hrtime(true);
        $response = $next($request);

        if (!$this->shouldRecord($request, $response)) {
            return $response;
        }

        $durationMs = (hrtime(true) - $start) / 1_000_000;
        $routeKey = $request->route()?->getName() ?: $request->path();

        SystemMetricsService::recordResponseTime($durationMs, $routeKey);
        MetricsService::incrementTransactions();

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (!$request->is('api/*')) {
            return false;
        }

        if ($request->is('api/system/sse/*') || $request->is('api/sse/*')) {
            return false;
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type', ''));

        return !str_contains($contentType, 'text/event-stream');
    }
}
