<?php

namespace App\Jobs;

use App\Events\RouterMetricsUpdated;
use App\Models\Router;
use App\Models\Tenant;
use App\Services\RouterMetricsService;
use App\Services\VictoriaMetricsClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ComputeRouterMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 120;
    public $tries = 3;
    public $backoff = [10, 30, 60];
    public $deleteWhenMissingModels = true;

    public ?array $routerIds;
    public array $timeRanges;

    /**
     * Create a new job instance.
     *
     * @param string|null $tenantId - If null, processes all active tenants
     * @param array|null $routerIds - If null, processes all routers for the tenant
     * @param array $timeRanges - Time ranges to compute (default: common ranges)
     */
    public function __construct(
        ?string $tenantId = null,
        ?array $routerIds = null,
        array $timeRanges = ['15m', '1h', '6h', '24h']
    ) {
        $this->tenantId = $tenantId;
        $this->routerIds = $routerIds;
        $this->timeRanges = $timeRanges;
        $this->onQueue('metrics');
    }

    /**
     * Execute the job.
     */
    public function handle(RouterMetricsService $metricsService, VictoriaMetricsClient $vm): void
    {
        // If no tenant ID, dispatch jobs for all active tenants
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id, null, $this->timeRanges);
            }
            Log::info('Dispatched metrics computation for all tenants', [
                'tenant_count' => $tenants->count()
            ]);
            return;
        }

        $this->executeInTenantContext(function() use ($metricsService, $vm) {
            try {
                $startTime = microtime(true);

                // Get routers to process
                if ($this->routerIds) {
                    $routers = Router::whereIn('id', $this->routerIds)
                        ->whereNotIn('status', ['pending', 'deploying', 'provisioning'])
                        ->get();
                } else {
                    $routers = Router::whereNotIn('status', ['pending', 'deploying', 'provisioning'])
                        ->get();
                }

                if ($routers->isEmpty()) {
                    Log::debug('No routers to compute metrics for', [
                        'tenant_id' => $this->tenantId
                    ]);
                    return;
                }

                $routerIds = $routers->pluck('id')->toArray();
                $processed = 0;
                $errors = 0;

                // Process each time range
                foreach ($this->timeRanges as $timeRange) {
                    try {
                        $step = $this->getStepForRange($timeRange);
                        $now = time();
                        $start = $this->rangeStartFromNow($timeRange, $now);

                        // Clear old cache to force fresh data
                        foreach ($routerIds as $routerId) {
                            $cacheKey = "router:{$routerId}:metrics:{$timeRange}";
                            Cache::forget($cacheKey);
                        }

                        // Fetch traffic metrics for all routers
                        $trafficMetrics = $this->fetchBatchTrafficMetrics($vm, $routerIds, $start, $now, $step);

                        // Fetch resource metrics for all routers
                        $resourceMetrics = $this->fetchBatchResourceMetrics($vm, $routerIds, $start, $now, $step);

                        Log::info('Fetched batch metrics', [
                            'time_range' => $timeRange,
                            'router_count' => count($routerIds),
                            'cpu_samples' => count($resourceMetrics[$routerIds[0]]['cpu'] ?? []),
                            'memory_samples' => count($resourceMetrics[$routerIds[0]]['memory'] ?? []),
                            'disk_samples' => count($resourceMetrics[$routerIds[0]]['disk'] ?? []),
                        ]);

                        // Process each router
                        foreach ($routerIds as $routerId) {
                            try {
                                $trafficData = $trafficMetrics[$routerId] ?? [];
                                $resourceData = $resourceMetrics[$routerId] ?? [];

                                // Cache the computed data
                                $cacheKey = "router:{$routerId}:metrics:{$timeRange}";
                                $cachedData = [
                                    'traffic' => $trafficData,
                                    'resources' => $resourceData,
                                    'computed_at' => now()->toIso8601String(),
                                    'time_range' => $timeRange,
                                ];
                                Cache::put($cacheKey, $cachedData, now()->addSeconds(30));

                                // Broadcast event for real-time updates
                                if (!empty($trafficData)) {
                                    broadcast(new RouterMetricsUpdated(
                                        $this->tenantId,
                                        (string) $routerId,
                                        $trafficData,
                                        'traffic',
                                        $timeRange
                                    ));
                                }

                                if (!empty($resourceData)) {
                                    broadcast(new RouterMetricsUpdated(
                                        $this->tenantId,
                                        (string) $routerId,
                                        $resourceData,
                                        'resources',
                                        $timeRange
                                    ));
                                }

                                $processed++;
                            } catch (\Exception $e) {
                                $errors++;
                                Log::warning('Failed to process router metrics', [
                                    'router_id' => $routerId,
                                    'time_range' => $timeRange,
                                    'error' => $e->getMessage()
                                ]);
                            }
                        }
                    } catch (\Exception $e) {
                        Log::error('Failed to compute metrics for time range', [
                            'time_range' => $timeRange,
                            'error' => $e->getMessage()
                        ]);
                    }
                }

                $duration = round(microtime(true) - $startTime, 2);
                Log::info('Router metrics computation completed', [
                    'tenant_id' => $this->tenantId,
                    'router_count' => count($routerIds),
                    'time_ranges' => $this->timeRanges,
                    'processed' => $processed,
                    'errors' => $errors,
                    'duration_seconds' => $duration
                ]);

            } catch (\Exception $e) {
                Log::error('Metrics computation job failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                throw $e;
            }
        });
    }

    /**
     * Fetch traffic metrics for multiple routers in batch
     */
    private function fetchBatchTrafficMetrics(VictoriaMetricsClient $vm, array $routerIds, int $start, int $end, string $step): array
    {
        $results = [];
        $routerIdRegex = '^(?:' . implode('|', array_map('preg_quote', $routerIds)) . ')$';

        $selector = sprintf(
            'tenant_id="%s",router_id=~"%s"',
            $this->escapeLabelValue((string) $this->tenantId),
            $this->escapeLabelValue($routerIdRegex)
        );

        // Query for traffic in (upload)
        $inQuery = sprintf('sum by (router_id) (rate(interface_ifHCInOctets{%s}[1m]))', $selector);
        $inResponse = $vm->queryRange($inQuery, $start, $end, $step);

        // Query for traffic out (download)
        $outQuery = sprintf('sum by (router_id) (rate(interface_ifHCOutOctets{%s}[1m]))', $selector);
        $outResponse = $vm->queryRange($outQuery, $start, $end, $step);

        // Process results and group by router
        $inResults = $this->extractRouterSeriesData($inResponse);
        $outResults = $this->extractRouterSeriesData($outResponse);

        foreach ($routerIds as $routerId) {
            $inData = $inResults[$routerId] ?? [];
            $outData = $outResults[$routerId] ?? [];

            // Combine and align by timestamp
            $results[$routerId] = $this->alignTrafficData($inData, $outData);
        }

        return $results;
    }

    /**
     * Fetch resource metrics for multiple routers in batch
     */
    private function fetchBatchResourceMetrics(VictoriaMetricsClient $vm, array $routerIds, int $start, int $end, string $step): array
    {
        $results = [];
        $routerIdRegex = '^(?:' . implode('|', array_map('preg_quote', $routerIds)) . ')$';

        $selector = sprintf(
            'tenant_id="%s",router_id=~"%s"',
            $this->escapeLabelValue((string) $this->tenantId),
            $this->escapeLabelValue($routerIdRegex)
        );

        // CPU query - primary from MikroTik Enterprise MIB
        $cpuQuery = sprintf('router_health_cpu_load{%s}', $selector);
        $cpuResponse = $vm->queryRange($cpuQuery, $start, $end, $step);
        
        // Log CPU query result
        $cpuResultCount = count($cpuResponse['data']['result'] ?? []);
        Log::debug('CPU query result', ['query' => $cpuQuery, 'series_count' => $cpuResultCount]);
        
        // Fallback to HOST-RESOURCES CPU if primary is empty
        if ($cpuResultCount === 0) {
            $cpuFallbackQuery = sprintf('avg by (tenant_id, router_id) (cpu_hrProcessorLoad{%s})', $selector);
            $cpuResponse = $vm->queryRange($cpuFallbackQuery, $start, $end, $step);
            Log::debug('CPU fallback query result', ['query' => $cpuFallbackQuery, 'series_count' => count($cpuResponse['data']['result'] ?? [])]);
        }
        $cpuResults = $this->extractRouterSeriesData($cpuResponse);

        // Memory query - calculate percentage from free/total bytes
        $memQuery = sprintf(
            '100 - ((router_health_free_memory{%s} / router_health_total_memory{%s}) * 100)',
            $selector, $selector
        );
        $memResponse = $vm->queryRange($memQuery, $start, $end, $step);
        
        $memResultCount = count($memResponse['data']['result'] ?? []);
        Log::debug('Memory query result', ['query' => $memQuery, 'series_count' => $memResultCount]);
        
        // Fallback to HOST-RESOURCES storage-based memory if primary is empty
        if ($memResultCount === 0) {
            $ramType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]2)$|hrStorageRam|HOST-RESOURCES-MIB::hrStorageRam)';
            $memFallbackQuery = sprintf(
                '(max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageUsed{%s,hrStorageType=~"%s"}) / ' .
                'max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageSize{%s,hrStorageType=~"%s"})) * 100',
                $selector, $ramType, $selector, $ramType,
                $selector, $ramType, $selector, $ramType
            );
            $memResponse = $vm->queryRange($memFallbackQuery, $start, $end, $step);
            Log::debug('Memory fallback query result', ['query' => $memFallbackQuery, 'series_count' => count($memResponse['data']['result'] ?? [])]);
        }
        $memResults = $this->extractRouterSeriesData($memResponse);

        // Disk query - calculate percentage from storage metrics
        $diskType = '(^([.]?1[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4|iso[.]3[.]6[.]1[.]2[.]1[.]25[.]2[.]1[.]4)$|hrStorageFixedDisk|HOST-RESOURCES-MIB::hrStorageFixedDisk)';
        $diskQuery = sprintf(
            '(max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageUsed{%s,hrStorageType=~"%s"}) / ' .
            'max by (tenant_id, router_id) (storage_hrStorageAllocationUnits{%s,hrStorageType=~"%s"} * on (tenant_id, router_id, hrStorageIndex) group_left storage_hrStorageSize{%s,hrStorageType=~"%s"})) * 100',
            $selector, $diskType, $selector, $diskType,
            $selector, $diskType, $selector, $diskType
        );
        $diskResponse = $vm->queryRange($diskQuery, $start, $end, $step);
        
        $diskResultCount = count($diskResponse['data']['result'] ?? []);
        Log::debug('Disk query result', ['query' => $diskQuery, 'series_count' => $diskResultCount]);
        $diskResults = $this->extractRouterSeriesData($diskResponse);

        foreach ($routerIds as $routerId) {
            $results[$routerId] = [
                'cpu' => $this->convertToVmFormat($cpuResults[$routerId] ?? []),
                'memory' => $this->convertToVmFormat($memResults[$routerId] ?? []),
                'disk' => $this->convertToVmFormat($diskResults[$routerId] ?? []),
            ];
        }

        return $results;
    }

    /**
     * Convert internal format back to VictoriaMetrics format for caching
     */
    private function convertToVmFormat(array $data): array
    {
        return array_map(function($point) {
            return [$point['ts'], (string) $point['v']];
        }, $data);
    }

    /**
     * Extract series data grouped by router_id
     */
    private function extractRouterSeriesData(array $response): array
    {
        $results = [];
        $resultData = $response['data']['result'] ?? [];

        foreach ($resultData as $series) {
            $routerId = $series['metric']['router_id'] ?? null;
            if (!$routerId) continue;

            $values = $series['values'] ?? [];
            $results[$routerId] = array_map(function($pair) {
                return [
                    'ts' => (int) $pair[0],
                    'v' => (float) $pair[1]
                ];
            }, $values);
        }

        return $results;
    }

    /**
     * Align traffic data by timestamp
     */
    private function alignTrafficData(array $inData, array $outData): array
    {
        $map = [];

        foreach ($inData as $point) {
            $ts = $point['ts'];
            $map[$ts] = ['ts' => $ts, 'upload' => $point['v'], 'download' => 0];
        }

        foreach ($outData as $point) {
            $ts = $point['ts'];
            if (!isset($map[$ts])) {
                $map[$ts] = ['ts' => $ts, 'upload' => 0, 'download' => $point['v']];
            } else {
                $map[$ts]['download'] = $point['v'];
            }
        }

        $aligned = array_values($map);
        usort($aligned, fn($a, $b) => $a['ts'] <=> $b['ts']);

        return $aligned;
    }

    /**
     * Get appropriate step for time range
     */
    private function getStepForRange(string $range): string
    {
        return match(true) {
            str_ends_with($range, 'm') => '15s',
            str_ends_with($range, 'h') => '30s',
            str_ends_with($range, 'd') => '5m',
            default => '30s'
        };
    }

    /**
     * Calculate start time from range string
     */
    private function rangeStartFromNow(string $range, int $now): int
    {
        $range = trim(strtolower($range));

        return match(true) {
            str_ends_with($range, 'm') => max(0, $now - ((int) rtrim($range, 'm')) * 60),
            str_ends_with($range, 'h') => max(0, $now - ((int) rtrim($range, 'h')) * 3600),
            str_ends_with($range, 'd') => max(0, $now - ((int) rtrim($range, 'd')) * 86400),
            default => max(0, $now - 3600),
        };
    }

    /**
     * Escape label value for Prometheus query
     */
    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ComputeRouterMetricsJob failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage()
        ]);
    }
}
