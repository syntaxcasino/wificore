<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\RouterMetricsService;
use App\Services\TenantContext;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RouterMetricsController extends Controller
{
    public function live(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;
        $batch = $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, [$routerId]);
        $liveData = $batch[$routerId] ?? [];

        if (! empty($liveData)) {
            $liveData['source'] = $liveData['source'] ?? 'projection';
        }

        return $this->noCacheJson([
            'success' => true,
            'router_id' => $routerId,
            'live_data' => $liveData,
            'is_stale' => empty($liveData),
        ]);
    }

    public function liveBatch(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerIds = $request->input('router_ids', []);
        if (! is_array($routerIds)) {
            return response()->json([
                'success' => false,
                'error' => 'router_ids must be an array',
            ], 422);
        }

        $routerIds = array_values(array_filter(array_map('strval', $routerIds), fn ($id) => $id !== ''));
        if (count($routerIds) === 0) {
            return $this->noCacheJson([
                'success' => true,
                'live_data' => [],
            ]);
        }

        $allowedIds = Router::query()
            ->whereIn('id', $routerIds)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        if (count($allowedIds) === 0) {
            return $this->noCacheJson([
                'success' => true,
                'live_data' => [],
            ]);
        }

        $live = $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, $allowedIds);

        return $this->noCacheJson([
            'success' => true,
            'live_data' => $live,
            'source' => 'projection',
        ]);
    }

    public function trafficRange(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;
        $range = (string) $request->query('range', '1h');
        $projection = $metricsService->getProjectedRouterTraffic((string) $tenantId, $routerId, $range);

        $traffic = $projection['traffic'] ?? [];
        $in = array_map(static fn (array $point) => [
            'ts' => (int) ($point['ts'] ?? 0),
            'v' => (float) ($point['upload'] ?? 0),
        ], $traffic);
        $out = array_map(static fn (array $point) => [
            'ts' => (int) ($point['ts'] ?? 0),
            'v' => (float) ($point['download'] ?? 0),
        ], $traffic);

        return $this->noCacheJson([
            'success' => true,
            'router_id' => $routerId,
            'range' => $range,
            'start' => $projection['start'] ?? null,
            'end' => $projection['end'] ?? null,
            'step' => $projection['step'] ?? null,
            'in' => $in,
            'out' => $out,
            'traffic' => $traffic,
            'source' => $projection['source'] ?? 'projection',
        ]);
    }

    public function trafficRangeBatch(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $range = (string) $request->query('range', '1h');
        $projection = $metricsService->getProjectedTenantTrafficBatch((string) $tenantId, $range);

        return $this->noCacheJson($projection);
    }

    public function resourcesRange(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (! $tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;
        $range = (string) $request->query('range', '1h');
        $projection = $metricsService->getProjectedRouterResources((string) $tenantId, $routerId, $range);

        return $this->noCacheJson($projection);
    }

    private function noCacheJson(mixed $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }
}
