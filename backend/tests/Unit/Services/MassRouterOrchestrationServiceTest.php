<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Jobs\DeployRouterConfigJob;
use App\Models\Router;
use App\Services\MikroTik\RouterOsCapabilityRegistry;
use App\Services\MikroTik\RouterOsV7ProvisioningValidator;
use App\Services\MikroTik\ServiceTemplateService;
use App\Services\Orchestration\MassRouterOrchestrationService;
use App\Services\RouterDriver\RouterTemplateMarketplaceRegistry;
use App\Services\RouterDriver\RouterVendorProfileRegistry;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MassRouterOrchestrationServiceTest extends TestCase
{
    public function test_it_builds_a_deterministic_preview_plan_for_bulk_changes(): void
    {
        $service = new MassRouterOrchestrationService(
            app(RouterVendorProfileRegistry::class),
            app(RouterOsCapabilityRegistry::class),
            app(RouterTemplateMarketplaceRegistry::class),
            app(ServiceTemplateService::class),
            app(RouterOsV7ProvisioningValidator::class),
        );

        $plan = $service->preview([
            [
                'id' => 1,
                'name' => 'Core A',
                'vendor' => 'mikrotik',
                'model' => 'RB4011',
                'os_version' => '7.18.0',
                'status' => 'online',
            ],
            [
                'id' => 2,
                'name' => 'Edge B',
                'vendor' => 'unknown-vendor',
                'model' => 'X1000',
                'os_version' => '6.49.10',
                'status' => 'offline',
            ],
        ], [
            'change_type' => 'apply_service_configs',
            'template' => 'hotel',
            'batch_size' => 10,
        ]);

        $this->assertSame('apply_service_configs', $plan['change_type']);
        $this->assertSame('hotel', $plan['template']);
        $this->assertSame(2, $plan['router_count']);
        $this->assertSame(1, $plan['supported_count']);
        $this->assertGreaterThanOrEqual(2, $plan['warning_count']);
        $this->assertSame('preview', $plan['execution_strategy']['mode']);
        $this->assertSame('Core A', $plan['router_plans'][0]['name']);
    }

    public function test_it_queues_deploy_jobs_only_for_executable_templates(): void
    {
        Queue::fake();

        $service = new MassRouterOrchestrationService(
            app(RouterVendorProfileRegistry::class),
            app(RouterOsCapabilityRegistry::class),
            app(RouterTemplateMarketplaceRegistry::class),
            app(ServiceTemplateService::class),
            app(RouterOsV7ProvisioningValidator::class),
        );

        $router = new Router();
        $router->forceFill([
            'id' => 'router-1',
            'name' => 'Deploy Core',
            'vendor' => 'mikrotik',
            'model' => 'RB4011',
            'os_version' => '7.18.0',
            'status' => 'online',
            'wan_interface' => 'ether1',
            'interface_list' => [
                ['name' => 'ether1'],
                ['name' => 'ether2'],
                ['name' => 'ether3'],
            ],
        ]);

        $result = $service->deploy([$router], [
            'template' => 'hotel-hotspot',
            'change_type' => 'apply_service_configs',
            'batch_size' => 5,
        ]);

        Queue::assertPushed(DeployRouterConfigJob::class);
        $this->assertSame('hotel-hotspot', $result['template']);
        $this->assertSame('hotspot', $result['template_execution_type']);
        $this->assertSame(1, $result['queued_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertNotEmpty($result['queued_routers']);
    }

    public function test_it_deploys_executable_multi_wan_templates(): void
    {
        Queue::fake();

        $service = new MassRouterOrchestrationService(
            app(RouterVendorProfileRegistry::class),
            app(RouterOsCapabilityRegistry::class),
            app(RouterTemplateMarketplaceRegistry::class),
            app(ServiceTemplateService::class),
            app(RouterOsV7ProvisioningValidator::class),
        );

        $router = new Router();
        $router->forceFill([
            'id' => 'router-2',
            'name' => 'Multi-WAN Router',
            'vendor' => 'mikrotik',
            'model' => 'RB4011',
            'os_version' => '7.18.0',
            'status' => 'online',
            'wan_interface' => 'ether1',
            'interface_list' => [
                ['name' => 'ether1'],
                ['name' => 'ether2'],
                ['name' => 'bridge-lan'],
            ],
        ]);

        $result = $service->deploy([$router], [
            'template' => 'multi-wan-failover',
            'change_type' => 'apply_service_configs',
            'batch_size' => 5,
        ]);

        Queue::assertPushed(DeployRouterConfigJob::class);
        $this->assertSame('multi-wan-failover', $result['template']);
        $this->assertSame('multi-wan-failover', $result['template_execution_type']);
        $this->assertSame(1, $result['queued_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertNotEmpty($result['queued_routers']);
    }

    public function test_it_deploys_executable_pcc_templates(): void
    {
        Queue::fake();

        $service = new MassRouterOrchestrationService(
            app(RouterVendorProfileRegistry::class),
            app(RouterOsCapabilityRegistry::class),
            app(RouterTemplateMarketplaceRegistry::class),
            app(ServiceTemplateService::class),
            app(RouterOsV7ProvisioningValidator::class),
        );

        $router = new Router();
        $router->forceFill([
            'id' => 'router-3',
            'name' => 'PCC Router',
            'vendor' => 'mikrotik',
            'model' => 'RB4011',
            'os_version' => '7.18.0',
            'status' => 'online',
            'wan_interface' => 'ether1',
            'interface_list' => [
                ['name' => 'ether1'],
                ['name' => 'ether2'],
                ['name' => 'bridge-lan'],
            ],
        ]);

        $result = $service->deploy([$router], [
            'template' => 'pcc-balanced',
            'change_type' => 'apply_service_configs',
            'batch_size' => 5,
        ]);

        Queue::assertPushed(DeployRouterConfigJob::class);
        $this->assertSame('pcc-balanced', $result['template']);
        $this->assertSame('pcc-balanced', $result['template_execution_type']);
        $this->assertSame(1, $result['queued_count']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertNotEmpty($result['queued_routers']);
    }
}
