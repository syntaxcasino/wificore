<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class RouterMetricsService
{
    public function getLatestRouterMetrics(VictoriaMetricsClient $vm, string $tenantId, array $routerIds): array
    {
        $routerIds = array_values(array_filter(array_map('strval', $routerIds), fn ($id) => $id !== ''));
        if (count($routerIds) === 0) {
            return [];
        }

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
            'pppoe_sessions' => sprintf('router_health_pppoe_sessions{%s}', $selector),
            'hotspot_active' => sprintf('router_health_hotspot_active{%s}', $selector),
            'wireless_clients' => sprintf('router_health_wireless_clients{%s}', $selector),
            'dhcp_leases' => sprintf('router_health_dhcp_leases{%s}', $selector),
            'interface_count' => sprintf('router_health_interface_count{%s}', $selector),
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

            try {
                $response = $vm->queryInstant($primary);
            } catch (Throwable $e) {
                $this->logVmIssueOnce(
                    $tenantId,
                    $field,
                    'primary_unavailable',
                    'VictoriaMetrics unavailable while fetching router live data; using cache fallback',
                    [
                        'router_count' => count($routerIds),
                    ],
                    $e
                );

                return $this->applyCacheFallback($routerIds, $live);
            }
            
            // Log the raw response count for debugging
            $resultCount = count($response['data']['result'] ?? []);
            Log::debug("VM Query [$field] primary result count: $resultCount");
            
            $missing = $this->applyInstantResult($response, $live, $field, $missing);

            if ($fallback && count($missing) > 0) {
                Log::debug("VM Query [$field] using fallback for " . count($missing) . " routers");

                try {
                    $fallbackResponse = $vm->queryInstant($fallback);
                } catch (Throwable $e) {
                    $this->logVmIssueOnce(
                        $tenantId,
                        $field,
                        'fallback_failed',
                        'VictoriaMetrics fallback query failed while fetching router live data',
                        [
                            'router_count' => count($missing),
                        ],
                        $e
                    );

                    continue;
                }
                
                $fallbackResultCount = count($fallbackResponse['data']['result'] ?? []);
                Log::debug("VM Query [$field] fallback result count: $fallbackResultCount");
                
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

            $uptimeTicks = $live[$rid]['uptime_ticks'] ?? null;
            // Aggregate active connections from various services
            if (is_int($uptimeTicks) && $uptimeTicks >= 0) {
                $live[$rid]['uptime'] = $this->formatUptimeFromTicks($uptimeTicks);
            }

            $pppoe = $live[$rid]['pppoe_sessions'] ?? 0;
            $hotspot = $live[$rid]['hotspot_active'] ?? 0;
            $wireless = $live[$rid]['wireless_clients'] ?? 0;

            // Only set active_connections if we have at least one valid metric to avoid overwriting with 0 if data is missing
            if (isset($live[$rid]['pppoe_sessions']) || isset($live[$rid]['hotspot_active']) || isset($live[$rid]['wireless_clients'])) {
                $live[$rid]['active_connections'] = $pppoe + $hotspot + $wireless;
            }

            // Ensure basic integer fields are present if available
            $intFields = ['dhcp_leases', 'interface_count'];
            foreach ($intFields as $field) {
                if (isset($live[$rid][$field])) {
                    $live[$rid][$field] = (int) $live[$rid][$field];
                }
            }

            $pppoeSessions = $live[$rid]['pppoe_sessions'] ?? null;
            if (is_int($pppoeSessions) && !array_key_exists('active_connections', $live[$rid])) {
                $live[$rid]['active_connections'] = $pppoeSessions;
            }
        }

        return $this->applyCacheFallback($routerIds, $live);
    }

    private function applyCacheFallback(array $routerIds, array $live): array
    {
        // Fall back to the Redis cache written by FetchRouterLiveData for any
        // router that VictoriaMetrics returned no data for (e.g. Telegraf not yet
        // polling, or SNMP not reachable). Cache TTL is 30 s so data is fresh.
        foreach ($routerIds as $routerId) {
            $rid = (string) $routerId;
            if (!isset($live[$rid]) || empty($live[$rid])) {
                $cached = Cache::get("router_live_data_{$rid}");
                if (is_array($cached) && !empty($cached) && !isset($cached['error'])) {
                    $live[$rid] = $cached;
                    Log::debug('RouterMetricsService: using Redis cache fallback', ['router_id' => $rid]);
                }
            }
        }

        return $live;
    }

    private function formatUptimeFromTicks(int $ticks): string
    {
        $seconds = (int) floor($ticks / 100);

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($days > 0) {
            return $days . 'd ' . $hours . 'h';
        }

        if ($hours > 0) {
            return $hours . 'h ' . $minutes . 'm';
        }

        return $minutes . 'm ' . $secs . 's';
    }

    private function logVmIssueOnce(
        string $tenantId,
        string $field,
        string $issue,
        string $message,
        array $context = [],
        ?Throwable $e = null
    ): void {
        $cacheKey = sprintf('router_metrics_vm_issue:%s:%s:%s', $tenantId, $field, $issue);
        $context['tenant_id'] = $tenantId;
        $context['field'] = $field;

        if ($e !== null) {
            $context['error'] = $e->getMessage();
            $context['exception'] = get_class($e);
        }

        if (Cache::add($cacheKey, true, now()->addMinutes(5))) {
            Log::warning($message, $context);
            return;
        }

        Log::debug($message, $context);
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
