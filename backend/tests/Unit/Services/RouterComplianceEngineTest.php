<?php

namespace Tests\Unit\Services;

use App\Models\ConfigSnapshot;
use App\Models\Router;
use App\Services\Deployment\ConfigDriftDetector;
use App\Services\Deployment\RouterComplianceEngine;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterComplianceEngineTest extends TestCase
{
    private function makeRouter(string $id = 'router-compliance-1'): Router
    {
        $router = new Router();
        $router->id = $id;
        $router->name = 'Compliance Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';
        $router->tenant_id = 'tenant-123';

        return $router;
    }

    #[Test]
    public function it_scores_configurations_and_reports_missing_controls(): void
    {
        $router = $this->makeRouter();
        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'snapshot-1';
        $snapshot->config_text = "/ip service\nset [find name=ssh] disabled=no\nset [find name=api] disabled=no\n/ip firewall filter add chain=input action=accept comment=\"allow management\"\n/system ntp client set enabled=yes servers=\"0.pool.ntp.org,1.pool.ntp.org\"\n/ip dns set servers=1.1.1.1,8.8.8.8\n/system scheduler add name=daily-backup interval=1d on-event=\"/system backup save name=daily\"";

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->method('getLatestSnapshot')->willReturn($snapshot);

        $engine = new RouterComplianceEngine($driftDetector);
        $report = $engine->evaluate($router);

        $this->assertGreaterThanOrEqual(90, $report->score);
        $this->assertSame('compliant', $report->status);
        $this->assertEmpty($report->missingControls);
        $this->assertSame('snapshot-1', (string) $report->sourceSnapshotId);
    }

    #[Test]
    public function it_flags_missing_controls_when_baseline_is_incomplete(): void
    {
        $router = $this->makeRouter('router-compliance-2');
        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'snapshot-2';
        $snapshot->config_text = '/system ntp client set enabled=yes';

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->method('getLatestSnapshot')->willReturn($snapshot);

        $engine = new RouterComplianceEngine($driftDetector);
        $report = $engine->evaluate($router);

        $this->assertLessThan(80, $report->score);
        $this->assertSame('non_compliant', $report->status);
        $this->assertNotEmpty($report->missingControls);
    }
}
