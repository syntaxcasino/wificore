<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MetricsController extends Controller
{
    /**
     * Get performance metrics (TPS, OPS, etc.)
     */
    public function index(): JsonResponse
    {
        try {
            $metrics = MetricsService::getPerformanceMetrics();
            
            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch performance metrics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get TPS (Transactions Per Second) only
     */
    public function tps(): JsonResponse
    {
        try {
            $tps = MetricsService::calculateTPS();
            $history = MetricsService::getTPSHistory();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $tps,
                    'history' => $history,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch TPS',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get OPS (Operations Per Second) only
     */
    public function ops(): JsonResponse
    {
        try {
            $ops = MetricsService::getRedisOPS();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'current' => $ops,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch OPS',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get historical metrics
     * Query params: period (1h, 6h, 24h, 7d, 30d) or start_date & end_date
     */
    public function historical(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '1h');
            $startDate = $request->query('start_date');
            $endDate = $request->query('end_date');

            $metrics = MetricsService::getHistoricalMetrics($period, $startDate, $endDate);
            
            return response()->json([
                'success' => true,
                'data' => $metrics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch historical metrics',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get metrics summary for a period
     */
    public function summary(Request $request): JsonResponse
    {
        try {
            $period = $request->query('period', '24h');
            $summary = MetricsService::getMetricsSummary($period);
            
            return response()->json([
                'success' => true,
                'data' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch metrics summary',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get current metrics with layout format
     */
    public function layout(): JsonResponse
    {
        try {
            $metrics = MetricsService::getPerformanceMetrics();
            $dbMetrics = $metrics['database'];
            
            // Get max connections from database config
            $maxConnections = config('database.connections.pgsql.pool.max_connections', 200);
            
            return response()->json([
                'success' => true,
                'data' => [
                    'tps' => [
                        'current' => $metrics['tps']['current'],
                        'average' => $metrics['tps']['average'],
                        'display' => number_format($metrics['tps']['current'], 2) . ' (Avg: ' . number_format($metrics['tps']['average'], 2) . ')',
                    ],
                    'ops' => [
                        'current' => $metrics['ops']['current'],
                        'display' => number_format($metrics['ops']['current'], 2),
                    ],
                    'db_connections' => [
                        'current' => $dbMetrics['active_connections'],
                        'max' => $maxConnections,
                        'display' => $dbMetrics['active_connections'] . '/' . $maxConnections,
                    ],
                    'slow_queries' => [
                        'count' => $dbMetrics['slow_queries'],
                        'display' => (string)$dbMetrics['slow_queries'],
                    ],
                    'timestamp' => $metrics['timestamp'],
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch metrics layout',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
