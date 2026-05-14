<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PppoeUser;
use App\Services\TenantContext;
use App\Services\VictoriaMetricsClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PPPoE Metrics Controller
 * 
 * Provides per-user PPPoE traffic metrics from VictoriaMetrics.
 * All queries are tenant-filtered to ensure zero cross-tenant data leaks.
 */
class PppoeMetricsController extends Controller
{
    /**
     * Get live traffic metrics for active PPPoE users
     * 
     * Queries VictoriaMetrics for interface traffic counters.
     * PPPoE sessions create dynamic interfaces named after the username.
     */
    public function liveTraffic(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        $routerId = $request->query('router_id');
        $usernames = $request->input('usernames', []);
        
        if (!is_array($usernames)) {
            $usernames = [];
        }

        // If no usernames provided, get active PPPoE users from database
        if (empty($usernames)) {
            $usernames = PppoeUser::query()
                ->where('status', 'active')
                ->when($routerId, fn($q) => $q->where('router_id', $routerId))
                ->pluck('username')
                ->filter()
                ->unique()
                ->values()
                ->all();
        }

        if (empty($usernames)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'No active PPPoE users found',
            ]);
        }

        $tenantIdEscaped = $this->escapeLabelValue((string) $tenantId);
        
        // Build router filter if specified
        $routerFilter = '';
        if ($routerId) {
            $routerFilter = sprintf(',router_id="%s"', $this->escapeLabelValue((string) $routerId));
        }

        // Build interface name regex to match PPPoE usernames
        // PPPoE interfaces are typically named as <pppoe-username> or just username
        $usernamePatterns = array_map(fn($u) => $this->escapeRegexValue((string) $u), $usernames);
        $ifNameRegex = '^(<pppoe-)?(' . implode('|', $usernamePatterns) . ')(>)?$';
        
        $selector = sprintf(
            'tenant_id="%s"%s,ifName=~"%s"',
            $tenantIdEscaped,
            $routerFilter,
            $this->escapeLabelValue($ifNameRegex)
        );

        // Query for traffic rates (bytes per second)
        $inRateQuery = sprintf('rate(interface_counters_ifHCInOctets{%s}[1m])', $selector);
        $outRateQuery = sprintf('rate(interface_counters_ifHCOutOctets{%s}[1m])', $selector);
        
        // Query for total bytes
        $inBytesQuery = sprintf('interface_counters_ifHCInOctets{%s}', $selector);
        $outBytesQuery = sprintf('interface_counters_ifHCOutOctets{%s}', $selector);

        try {
            $inRate = $vm->queryInstant($inRateQuery);
            $outRate = $vm->queryInstant($outRateQuery);
            $inBytes = $vm->queryInstant($inBytesQuery);
            $outBytes = $vm->queryInstant($outBytesQuery);

            $metrics = [];

            // Process rate data
            $this->processMetricResults($inRate, $metrics, 'download_rate');
            $this->processMetricResults($outRate, $metrics, 'upload_rate');
            $this->processMetricResults($inBytes, $metrics, 'input_octets');
            $this->processMetricResults($outBytes, $metrics, 'output_octets');

            return response()->json([
                'success' => true,
                'data' => array_values($metrics),
                'tenant_id' => $tenantId,
                'source' => 'victoriametrics',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch PPPoE metrics: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get traffic history for a specific PPPoE user
     */
    public function userTrafficHistory(Request $request, string $username, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
    {
        $tenantId = $tenantContext->getTenantId();
        if (!$tenantId) {
            return response()->json([
                'success' => false,
                'error' => 'Tenant context not set',
            ], 403);
        }

        // Verify user belongs to tenant
        // OPTIMIZED: Use exists() instead of first() - we only need to verify existence
        if (!PppoeUser::query()->where('username', $username)->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'PPPoE user not found',
            ], 404);
        }

        $range = (string) $request->query('range', '1h');
        $step = (string) $request->query('step', '30s');
        $now = time();
        $start = $this->rangeStartFromNow($range, $now);

        $tenantIdEscaped = $this->escapeLabelValue((string) $tenantId);
        $usernameEscaped = $this->escapeLabelValue($username);
        
        // Match PPPoE interface name patterns
        $ifNameRegex = sprintf('^(<pppoe-)?%s(>)?$', $this->escapeRegexValue($username));
        
        $selector = sprintf(
            'tenant_id="%s",ifName=~"%s"',
            $tenantIdEscaped,
            $this->escapeLabelValue($ifNameRegex)
        );

        $inQuery = sprintf('sum(rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector);
        $outQuery = sprintf('sum(rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector);

        try {
            $inData = $vm->queryRange($inQuery, $start, $now, $step);
            $outData = $vm->queryRange($outQuery, $start, $now, $step);

            return response()->json([
                'success' => true,
                'username' => $username,
                'start' => $start,
                'end' => $now,
                'step' => $step,
                'download' => $inData,
                'upload' => $outData,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch traffic history: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get aggregate PPPoE traffic for all users (tenant-wide)
     */
    public function aggregateTraffic(Request $request, VictoriaMetricsClient $vm, TenantContext $tenantContext): JsonResponse
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

        $tenantIdEscaped = $this->escapeLabelValue((string) $tenantId);
        
        // Match all PPPoE interfaces (names starting with <pppoe-)
        $selector = sprintf('tenant_id="%s",ifName=~"^<pppoe-.*"', $tenantIdEscaped);

        $totalInQuery = sprintf('sum(rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector);
        $totalOutQuery = sprintf('sum(rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector);
        $byRouterInQuery = sprintf('sum by (router_id) (rate(interface_counters_ifHCInOctets{%s}[1m]))', $selector);
        $byRouterOutQuery = sprintf('sum by (router_id) (rate(interface_counters_ifHCOutOctets{%s}[1m]))', $selector);

        try {
            $totalIn = $vm->queryRange($totalInQuery, $start, $now, $step);
            $totalOut = $vm->queryRange($totalOutQuery, $start, $now, $step);
            $byRouterIn = $vm->queryRange($byRouterInQuery, $start, $now, $step);
            $byRouterOut = $vm->queryRange($byRouterOutQuery, $start, $now, $step);

            return response()->json([
                'success' => true,
                'start' => $start,
                'end' => $now,
                'step' => $step,
                'total_download' => $totalIn,
                'total_upload' => $totalOut,
                'by_router_download' => $byRouterIn,
                'by_router_upload' => $byRouterOut,
                'tenant_id' => $tenantId,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch aggregate traffic: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function processMetricResults(array $response, array &$metrics, string $field): void
    {
        $results = $response['data']['result'] ?? [];
        
        foreach ($results as $series) {
            $labels = $series['metric'] ?? [];
            $ifName = $labels['ifName'] ?? '';
            
            // Extract username from interface name (remove <pppoe- prefix and > suffix)
            $username = preg_replace('/^<pppoe-/', '', $ifName);
            $username = preg_replace('/>$/', '', $username);
            
            if (empty($username)) {
                continue;
            }

            $routerId = $labels['router_id'] ?? '';
            $key = $username . '_' . $routerId;
            
            if (!isset($metrics[$key])) {
                $metrics[$key] = [
                    'username' => $username,
                    'router_id' => $routerId,
                    'ifName' => $ifName,
                ];
            }
            
            $value = $series['value'] ?? null;
            if (is_array($value) && count($value) >= 2) {
                $metrics[$key][$field] = is_numeric($value[1]) ? (float) $value[1] : 0;
            }
        }
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
