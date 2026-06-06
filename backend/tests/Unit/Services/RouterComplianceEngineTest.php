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
    private function makeRouter(string $id = '01a03b6a-b737-4039-a376-32cba5479a39'): Router
    {
        $router = new Router();
        $router->id = $id;
        $router->name = 'Compliance Router';
        $router->vendor = 'mikrotik';
        $router->model = 'hAP ac2';
        $router->tenant_id = 'be27f09b-ee28-46b0-ba5e-1ec43226d421';

        return $router;
    }

    #[Test]
    public function it_scores_configurations_and_reports_missing_controls(): void
    {
        $router = $this->makeRouter();
        $snapshot = new ConfigSnapshot();
        $snapshot->id = 'c9de9df6-8d8a-46b1-b578-8a9c9ed88dfb';
        $snapshot->config_text = "/ip service\nset [find name=ssh] disabled=no\nset [find name=api] disabled=no\n/ip firewall filter add chain=input action=accept comment=\"allow management\"\n/system ntp client set enabled=yes servers=\"0.pool.ntp.org,1.pool.ntp.org\"\n/ip dns set servers=1.1.1.1,8.8.8.8\n/system scheduler add name=daily-backup interval=1d on-event=\"/system backup save name=daily\"";

        $driftDetector = $this->createMock(ConfigDriftDetector::class);
        $driftDetector->method('getLatestSnapshot')->willReturn($snapshot);

        $engine = new RouterComplianceEngine($driftDetector);
        $report = $engine->evaluate($router);

        $this->assertGreaterThanOrEqual(90, $report->score);
        $this->assertSame('compliant', $report->status);
        $this->assertEmpty($report->missingControls);
        $this->assertSame('01a03b6a-b737-4039-a376-32cba5479a39', $report->routerId);
        $this->assertSame('be27f09b-ee28-46b0-ba5e-1ec43226d421', $report->tenantId);
        $this->assertSame('c9de9df6-8d8a-46b1-b578-8a9c9ed88dfb', $report->sourceSnapshotId);
        $this->assertSame('01a03b6a-b737-4039-a376-32cba5479a39', $report->toArray()['router_id']);
    }

    #[Test]
    public function it_flags_missing_controls_when_baseline_is_incomplete(): void
    {
        $router = $this->makeRouter('5851a8eb-e7cc-44ff-b6f4-bd333e8a8534');
        $snapshot = new ConfigSnapshot();
        $snapshot->id = '95a2db9e-08a2-450f-a153-a3ef927a994b';
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
