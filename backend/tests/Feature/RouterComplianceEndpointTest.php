<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\RouterController;
use App\Models\Router;
use App\Models\RouterComplianceSnapshot;
use App\Models\Tenant;
use App\Services\Deployment\RouterComplianceEngine;
use Illuminate\Http\Request;
use Mockery;
use Tests\TestCase;

class RouterComplianceEndpointTest extends TestCase
{
    public function test_it_returns_a_router_compliance_report(): void
    {
        $tenant = (new Tenant())->setRawAttributes([
            'id' => 'tenant-a',
            'name' => 'Tenant A',
            'slug' => 'tenant-a',
            'is_active' => true,
        ], true);

        $router = (new Router())->setRawAttributes([
            'id' => 901,
            'tenant_id' => $tenant->id,
            'name' => 'Compliance Router',
            'status' => 'online',
            'os_version' => '7.18.0',
        ], true);
        $router->setRelation('tenant', $tenant);

        $request = Request::create('/api/routers/901/compliance', 'GET', ['refresh' => 0]);
        $request->setUserResolver(fn () => (object) ['tenant_id' => $tenant->id]);

        $snapshot = RouterComplianceSnapshot::make([
            'tenant_id' => $tenant->id,
            'router_id' => $router->id,
            'score' => 92,
            'grade' => 'A',
            'status' => 'compliant',
            'checks' => [
                ['key' => 'ssh', 'label' => 'SSH Enabled', 'passed' => true, 'weight' => 15],
                ['key' => 'api', 'label' => 'API Enabled', 'passed' => true, 'weight' => 10],
            ],
            'missing_controls' => [],
            'passed_controls' => ['ssh', 'api', 'firewall'],
            'summary' => 'Router is compliant.',
            'source_snapshot_id' => null,
            'evaluated_at' => now(),
        ]);

        $engine = Mockery::mock(RouterComplianceEngine::class);
        $engine->shouldReceive('getLatestSnapshot')
            ->once()
            ->with(Mockery::on(fn ($candidate) => (string) $candidate->id === (string) $router->id))
            ->andReturn(null);
        $engine->shouldReceive('evaluateAndPersist')
            ->once()
            ->with(Mockery::on(fn ($candidate) => (string) $candidate->id === (string) $router->id))
            ->andReturn($snapshot);

        $controller = app(RouterController::class);
        $response = $controller->getRouterCompliance($request, $router, $engine);
        $payload = $response->getData(true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['success']);
        $this->assertTrue($payload['refresh']);
        $this->assertSame(901, $payload['report']['router_id']);
        $this->assertSame(92, $payload['report']['score']);
        $this->assertSame('compliant', $payload['report']['status']);
        $this->assertSame('Router is compliant.', $payload['report']['summary']);
    }
}
