<?php

namespace Tests\Unit\Services;

use App\Services\RevenueAssuranceEngine;
use Tests\TestCase;

class RevenueAssuranceEngineTest extends TestCase
{
    public function test_it_scores_leakage_signals_and_calculates_kpis(): void
    {
        $engine = app(RevenueAssuranceEngine::class);

        $report = $engine->evaluate([
            'payments' => [
                'monthly_completed_amount' => 12000,
                'monthly_completed_count' => 24,
                'daily_completed_amount' => 1200,
                'completed_today' => 4,
                'failed_today' => 2,
                'callback_mismatch' => 1,
                'missing_accounting' => 1,
                'pending_overdue' => 3,
                'callback_mismatch_examples' => ['MPESA-001'],
                'missing_accounting_examples' => ['MPESA-002'],
                'pending_overdue_examples' => ['MPESA-003'],
            ],
            'subscriptions' => [
                'active_count' => 80,
                'expired_count' => 20,
            ],
            'pppoe' => [
                'active_count' => 40,
                'active_not_billed' => 5,
                'duplicate_usernames' => 2,
                'expired_online' => 1,
                'active_not_billed_users' => ['pppoe-1'],
                'duplicate_username_examples' => ['dup-pppoe'],
                'expired_online_examples' => ['expired-pppoe'],
            ],
            'hotspot' => [
                'active_count' => 30,
                'active_not_billed' => 4,
                'duplicate_usernames' => 1,
                'expired_online' => 2,
                'active_not_billed_users' => ['hotspot-1'],
                'duplicate_username_examples' => ['dup-hotspot'],
                'expired_online_examples' => ['expired-hotspot'],
            ],
            'sessions' => [
                'unmatched_active' => 6,
                'unmatched_examples' => ['voucher-1'],
            ],
            'revenue_by_area' => [
                ['label' => 'Nairobi', 'amount' => 7000, 'count' => 10],
                ['label' => 'Kiambu', 'amount' => 5000, 'count' => 14],
            ],
        ]);

        $this->assertSame('critical', $report['status']);
        $this->assertLessThan(85, $report['score']);
        $this->assertSame(12000.0, $report['kpis']['mrr']);
        $this->assertSame(144000.0, $report['kpis']['arr']);
        $this->assertSame(33.33, $report['kpis']['failed_payment_rate']);
        $this->assertCount(2, $report['kpis']['revenue_by_area']);
        $this->assertNotEmpty($report['findings']);
        $this->assertStringContainsString('Top leakage signals', $report['summary']);
    }

    public function test_it_returns_a_healthy_report_when_no_leakage_signals_exist(): void
    {
        $engine = app(RevenueAssuranceEngine::class);

        $report = $engine->evaluate([
            'payments' => [],
            'subscriptions' => [],
            'pppoe' => [],
            'hotspot' => [],
            'sessions' => [],
            'revenue_by_area' => [],
        ]);

        $this->assertSame('healthy', $report['status']);
        $this->assertSame(100, $report['score']);
        $this->assertSame('No revenue leakage signals detected.', $report['summary']);
        $this->assertSame(0.0, $report['kpis']['mrr']);
    }
}
