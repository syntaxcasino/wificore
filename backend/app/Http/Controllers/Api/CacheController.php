<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\CacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CacheController extends Controller
{
    /**
     * Get cache statistics
     */
    public function stats(): JsonResponse
    {
        try {
            $health = CacheService::getHealth();
            
            return response()->json([
                'success' => true,
                'data' => $health,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch cache stats',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Warm up cache
     */
    public function warmup(): JsonResponse
    {
        try {
            $warmed = CacheService::warmUp();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache warmed up successfully',
                'data' => [
                    'items_warmed' => $warmed,
                    'stats' => CacheService::getStats(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to warm up cache',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear all cache
     */
    public function clear(): JsonResponse
    {
        try {
            CacheService::clearAll();
            
            return response()->json([
                'success' => true,
                'message' => 'Cache cleared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear cache by pattern
     */
    public function clearPattern(Request $request): JsonResponse
    {
        $request->validate([
            'pattern' => 'required|string',
        ]);

        try {
            $deleted = CacheService::flushPattern($request->pattern);
            
            return response()->json([
                'success' => true,
                'message' => "Cleared {$deleted} cache entries",
                'data' => [
                    'deleted_count' => $deleted,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear cache pattern',
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
