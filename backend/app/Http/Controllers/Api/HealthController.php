<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;

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
    public function ping(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'timestamp' => now()->toIso8601String(),
            'service' => 'WiFi Hotspot Management System'
        ]);
    }
}
