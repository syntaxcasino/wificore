<?php

declare(strict_types=1);

namespace App\Services\Troubleshooting;

use App\Models\Router;
use Carbon\CarbonInterface;

class RouterTroubleshootingAssistant
{
    private function normalizeList(mixed $value): array
    {
        if ($value instanceof \Illuminate\Support\Collection) {
            return array_values($value->all());
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [];
    }

    public function diagnose(Router $router, array $context = [], ?CarbonInterface $now = null): array
    {
        $now ??= now();
        $status = strtolower((string) ($router->status ?? 'unknown'));
        $vpnStatus = strtolower((string) ($router->vpn_status ?? 'unknown'));
        $lastSeen = $router->last_seen instanceof CarbonInterface ? $router->last_seen : null;
        $staleMinutes = $lastSeen ? $lastSeen->diffInMinutes($now) : null;
        $services = $this->normalizeList($context['services'] ?? []);
        $accessPoints = $this->normalizeList($context['access_points'] ?? []);
        $activeConnections = (int) ($context['active_connections'] ?? 0);
        $complianceScore = isset($context['compliance']['score']) ? (int) $context['compliance']['score'] : null;

        $findings = [];
        $recommendations = [];
        $confidence = 0.55;
        $severity = 'info';
        $cause = 'No obvious fault detected';

        if ($status === 'offline') {
            $cause = 'Router is offline or unreachable';
            $severity = 'critical';
            $confidence = 0.98;
            $findings[] = 'Router status is offline.';
            if ($staleMinutes !== null) {
                $findings[] = sprintf('Last seen %d minutes ago.', $staleMinutes);
            }
            $recommendations[] = 'Check power, WAN reachability, and the management tunnel first.';
        } elseif ($vpnStatus === 'down' || $vpnStatus === 'disconnected') {
            $cause = 'VPN tunnel is unhealthy';
            $severity = 'high';
            $confidence = 0.91;
            $findings[] = 'VPN status indicates a disconnected tunnel.';
            $recommendations[] = 'Verify WireGuard / VPN peer state and firewall reachability.';
        } elseif ($staleMinutes !== null && $staleMinutes >= 20) {
            $cause = 'Router heartbeat is stale';
            $severity = 'high';
            $confidence = 0.84;
            $findings[] = sprintf('Router last reported %d minutes ago.', $staleMinutes);
            $recommendations[] = 'Review WAN health, CPU pressure, and router event logs.';
        } elseif ($activeConnections === 0 && count($services) > 0) {
            $cause = 'Services are configured but no customer traffic is active';
            $severity = 'medium';
            $confidence = 0.72;
            $findings[] = sprintf('%d services are configured on the router.', count($services));
            $recommendations[] = 'Check PPPoE secrets, queue bindings, and RADIUS authentication.';
        } elseif ($complianceScore !== null && $complianceScore < 85) {
            $cause = 'Router compliance drift is likely affecting service health';
            $severity = 'medium';
            $confidence = 0.75;
            $findings[] = sprintf('Compliance score is %d/100.', $complianceScore);
            $recommendations[] = 'Review missing controls and restore the approved baseline.';
        } elseif (count($accessPoints) > 0 && $activeConnections > 0) {
            $cause = 'Router is operational; issue is likely localized to a subset of services or clients';
            $severity = 'low';
            $confidence = 0.6;
            $findings[] = sprintf('%d access points are attached to this router.', count($accessPoints));
            $recommendations[] = 'Inspect affected client sessions, queue limits, and area-specific outage signals.';
        }

        if ($status === 'online' && $staleMinutes !== null && $staleMinutes >= 5 && !in_array($cause, ['Router is offline or unreachable', 'VPN tunnel is unhealthy'], true)) {
            $findings[] = sprintf('Router last seen %d minutes ago.', $staleMinutes);
        }

        $nextActions = array_values(array_unique(array_merge($recommendations, [
            'Verify router logs and interface errors.',
            'Compare live status against the compliance baseline.',
            'Check payment and subscription state for impacted customers.',
        ])));

        return [
            'deterministic' => true,
            'severity' => $severity,
            'confidence' => round($confidence, 2),
            'diagnosis' => $cause,
            'summary' => $cause . ($findings ? ' - ' . $findings[0] : ''),
            'evidence' => $findings,
            'next_actions' => $nextActions,
            'signals' => [
                'status' => $status,
                'vpn_status' => $vpnStatus,
                'last_seen_minutes' => $staleMinutes,
                'service_count' => count($services),
                'access_point_count' => count($accessPoints),
                'active_connections' => $activeConnections,
                'compliance_score' => $complianceScore,
            ],
            'generated_at' => $now->toIso8601String(),
        ];
    }
}
