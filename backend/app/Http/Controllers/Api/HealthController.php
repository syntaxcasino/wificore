<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    protected HealthCheckService $healthCheckService;
    
    public function __construct(HealthCheckService $healthCheckService)
    {
        $this->healthCheckService = $healthCheckService;
    }
    
    /**
     * Get complete system health status
     * 
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $health = $this->healthCheckService->getSystemHealth();
        
        return response()->json($health);
    }
    
    /**
     * Get router health status
     * 
     * @return JsonResponse
     */
    public function routers(): JsonResponse
    {
        $routerHealth = $this->healthCheckService->getRouterHealth();
        
        return response()->json([
            'status' => 'success',
            'data' => $routerHealth
        ]);
    }
    
    /**
     * Get database health status
     * 
     * @return JsonResponse
     */
    public function database(): JsonResponse
    {
        $databaseHealth = $this->healthCheckService->getDatabaseHealth();
        
        return response()->json([
            'status' => 'success',
            'data' => $databaseHealth
        ]);
    }
    
    /**
     * Get security health status
     * 
     * @return JsonResponse
     */
    public function security(): JsonResponse
    {
        $securityHealth = $this->healthCheckService->getSecurityHealth();
        
        return response()->json([
            'status' => 'success',
            'data' => $securityHealth
        ]);
    }
    
    /**
     * Get quick health status (for monitoring/uptime checks)
     * 
     * @return JsonResponse
     */
    public function ping(Request $request): JsonResponse
    {
        $startedAt = microtime(true);
        $requestStartedAt = (float) $request->server('REQUEST_TIME_FLOAT', $startedAt);
        $includeTimings = $request->boolean('include_timings')
            || (bool) config('app.debug')
            || filter_var(env('HEALTH_PING_INCLUDE_TIMINGS', false), FILTER_VALIDATE_BOOL);
        $runDeepChecks = $request->boolean('deep')
            || filter_var(env('HEALTH_PING_DEEP_CHECKS', false), FILTER_VALIDATE_BOOL);
        $slowLogThresholdMs = (float) env('HEALTH_PING_SLOW_LOG_MS', 500);

        $timingsMs = [];
        $checks = [];

        if ($runDeepChecks) {
            $dbStartedAt = microtime(true);
            DB::connection()->select('select 1');
            $timingsMs['db_probe'] = round((microtime(true) - $dbStartedAt) * 1000, 3);
            $checks['database'] = 'ok';

            $redisStartedAt = microtime(true);
            $redisResult = Redis::connection('cache')->command('PING');
            $timingsMs['redis_probe'] = round((microtime(true) - $redisStartedAt) * 1000, 3);
            $checks['redis'] = is_string($redisResult) ? strtolower($redisResult) : 'ok';
        }

        $timingsMs['controller'] = round((microtime(true) - $startedAt) * 1000, 3);
        $timingsMs['full_request'] = round((microtime(true) - $requestStartedAt) * 1000, 3);

        if ($timingsMs['full_request'] >= $slowLogThresholdMs) {
            Log::warning('Health ping slow request detected', [
                'path' => $request->path(),
                'query' => $request->query(),
                'timings_ms' => $timingsMs,
                'deep_checks' => $runDeepChecks,
            ]);
        }

        $payload = [
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'service' => 'WiFi Hotspot Management System',
        ];

        if ($includeTimings) {
            $payload['timings_ms'] = $timingsMs;
        }

        if (! empty($checks)) {
            $payload['checks'] = $checks;
        }

        return response()->json($payload);
    }
}
