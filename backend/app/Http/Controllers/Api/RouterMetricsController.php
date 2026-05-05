<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Router;
use App\Services\MikrotikSnmpService;
use App\Services\RouterMetricsService;
use App\Services\TenantContext;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RouterMetricsController extends Controller
{
    public function live(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService, MikrotikSnmpService $snmpService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;

        $batch = $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, [$routerId]);
        $liveData = $batch[$routerId] ?? [];

        // Direct SNMP fallback when VictoriaMetrics+Redis both have nothing
        if (empty($liveData) && $router->snmp_enabled !== false) {
            try {
                $snmpData = $snmpService->fetchLiveData($router);
                if (!empty($snmpData) && ($snmpData['status'] ?? '') !== 'offline') {
                    $liveData = $snmpData;
                    $liveData['source'] = 'snmp_direct';
                    Cache::put("router_live_data_{$routerId}", $liveData, now()->addSeconds(30));
                }
            } catch (\Throwable $e) {
                Log::debug('live() SNMP fallback failed', ['router_id' => $routerId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'success' => true,
            'router_id' => $routerId,
            'live_data' => $liveData,
        ]);
    }

    public function liveBatch(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext, RouterMetricsService $metricsService): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerIds = $request->input('router_ids', []);
        if (!is_array($routerIds)) {
            return response()->json([
                'success' => false,
                'error' => 'router_ids must be an array',
            ], 422);
        }

        $routerIds = array_values(array_filter(array_map('strval', $routerIds), fn ($id) => $id !== ''));
        if (count($routerIds) === 0) {
            return response()->json([
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
            return response()->json([
                'success' => true,
                'live_data' => [],
            ]);
        }

        $live = $metricsService->getLatestRouterMetrics($vm, (string) $tenantId, $allowedIds);

        return response()->json([
            'success' => true,
            'live_data' => $live,
        ]);
    }

    public function trafficRange(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;
        $range = (string) $request->query('range', '1h');
        $step = (string) $request->query('step', '30s');

        // REMOVED: Metrics caching - always fetch fresh data from VictoriaMetrics
        // Caching was serving stale data when routers reconnect

        $now = time();
        $start = $this->rangeStartFromNow($range, $now);

        $selector = sprintf('tenant_id="%s",router_id="%s"', $this->escapeLabelValue((string) $tenantId), $this->escapeLabelValue($routerId));

        $inPrimary = sprintf('sum by (router_id) (rate(interface_ifHCInOctets{%s}[1m]))', $selector);
        $inFallbacks = [
            sprintf('sum by (router_id) (rate(interface_ifInOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifInOctets{%s}[1m]))', $selector),
        ];
        $outPrimary = sprintf('sum by (router_id) (rate(interface_ifHCOutOctets{%s}[1m]))', $selector);
        $outFallbacks = [
            sprintf('sum by (router_id) (rate(interface_ifOutOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifOutOctets{%s}[1m]))', $selector),
        ];

        $in = $this->queryRangeWithFallback($vm, $inPrimary, $inFallbacks, $start, $now, $step);
        $out = $this->queryRangeWithFallback($vm, $outPrimary, $outFallbacks, $start, $now, $step);

        return response()->json([
            'success' => true,
            'router_id' => $routerId,
            'start' => $start,
            'end' => $now,
            'step' => $step,
            'in' => $in,
            'out' => $out,
        ]);
    }

    public function trafficRangeBatch(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $range = (string) $request->query('range', '1h');
        $step = (string) $request->query('step', '30s');
        $now = time();
        $start = $this->rangeStartFromNow($range, $now);

        $selector = sprintf('tenant_id="%s"', $this->escapeLabelValue((string) $tenantId));

        $totalInPrimary = sprintf('sum(rate(interface_ifHCInOctets{%s}[1m]))', $selector);
        $totalInFallbacks = [
            sprintf('sum(rate(interface_ifInOctets{%s}[1m]))', $selector),
            sprintf('sum(rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector),
            sprintf('sum(rate(interface_counters_ifInOctets{%s}[1m]))', $selector),
        ];
        $totalOutPrimary = sprintf('sum(rate(interface_ifHCOutOctets{%s}[1m]))', $selector);
        $totalOutFallbacks = [
            sprintf('sum(rate(interface_ifOutOctets{%s}[1m]))', $selector),
            sprintf('sum(rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector),
            sprintf('sum(rate(interface_counters_ifOutOctets{%s}[1m]))', $selector),
        ];
        $byRouterInPrimary = sprintf('sum by (router_id) (rate(interface_ifHCInOctets{%s}[1m]))', $selector);
        $byRouterInFallbacks = [
            sprintf('sum by (router_id) (rate(interface_ifInOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifInOctets{%s}[1m]))', $selector),
        ];
        $byRouterOutPrimary = sprintf('sum by (router_id) (rate(interface_ifHCOutOctets{%s}[1m]))', $selector);
        $byRouterOutFallbacks = [
            sprintf('sum by (router_id) (rate(interface_ifOutOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector),
            sprintf('sum by (router_id) (rate(interface_counters_ifOutOctets{%s}[1m]))', $selector),
        ];

        $totalIn = $this->queryRangeWithFallback($vm, $totalInPrimary, $totalInFallbacks, $start, $now, $step);
        $totalOut = $this->queryRangeWithFallback($vm, $totalOutPrimary, $totalOutFallbacks, $start, $now, $step);
        $byRouterIn = $this->queryRangeWithFallback($vm, $byRouterInPrimary, $byRouterInFallbacks, $start, $now, $step);
        $byRouterOut = $this->queryRangeWithFallback($vm, $byRouterOutPrimary, $byRouterOutFallbacks, $start, $now, $step);

        return response()->json([
            'success' => true,
            'start' => $start,
            'end' => $now,
            'step' => $step,
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'by_router_in' => $byRouterIn,
            'by_router_out' => $byRouterOut,
        ]);
    }

    public function resourcesRange(Request $request, Router $router, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = (string) $router->id;
        $range = (string) $request->query('range', '1h');
        $step = (string) $request->query('step', '30s');

        // REMOVED: Metrics caching - always fetch fresh data from VictoriaMetrics
        // Caching was serving stale data when routers reconnect

        $now = time();
        $start = $this->rangeStartFromNow($range, $now);

        $selector = sprintf('tenant_id="%s",router_id="%s"', $this->escapeLabelValue((string) $tenantId), $this->escapeLabelValue($routerId));

        $diskType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4)$|hrStorageFixedDisk|HOST-RESOURCES-MIB::hrStorageFixedDisk)';
        $ramType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2)$|hrStorageRam|HOST-RESOURCES-MIB::hrStorageRam)';

        // CPU queries - primary from MikroTik Enterprise MIB, fallback to HOST-RESOURCES
        $cpuPrimary = sprintf('router_health_cpu_load{%s}', $selector);
        $cpuFallbacks = [
            sprintf('avg by (router_id) (cpu_hrProcessorLoad{%s})', $selector),
        ];

        // Memory percentage - calculate from total/free bytes since percentage metric doesn't exist
        $memPrimary = sprintf('100 - ((router_health_free_memory{%s} / router_health_total_memory{%s}) * 100)', $selector, $selector);
        $memFallbacks = [
            // Fallback to HOST-RESOURCES-MIB storage-based calculation
            sprintf('(%s / %s) * 100',
                $this->buildStorageBytesQuery('storage', 'hrStorageUsed', $selector, $ramType),
                $this->buildStorageBytesQuery('storage', 'hrStorageSize', $selector, $ramType)
            ),
            // Second fallback to router_storage prefix
            sprintf('(%s / %s) * 100',
                $this->buildStorageBytesQuery('router_storage', 'hrStorageUsed', $selector, $ramType),
                $this->buildStorageBytesQuery('router_storage', 'hrStorageSize', $selector, $ramType)
            ),
        ];

        // Disk percentage - calculate from storage table
        $diskPrimary = sprintf('(%s / %s) * 100',
            $this->buildStorageBytesQuery('storage', 'hrStorageUsed', $selector, $diskType),
            $this->buildStorageBytesQuery('storage', 'hrStorageSize', $selector, $diskType)
        );
        $diskFallbacks = [
            // Fallback to router_storage prefix
            sprintf('(%s / %s) * 100',
                $this->buildStorageBytesQuery('router_storage', 'hrStorageUsed', $selector, $diskType),
                $this->buildStorageBytesQuery('router_storage', 'hrStorageSize', $selector, $diskType)
            ),
        ];

        $cpu = $this->queryRangeWithFallback($vm, $cpuPrimary, $cpuFallbacks, $start, $now, $step);
        $memory = $this->queryRangeWithFallback($vm, $memPrimary, $memFallbacks, $start, $now, $step);
        $disk = $this->queryRangeWithFallback($vm, $diskPrimary, $diskFallbacks, $start, $now, $step);

        return response()->json([
            'success' => true,
            'router_id' => $routerId,
            'start' => $start,
            'end' => $now,
            'step' => $step,
            'cpu' => $cpu,
            'memory' => $memory,
            'disk' => $disk,
        ]);
    }

    private function queryLatestRouterMetrics(VictoriaMetricsClient $vm, string $tenantId, array $routerIds): array
    {
        if (count($routerIds) === 1) {
            $selector = sprintf(
                'tenant_id="%s",router_id="%s"',
                $this->escapeLabelValue($tenantId),
                $this->escapeLabelValue((string) $routerIds[0])
            );
        } else {
            $routerIdRegex = '^(?:' . implode('|', array_map(fn ($id) => $this->escapeRegexValue((string) $id), $routerIds)) . ')$';
            $selector = sprintf(
                'tenant_id="%s",router_id=~"%s"',
                $this->escapeLabelValue($tenantId),
                $this->escapeLabelValue($routerIdRegex)
            );
        }

        $diskType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4)$|hrStorageFixedDisk|HOST-RESOURCES-MIB::hrStorageFixedDisk)';
        $ramType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2)$|hrStorageRam|HOST-RESOURCES-MIB::hrStorageRam)';

        $queries = [
            'cpu_load' => [
                'primary' => sprintf('router_health_cpu_load{%s}', $selector),
                'fallback' => sprintf('avg by (router_id) (cpu_hrProcessorLoad{%s})', $selector),
            ],
            'total_memory' => sprintf('router_health_total_memory{%s}', $selector),
            'total_memory_kb' => sprintf('router_health_total_memory_kb{%s}', $selector),
            'free_memory' => sprintf('router_health_free_memory{%s}', $selector),
            'uptime_ticks' => sprintf('router_health_uptime_ticks{%s}', $selector),
            'disk_total_bytes' => [
                'primary' => $this->buildStorageBytesQuery('storage', 'hrStorageSize', $selector, $diskType),
                'fallback' => $this->buildStorageBytesQuery('router_storage', 'hrStorageSize', $selector, $diskType),
            ],
            'disk_used_bytes' => [
                'primary' => $this->buildStorageBytesQuery('storage', 'hrStorageUsed', $selector, $diskType),
                'fallback' => $this->buildStorageBytesQuery('router_storage', 'hrStorageUsed', $selector, $diskType),
            ],
            'memory_total_bytes' => [
                'primary' => $this->buildStorageBytesQuery('storage', 'hrStorageSize', $selector, $ramType),
                'fallback' => $this->buildStorageBytesQuery('router_storage', 'hrStorageSize', $selector, $ramType),
            ],
            'memory_used_bytes' => [
                'primary' => $this->buildStorageBytesQuery('storage', 'hrStorageUsed', $selector, $ramType),
                'fallback' => $this->buildStorageBytesQuery('router_storage', 'hrStorageUsed', $selector, $ramType),
            ],
        ];

        $live = [];
        foreach ($routerIds as $routerId) {
            $live[(string) $routerId] = [];
        }

        foreach ($queries as $field => $promql) {
            $primary = is_array($promql) ? $promql['primary'] : $promql;
            $fallback = is_array($promql) ? ($promql['fallback'] ?? null) : null;

            $missing = array_fill_keys($routerIds, true);
            $response = $vm->queryInstant($primary);
            $missing = $this->applyInstantResult($response, $live, $field, $missing);

            if ($fallback && count($missing) > 0) {
                $fallbackResponse = $vm->queryInstant($fallback);
                $this->applyInstantResult($fallbackResponse, $live, $field, $missing);
            }
        }

        foreach ($routerIds as $routerId) {
            $rid = (string) $routerId;
            $diskTotal = $live[$rid]['disk_total_bytes'] ?? null;
            $diskUsed = $live[$rid]['disk_used_bytes'] ?? null;

            if (is_int($diskTotal) && is_int($diskUsed) && $diskTotal >= 0 && $diskUsed >= 0) {
                $free = $diskTotal - $diskUsed;
                if ($free < 0) {
                    $free = 0;
                }

                $live[$rid]['total_hdd_space'] = $diskTotal;
                $live[$rid]['free_hdd_space'] = $free;
            }

            unset($live[$rid]['disk_total_bytes']);
            unset($live[$rid]['disk_used_bytes']);

            $memoryTotal = $live[$rid]['total_memory'] ?? null;
            $memoryFree = $live[$rid]['free_memory'] ?? null;
            $memoryTotalKb = $live[$rid]['total_memory_kb'] ?? null;
            $memoryTotalBytes = $live[$rid]['memory_total_bytes'] ?? null;
            $memoryUsedBytes = $live[$rid]['memory_used_bytes'] ?? null;

            if (is_int($memoryTotal) && $memoryTotal <= 0) {
                $memoryTotal = null;
                unset($live[$rid]['total_memory']);
            }

            if (is_int($memoryFree) && $memoryFree <= 0) {
                $memoryFree = null;
                unset($live[$rid]['free_memory']);
            }

            if ($memoryTotal === null && is_int($memoryTotalBytes) && $memoryTotalBytes >= 0) {
                $memoryTotal = $memoryTotalBytes;
                $live[$rid]['total_memory'] = $memoryTotalBytes;
            }

            if ($memoryTotal === null && is_int($memoryTotalKb) && $memoryTotalKb >= 0) {
                $memoryTotal = $memoryTotalKb * 1024;
                $live[$rid]['total_memory'] = $memoryTotal;
            }

            if ($memoryFree === null && is_int($memoryUsedBytes) && $memoryUsedBytes >= 0) {
                $totalForFree = null;
                if (is_int($memoryTotalBytes) && $memoryTotalBytes >= 0) {
                    $totalForFree = $memoryTotalBytes;
                } elseif (is_int($memoryTotal) && $memoryTotal >= 0) {
                    $totalForFree = $memoryTotal;
                }

                if ($totalForFree !== null) {
                    $free = $totalForFree - $memoryUsedBytes;
                    if ($free < 0) {
                        $free = 0;
                    }
                    $live[$rid]['free_memory'] = $free;
                }
            }

            unset($live[$rid]['total_memory_kb']);
            unset($live[$rid]['memory_total_bytes']);
            unset($live[$rid]['memory_used_bytes']);
        }

        return $live;
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

    private function extractPrometheusValue(array $series): ?int
    {
        $value = $series['value'] ?? null;
        if (!is_array($value) || count($value) < 2) {
            return null;
        }

        $raw = $value[1];
        if ($raw === null || $raw === '') {
            return null;
        }

        if (!is_numeric($raw)) {
            return null;
        }

        return (int) round((float) $raw);
    }

    private function applyInstantResult(array $response, array &$live, string $field, array $missing): array
    {
        $result = (array) (($response['data']['result'] ?? []) ?: []);

        foreach ($result as $series) {
            $labels = (array) ($series['metric'] ?? []);
            $routerId = (string) ($labels['router_id'] ?? '');
            if ($routerId === '' || !array_key_exists($routerId, $live)) {
                continue;
            }

            if (!array_key_exists($routerId, $missing)) {
                continue;
            }

            $value = $this->extractPrometheusValue($series);
            if ($value === null) {
                continue;
            }

            $live[$routerId][$field] = $value;
            unset($missing[$routerId]);
        }

        return $missing;
    }

    private function buildStorageBytesQuery(string $prefix, string $valueField, string $selector, string $storageTypePattern): string
    {
        $allocUnits = sprintf('%s_hrStorageAllocationUnits', $prefix);
        $values = sprintf('%s_%s', $prefix, $valueField);

        return sprintf(
            'max by (tenant_id, router_id) (%s{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left %s{%s,hrStorageType=~"%s"})',
            $allocUnits,
            $selector,
            $storageTypePattern,
            $values,
            $selector,
            $storageTypePattern
        );
    }

    private function queryRangeWithFallback(
        VictoriaMetricsClient $vm,
        string $primary,
        string|array|null $fallback,
        int $start,
        int $end,
        string $step
    ): array
    {
        $response = $vm->queryRange($primary, $start, $end, $step);
        if ($this->hasSeries($response)) {
            return $response;
        }

        if ($fallback === null) {
            return $response;
        }

        $fallbacks = is_array($fallback) ? $fallback : [$fallback];
        foreach ($fallbacks as $fallbackQuery) {
            if (!$fallbackQuery) {
                continue;
            }

            $fallbackResponse = $vm->queryRange($fallbackQuery, $start, $end, $step);
            if ($this->hasSeries($fallbackResponse)) {
                return $fallbackResponse;
            }

            $response = $fallbackResponse;
        }

        return $response;
    }

    private function hasSeries(array $response): bool
    {
        $result = $response['data']['result'] ?? null;
        return is_array($result) && count($result) > 0;
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace([
            "\\",
            '"',
        ], [
            "\\\\",
            '\\"',
        ], $value);
    }

    private function escapeRegexValue(string $value): string
    {
        return preg_replace('/([\\\\.^$|?*+()\[\]{}])/', '\\\\$1', $value) ?? '';
    }
}
