<?php

namespace App\Services;

use Carbon\CarbonInterface;

class RevenueAssuranceEngine
{
    public function evaluate(array $signals, ?CarbonInterface $asOf = null): array
    {
        $asOf ??= now();

        $payments = (array) ($signals['payments'] ?? []);
        $subscriptions = (array) ($signals['subscriptions'] ?? []);
        $pppoe = (array) ($signals['pppoe'] ?? []);
        $hotspot = (array) ($signals['hotspot'] ?? []);
        $sessions = (array) ($signals['sessions'] ?? []);
        $byArea = array_values(array_filter((array) ($signals['revenue_by_area'] ?? [])));

        $findings = [];
        $penalty = 0;

        $ruleSet = [
            [
                'key' => 'active_not_billed',
                'label' => 'Active customers not billed',
                'count' => (int) ($pppoe['active_not_billed'] ?? 0) + (int) ($hotspot['active_not_billed'] ?? 0),
                'severity' => 'critical',
                'weight' => 18,
                'evidence' => array_slice(array_values(array_unique(array_merge(
                    (array) ($pppoe['active_not_billed_users'] ?? []),
                    (array) ($hotspot['active_not_billed_users'] ?? [])
                ))), 0, 10),
            ],
            [
                'key' => 'duplicate_identity',
                'label' => 'Duplicate PPP/Hotspot identities',
                'count' => (int) ($pppoe['duplicate_usernames'] ?? 0) + (int) ($hotspot['duplicate_usernames'] ?? 0),
                'severity' => 'high',
                'weight' => 14,
                'evidence' => array_slice(array_values(array_unique(array_merge(
                    (array) ($pppoe['duplicate_username_examples'] ?? []),
                    (array) ($hotspot['duplicate_username_examples'] ?? [])
                ))), 0, 10),
            ],
            [
                'key' => 'expired_online',
                'label' => 'Expired but still online',
                'count' => (int) ($pppoe['expired_online'] ?? 0) + (int) ($hotspot['expired_online'] ?? 0),
                'severity' => 'critical',
                'weight' => 18,
                'evidence' => array_slice(array_values(array_unique(array_merge(
                    (array) ($pppoe['expired_online_examples'] ?? []),
                    (array) ($hotspot['expired_online_examples'] ?? [])
                ))), 0, 10),
            ],
            [
                'key' => 'callback_mismatch',
                'label' => 'Callback / receipt mismatch',
                'count' => (int) ($payments['callback_mismatch'] ?? 0),
                'severity' => 'high',
                'weight' => 12,
                'evidence' => array_slice((array) ($payments['callback_mismatch_examples'] ?? []), 0, 10),
            ],
            [
                'key' => 'missing_accounting',
                'label' => 'Missing accounting records',
                'count' => (int) ($payments['missing_accounting'] ?? 0),
                'severity' => 'medium',
                'weight' => 10,
                'evidence' => array_slice((array) ($payments['missing_accounting_examples'] ?? []), 0, 10),
            ],
            [
                'key' => 'pending_overdue',
                'label' => 'Overdue pending payments',
                'count' => (int) ($payments['pending_overdue'] ?? 0),
                'severity' => 'medium',
                'weight' => 8,
                'evidence' => array_slice((array) ($payments['pending_overdue_examples'] ?? []), 0, 10),
            ],
            [
                'key' => 'unmatched_sessions',
                'label' => 'Active sessions without billed subscription',
                'count' => (int) ($sessions['unmatched_active'] ?? 0),
                'severity' => 'medium',
                'weight' => 6,
                'evidence' => array_slice((array) ($sessions['unmatched_examples'] ?? []), 0, 10),
            ],
        ];

        foreach ($ruleSet as $rule) {
            if ((int) $rule['count'] <= 0) {
                continue;
            }

            $penalty += min((int) $rule['weight'], (int) $rule['count'] * max(1, (int) ceil($rule['weight'] / 2)));
            $findings[] = $rule;
        }

        $activeSubscribers = max(1, (int) ($subscriptions['active_count'] ?? 0) + (int) ($pppoe['active_count'] ?? 0) + (int) ($hotspot['active_count'] ?? 0));
        $monthlyRevenue = (float) ($payments['monthly_completed_amount'] ?? 0.0);
        $dailyRevenue = (float) ($payments['daily_completed_amount'] ?? 0.0);
        $completedCount = max(1, (int) ($payments['monthly_completed_count'] ?? 0));
        $failedCount = (int) ($payments['failed_today'] ?? 0);
        $completedToday = max(0, (int) ($payments['completed_today'] ?? 0));
        $failedRate = ($failedCount + $completedToday) > 0 ? round(($failedCount / ($failedCount + $completedToday)) * 100, 2) : 0;
        $arpu = round($monthlyRevenue / $activeSubscribers, 2);
        $mrr = round($monthlyRevenue, 2);
        $arr = round($mrr * 12, 2);
        $churnRate = ($subscriptions['active_count'] ?? 0) + ($subscriptions['expired_count'] ?? 0) > 0
            ? round(((int) ($subscriptions['expired_count'] ?? 0) / max(1, ((int) ($subscriptions['active_count'] ?? 0) + (int) ($subscriptions['expired_count'] ?? 0)))) * 100, 2)
            : 0;

        $areaRevenue = array_map(static function (array $row): array {
            return [
                'label' => $row['label'] ?? 'Unspecified',
                'amount' => round((float) ($row['amount'] ?? 0), 2),
                'count' => (int) ($row['count'] ?? 0),
            ];
        }, $byArea);

        $score = max(0, 100 - $penalty);
        $status = $score >= 85 ? 'healthy' : ($score >= 70 ? 'warning' : 'critical');
        $summary = $findings === []
            ? 'No revenue leakage signals detected.'
            : 'Top leakage signals: ' . implode(', ', array_slice(array_map(fn ($finding) => $finding['label'], $findings), 0, 3));

        return [
            'score' => $score,
            'status' => $status,
            'summary' => $summary,
            'findings' => $findings,
            'signals' => $signals,
            'kpis' => [
                'mrr' => $mrr,
                'arr' => $arr,
                'arpu' => $arpu,
                'churn_rate' => $churnRate,
                'failed_payment_rate' => $failedRate,
                'daily_revenue' => round($dailyRevenue, 2),
                'monthly_completed_count' => (int) ($payments['monthly_completed_count'] ?? 0),
                'revenue_by_area' => $areaRevenue,
                'active_subscribers' => $activeSubscribers,
            ],
            'generated_at' => $asOf->toIso8601String(),
        ];
    }
}
