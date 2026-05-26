<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RouterMetricsService
{
    private const METRICS_CACHE_TTL = 30;

    public function getLatestRouterMetrics(VictoriaMetricsClient $vm, string $tenantId, array $routerIds): array
    {
        $routerIds = array_values(array_filter(array_map('strval', $routerIds), fn ($id) => $id !== ''));
        if (count($routerIds) === 0) {
            return [];
        }

        $live = [];
        foreach ($routerIds as $routerId) {
            $cached = Cache::get("router_live_data_{$routerId}");
            if (is_array($cached) && ! empty($cached) && ! isset($cached['error'])) {
                $cached['source'] = $cached['source'] ?? 'projection';
                $live[(string) $routerId] = $cached;
                continue;
            }

            $live[(string) $routerId] = [];
        }

        return $live;
    }

    public function getProjectedRouterTraffic(string $tenantId, string $routerId, string $range): array
    {
        $series = $this->getTrafficSeries($tenantId, $routerId, $range);
        return [
            'success' => true,
            'router_id' => $routerId,
            'range' => $range,
            'start' => $this->rangeStartFromNow($range, time()),
            'end' => time(),
            'step' => $this->getStepForRange($range),
            'traffic' => $series,
            'source' => 'projection',
        ];
    }

    public function getProjectedRouterResources(string $tenantId, string $routerId, string $range): array
    {
        $bundle = $this->getResourceBundle($tenantId, $routerId, $range);
        return [
            'success' => true,
            'router_id' => $routerId,
            'range' => $range,
            'start' => $this->rangeStartFromNow($range, time()),
            'end' => time(),
            'step' => $this->getStepForRange($range),
            'cpu' => $bundle['cpu'] ?? [],
            'memory' => $bundle['memory'] ?? [],
            'disk' => $bundle['disk'] ?? [],
            'resources' => $bundle,
            'source' => 'projection',
        ];
    }

    public function getProjectedTenantTrafficBatch(string $tenantId, string $range): array
    {
        $routerIds = Router::query()
            ->where('tenant_id', $tenantId)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();

        $byRouterIn = [];
        $byRouterOut = [];
        $totalIn = [];
        $totalOut = [];

        foreach ($routerIds as $routerId) {
            $series = $this->getTrafficSeries($tenantId, $routerId, $range);
            if ($series === []) {
                continue;
            }

            $byRouterIn[$routerId] = $this->buildSingleAxisSeries($series, 'upload');
            $byRouterOut[$routerId] = $this->buildSingleAxisSeries($series, 'download');
            $totalIn = $this->mergeSeries($totalIn, $byRouterIn[$routerId]);
            $totalOut = $this->mergeSeries($totalOut, $byRouterOut[$routerId]);
        }

        return [
            'success' => true,
            'start' => $this->rangeStartFromNow($range, time()),
            'end' => time(),
            'step' => $this->getStepForRange($range),
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'by_router_in' => $byRouterIn,
            'by_router_out' => $byRouterOut,
            'source' => 'projection',
        ];
    }

    private function getTrafficSeries(string $tenantId, string $routerId, string $range): array
    {
        $cached = Cache::get($this->trafficCacheKey($tenantId, $routerId, $range));
        if (! is_array($cached)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($point) {
            if (! is_array($point)) {
                return null;
            }

            $ts = (int) ($point['ts'] ?? 0);
            if ($ts <= 0) {
                return null;
            }

            return [
                'ts' => $ts,
                'upload' => (float) ($point['upload'] ?? 0),
                'download' => (float) ($point['download'] ?? 0),
            ];
        }, $cached)));
    }

    private function getResourceBundle(string $tenantId, string $routerId, string $range): array
    {
        $cached = Cache::get($this->resourceCacheKey($tenantId, $routerId, $range));
        if (! is_array($cached)) {
            return [
                'cpu' => [],
                'memory' => [],
                'disk' => [],
            ];
        }

        return [
            'cpu' => $this->normalizeMetricSeries($cached['cpu'] ?? []),
            'memory' => $this->normalizeMetricSeries($cached['memory'] ?? []),
            'disk' => $this->normalizeMetricSeries($cached['disk'] ?? []),
        ];
    }

    private function normalizeMetricSeries(array $series): array
    {
        return array_values(array_filter(array_map(function ($point) {
            if (! is_array($point)) {
                return null;
            }

            $ts = (int) ($point['ts'] ?? 0);
            if ($ts <= 0) {
                return null;
            }

            return [
                'ts' => $ts,
                'v' => (float) ($point['v'] ?? 0),
            ];
        }, $series)));
    }

    private function buildSingleAxisSeries(array $trafficSeries, string $axis): array
    {
        return array_map(static fn (array $point) => [
            'ts' => (int) $point['ts'],
            'v' => (float) ($point[$axis] ?? 0),
        ], $trafficSeries);
    }

    private function mergeSeries(array $accumulator, array $series): array
    {
        $byTs = [];
        foreach ($accumulator as $point) {
            $byTs[(int) $point['ts']] = ['ts' => (int) $point['ts'], 'v' => (float) ($point['v'] ?? 0)];
        }
        foreach ($series as $point) {
            $ts = (int) ($point['ts'] ?? 0);
            if ($ts <= 0) {
                continue;
            }
            $byTs[$ts] = [
                'ts' => $ts,
                'v' => (float) (($byTs[$ts]['v'] ?? 0) + ($point['v'] ?? 0)),
            ];
        }

        $merged = array_values($byTs);
        usort($merged, fn ($a, $b) => $a['ts'] <=> $b['ts']);
        return $merged;
    }

    private function trafficCacheKey(string $tenantId, string $routerId, string $range): string
    {
        return "router_metrics_{$tenantId}_{$routerId}_traffic_{$range}";
    }

    private function resourceCacheKey(string $tenantId, string $routerId, string $range): string
    {
        return "router_metrics_{$tenantId}_{$routerId}_resources_{$range}";
    }

    private function getStepForRange(string $range): string
    {
        return match (true) {
            str_ends_with($range, 'm') => '15s',
            str_ends_with($range, 'h') => '30s',
            str_ends_with($range, 'd') => '5m',
            default => '30s',
        };
    }

    private function rangeStartFromNow(string $range, int $now): int
    {
        $range = trim(strtolower($range));

        return match (true) {
            str_ends_with($range, 'm') => max(0, $now - ((int) rtrim($range, 'm')) * 60),
            str_ends_with($range, 'h') => max(0, $now - ((int) rtrim($range, 'h')) * 3600),
            str_ends_with($range, 'd') => max(0, $now - ((int) rtrim($range, 'd')) * 86400),
            default => max(0, $now - 3600),
        };
    }
}
