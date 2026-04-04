<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\MikroTik\ZeroConfigPPPoEGenerator;
use App\Models\RouterService;
use App\Models\Router;
use App\Models\TenantIpPool;

class TestPPPoEGenerator extends Command
{
    protected $signature = 'test:pppoe-generator 
                            {--debug : Show detailed debug output}';

    protected $description = 'Test PPPoE generator with sample data';

    public function handle(): int
    {
        $this->info('Testing ZeroConfigPPPoEGenerator...');

        $generator = new ZeroConfigPPPoEGenerator();

        // Create mock objects
        $router = new Router([
            'id' => '64a8f788-ae30-4cdf-a92c-31e1ff8c6d36',
            'tenant_id' => 'ff43d87e-3118-40b3-bb87-f14defda6655',
            'name' => 'haplite',
            'model' => 'RB941-2nD',
        ]);

        $pool = new TenantIpPool([
            'network_cidr' => '100.64.0.0/24',
            'gateway_ip' => '100.64.0.1',
            'range_start' => '100.64.0.2',
            'range_end' => '100.64.0.254',
            'dns_primary' => '8.8.8.8',
            'dns_secondary' => '8.8.4.4',
        ]);

        // Test 1: With valid interfaces
        $this->info("\n[Test 1] Valid interfaces (ether2,ether3):");
        $service1 = new RouterService([
            'id' => 'test-service-1',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => '["ether2","ether3"]',
            'vlan_required' => false,
            'deployment_status' => 'pending',
        ]);
        $service1->setRelation('router', $router);
        $service1->setRelation('ipPool', $pool);

        try {
            $config1 = $generator->generate($service1);
            $this->info("✓ Generated successfully (" . strlen($config1) . " chars)");
            if ($this->option('debug')) {
                $this->line(substr($config1, 0, 500) . "...");
            }
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        // Test 2: With empty interfaces
        $this->info("\n[Test 2] Empty interfaces:");
        $service2 = new RouterService([
            'id' => 'test-service-2',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => '',
            'vlan_required' => false,
        ]);
        $service2->setRelation('router', $router);
        $service2->setRelation('ipPool', $pool);

        try {
            $config2 = $generator->generate($service2);
            $this->info("✓ Generated (unexpected success)");
        } catch (\Exception $e) {
            $this->warn("✗ Expected failure: " . $e->getMessage());
        }

        // Test 3: With null interfaces
        $this->info("\n[Test 3] Null interfaces:");
        $service3 = new RouterService([
            'id' => 'test-service-3',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => null,
            'vlan_required' => false,
        ]);
        $service3->setRelation('router', $router);
        $service3->setRelation('ipPool', $pool);

        try {
            $config3 = $generator->generate($service3);
            $this->info("✓ Generated (unexpected success)");
        } catch (\Exception $e) {
            $this->warn("✗ Expected failure: " . $e->getMessage());
        }

        // Test 4: Without IP pool
        $this->info("\n[Test 4] Without IP pool:");
        $service4 = new RouterService([
            'id' => 'test-service-4',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => '["ether2"]',
            'vlan_required' => false,
        ]);
        $service4->setRelation('router', $router);
        $service4->setRelation('ipPool', null);

        try {
            $config4 = $generator->generate($service4);
            $this->info("✓ Generated (unexpected success)");
        } catch (\Exception $e) {
            $this->warn("✗ Expected failure: " . $e->getMessage());
        }

        // Test 5: With VLAN
        $this->info("\n[Test 5] With VLAN (ID 100):");
        $service5 = new RouterService([
            'id' => 'test-service-5',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => '["ether2"]',
            'vlan_required' => true,
            'vlan_id' => 100,
        ]);
        $service5->setRelation('router', $router);
        $service5->setRelation('ipPool', $pool);

        try {
            $config5 = $generator->generate($service5);
            $this->info("✓ Generated successfully (" . strlen($config5) . " chars)");
            if ($this->option('debug')) {
                // Check for VLAN configuration
                if (str_contains($config5, 'vlan100')) {
                    $this->info("✓ VLAN 100 configuration found");
                }
            }
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        // Test 6: Single interface string
        $this->info("\n[Test 6] Single interface string (ether2):");
        $service6 = new RouterService([
            'id' => 'test-service-6',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => 'ether2',
            'vlan_required' => false,
        ]);
        $service6->setRelation('router', $router);
        $service6->setRelation('ipPool', $pool);

        try {
            $config6 = $generator->generate($service6);
            $this->info("✓ Generated successfully (" . strlen($config6) . " chars)");
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        // Test 7: Comma-separated interfaces
        $this->info("\n[Test 7] Comma-separated interfaces (ether2,ether3,ether4):");
        $service7 = new RouterService([
            'id' => 'test-service-7',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => 'ether2,ether3,ether4',
            'vlan_required' => false,
        ]);
        $service7->setRelation('router', $router);
        $service7->setRelation('ipPool', $pool);

        try {
            $config7 = $generator->generate($service7);
            $this->info("✓ Generated successfully (" . strlen($config7) . " chars)");
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        // Test 8: Nested JSON (common issue)
        $this->info("\n[Test 8] Nested JSON format:[[\"ether2\",\"ether3\"]]:");
        $service8 = new RouterService([
            'id' => 'test-service-8',
            'router_id' => $router->id,
            'service_type' => 'pppoe',
            'interface_name' => '[["ether2","ether3"]]',
            'vlan_required' => false,
        ]);
        $service8->setRelation('router', $router);
        $service8->setRelation('ipPool', $pool);

        try {
            $config8 = $generator->generate($service8);
            $this->info("✓ Generated successfully (" . strlen($config8) . " chars)");
        } catch (\Exception $e) {
            $this->error("✗ Failed: " . $e->getMessage());
        }

        $this->info("\n=== Tests Complete ===");
        return 0;
    }
}
