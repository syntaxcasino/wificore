<?php

namespace Tests\Unit\Services;

use App\Services\TenantHealthScoreEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantHealthScoreEngineTest extends TestCase
{
    #[Test]
    public function it_builds_an_explainable_health_score_from_signals(): void
    {
        $engine = new TenantHealthScoreEngine();

        $report = $engine->buildReportFromSignals([
            'routers' => [
                'total_count' => 5,
                'offline_count' => 2,
                'stale_count' => 1,
                'vpn_stale_count' => 1,
                'provisioning_count' => 0,
                'offline_router_ids' => ['router-1', 'router-2'],
                'stale_router_ids' => ['router-3'],
                'vpn_stale_router_ids' => ['router-4'],
                'provisioning_router_ids' => [],
            ],
            'payments' => [
                'pending_overdue_count' => 3,
                'failed_today_count' => 1,
                'pending_overdue_payment_ids' => ['pay-1', 'pay-2', 'pay-3'],
                'failed_today_payment_ids' => ['pay-4'],
            ],
            'sessions' => [
                'expired_active_count' => 1,
                'expired_active_session_ids' => ['session-1'],
            ],
        ], [
            'source_event' => 'router.status.updated',
            'source_reference' => 'router-1',
        ]);

        $this->assertSame(33.0, $report['score']);
        $this->assertSame('critical', $report['grade']);
        $this->assertSame('router.status.updated', $report['context']['source_event']);
        $this->assertArrayHasKey('factors', $report);
        $this->assertSame('Offline routers', $report['factors'][0]['label']);
        $this->assertGreaterThan(0, $report['factors'][0]['penalty']);
        $this->assertStringContainsString('top contributors', strtolower($report['summary']));
    }

    #[Test]
    public function it_grades_as_healthy_when_no_negative_signals_exist(): void
    {
        $engine = new TenantHealthScoreEngine();

        $report = $engine->buildReportFromSignals([
            'routers' => [
                'total_count' => 0,
                'offline_count' => 0,
                'stale_count' => 0,
                'vpn_stale_count' => 0,
                'provisioning_count' => 0,
                'offline_router_ids' => [],
                'stale_router_ids' => [],
                'vpn_stale_router_ids' => [],
                'provisioning_router_ids' => [],
            ],
            'payments' => [
                'pending_overdue_count' => 0,
                'failed_today_count' => 0,
                'pending_overdue_payment_ids' => [],
                'failed_today_payment_ids' => [],
            ],
            'sessions' => [
                'expired_active_count' => 0,
                'expired_active_session_ids' => [],
            ],
        ]);

        $this->assertSame(100.0, $report['score']);
        $this->assertSame('healthy', $report['grade']);
        $this->assertSame([], array_filter($report['factors'], fn (array $factor) => ($factor['penalty'] ?? 0) > 0));
    }
}
