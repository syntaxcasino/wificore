<?php

namespace App\Services;

use App\Models\HealthScoreSnapshot;
use App\Models\Payment;
use App\Models\Router;
use App\Models\UserSession;
use Illuminate\Support\Facades\Schema;

class TenantHealthScoreEngine
{
    public function buildReport(array $context = []): array
    {
        $signals = $this->collectSignals();
        return $this->buildReportFromSignals($signals, $context);
    }

    public function buildReportFromSignals(array $signals, array $context = []): array
    {
        $weights = (array) config('health_scores.weights', []);
        $factors = [];
        $penaltyTotal = 0.0;

        $definitions = [
            [
                'key' => 'offline_routers',
                'label' => 'Offline routers',
                'count' => (int) ($signals['routers']['offline_count'] ?? 0),
                'weight' => (int) ($weights['offline_router'] ?? 14),
                'evidence' => array_slice((array) ($signals['routers']['offline_router_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'stale_routers',
                'label' => 'Routers not seen recently',
                'count' => (int) ($signals['routers']['stale_count'] ?? 0),
                'weight' => (int) ($weights['stale_router'] ?? 6),
                'evidence' => array_slice((array) ($signals['routers']['stale_router_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'vpn_stale_routers',
                'label' => 'Routers with stale VPN handshakes',
                'count' => (int) ($signals['routers']['vpn_stale_count'] ?? 0),
                'weight' => (int) ($weights['vpn_stale_router'] ?? 10),
                'evidence' => array_slice((array) ($signals['routers']['vpn_stale_router_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'pending_payments',
                'label' => 'Overdue pending payments',
                'count' => (int) ($signals['payments']['pending_overdue_count'] ?? 0),
                'weight' => (int) ($weights['pending_payment'] ?? 4),
                'evidence' => array_slice((array) ($signals['payments']['pending_overdue_payment_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'failed_payments',
                'label' => 'Failed payments today',
                'count' => (int) ($signals['payments']['failed_today_count'] ?? 0),
                'weight' => (int) ($weights['failed_payment'] ?? 6),
                'evidence' => array_slice((array) ($signals['payments']['failed_today_payment_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'expired_sessions',
                'label' => 'Expired active sessions',
                'count' => (int) ($signals['sessions']['expired_active_count'] ?? 0),
                'weight' => (int) ($weights['expired_session'] ?? 5),
                'evidence' => array_slice((array) ($signals['sessions']['expired_active_session_ids'] ?? []), 0, 10),
            ],
            [
                'key' => 'provisioning_backlog',
                'label' => 'Routers still provisioning',
                'count' => (int) ($signals['routers']['provisioning_count'] ?? 0),
                'weight' => (int) ($weights['provisioning_backlog'] ?? 2),
                'evidence' => array_slice((array) ($signals['routers']['provisioning_router_ids'] ?? []), 0, 10),
            ],
        ];

        foreach ($definitions as $definition) {
            $penalty = min(100, $definition['count'] * $definition['weight']);
            $penaltyTotal += $penalty;

            $factors[] = [
                'key' => $definition['key'],
                'label' => $definition['label'],
                'count' => $definition['count'],
                'weight' => $definition['weight'],
                'penalty' => $penalty,
                'evidence' => $definition['evidence'],
            ];
        }

        usort($factors, static fn (array $a, array $b): int => $b['penalty'] <=> $a['penalty']);

        $score = max(0, round(100 - $penaltyTotal, 2));
        $grade = $score >= 85 ? 'healthy' : ($score >= 70 ? 'warning' : 'critical');

        return [
            'score' => $score,
            'grade' => $grade,
            'factors' => $factors,
            'signals' => $signals,
            'summary' => $this->summarize($score, $factors),
            'context' => $context,
            'calculated_at' => now()->toIso8601String(),
        ];
    }

    public function persistSnapshot(string $tenantId, array $report): HealthScoreSnapshot
    {
        return HealthScoreSnapshot::create([
            'tenant_id' => $tenantId,
            'score' => $report['score'] ?? 0,
            'grade' => $report['grade'] ?? 'critical',
            'factors' => $report['factors'] ?? [],
            'signals' => $report['signals'] ?? [],
            'source_event' => $report['context']['source_event'] ?? null,
            'source_reference' => $report['context']['source_reference'] ?? null,
            'calculated_at' => $report['calculated_at'] ?? now(),
        ]);
    }

    public function collectSignals(): array
    {
        $staleRouterMinutes = (int) config('health_scores.stale_router_minutes', 15);
        $vpnStaleMinutes = (int) config('health_scores.vpn_stale_minutes', 10);
        $paymentOverdueMinutes = (int) config('health_scores.payment_overdue_minutes', 30);
        $sessionOverdueMinutes = (int) config('health_scores.session_overdue_minutes', 15);

        $routerSignals = [
            'total_count' => 0,
            'offline_count' => 0,
            'stale_count' => 0,
            'vpn_stale_count' => 0,
            'provisioning_count' => 0,
            'offline_router_ids' => [],
            'stale_router_ids' => [],
            'vpn_stale_router_ids' => [],
            'provisioning_router_ids' => [],
        ];

        if (Schema::hasTable('routers')) {
            $staleCutoff = now()->subMinutes($staleRouterMinutes);
            $vpnStaleCutoff = now()->subMinutes($vpnStaleMinutes);
            $provisioningStatuses = ['pending', 'deploying', 'provisioning', 'verifying'];

            $offlineRouters = Router::query()->where('status', 'offline');
            $staleRouters = Router::query()
                ->where(function ($query) use ($staleCutoff): void {
                    $query->whereNull('last_seen')->orWhere('last_seen', '<', $staleCutoff);
                });
            $vpnStaleRouters = Router::query()
                ->where('vpn_status', 'active')
                ->where(function ($query) use ($vpnStaleCutoff): void {
                    $query->whereNull('vpn_last_handshake')->orWhere('vpn_last_handshake', '<', $vpnStaleCutoff);
                });
            $provisioningRouters = Router::query()->whereIn('status', $provisioningStatuses);

            $routerSignals['total_count'] = Router::query()->count();
            $routerSignals['offline_count'] = (clone $offlineRouters)->count();
            $routerSignals['stale_count'] = (clone $staleRouters)->count();
            $routerSignals['vpn_stale_count'] = (clone $vpnStaleRouters)->count();
            $routerSignals['provisioning_count'] = (clone $provisioningRouters)->count();
            $routerSignals['offline_router_ids'] = $this->sampleIds($offlineRouters);
            $routerSignals['stale_router_ids'] = $this->sampleIds($staleRouters);
            $routerSignals['vpn_stale_router_ids'] = $this->sampleIds($vpnStaleRouters);
            $routerSignals['provisioning_router_ids'] = $this->sampleIds($provisioningRouters);
        }

        $paymentSignals = [
            'pending_overdue_count' => 0,
            'failed_today_count' => 0,
            'pending_overdue_payment_ids' => [],
            'failed_today_payment_ids' => [],
        ];

        if (Schema::hasTable('payments')) {
            $pendingOverdue = Payment::query()
                ->where('status', 'pending')
                ->where('created_at', '<', now()->subMinutes($paymentOverdueMinutes));
            $failedToday = Payment::query()
                ->where('status', 'failed')
                ->whereDate('created_at', today());

            $paymentSignals['pending_overdue_count'] = (clone $pendingOverdue)->count();
            $paymentSignals['failed_today_count'] = (clone $failedToday)->count();
            $paymentSignals['pending_overdue_payment_ids'] = $this->sampleIds($pendingOverdue);
            $paymentSignals['failed_today_payment_ids'] = $this->sampleIds($failedToday);
        }

        $sessionSignals = [
            'expired_active_count' => 0,
            'expired_active_session_ids' => [],
        ];

        if (Schema::hasTable('user_sessions')) {
            $expiredActive = UserSession::query()
                ->where('status', 'active')
                ->whereNotNull('end_time')
                ->where('end_time', '<', now()->subMinutes($sessionOverdueMinutes));

            $sessionSignals['expired_active_count'] = (clone $expiredActive)->count();
            $sessionSignals['expired_active_session_ids'] = $this->sampleIds($expiredActive);
        }

        return [
            'routers' => $routerSignals,
            'payments' => $paymentSignals,
            'sessions' => $sessionSignals,
        ];
    }

    private function sampleIds($query, int $limit = 10): array
    {
        return (clone $query)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
    }

    private function summarize(float $score, array $factors): string
    {
        $top = collect($factors)->filter(fn (array $factor) => ($factor['penalty'] ?? 0) > 0)->take(3)->pluck('label')->all();

        if ($top === []) {
            return 'No significant health degradations detected.';
        }

        return sprintf('Health score %.2f with top contributors: %s.', $score, implode(', ', $top));
    }
}
