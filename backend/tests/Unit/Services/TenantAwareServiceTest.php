<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use App\Models\Payment;
use App\Models\Voucher;
use App\Models\HotspotUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base test class for all tenant-aware services
 * Extend this class for each service test
 */
abstract class TenantAwareServiceTest extends TestCase
{
    use RefreshDatabase;

    protected $tenantA;
    protected $tenantB;
    protected $userA;
    protected $userB;
    protected $packageA;
    protected $packageB;
    protected $routerA;
    protected $routerB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two tenants
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);

        // Create users for each tenant
        $this->userA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin'
        ]);

        $this->userB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin'
        ]);

        // Create packages for each tenant
        $this->packageA = Package::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Package A'
        ]);

        $this->packageB = Package::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Package B'
        ]);

        // Create routers for each tenant
        $this->routerA = Router::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Router A'
        ]);

        $this->routerB = Router::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Router B'
        ]);
    }

    /**
     * Test that service cannot access resources from other tenant
     */
    abstract public function test_cannot_access_other_tenant_resources();

    /**
     * Test that service can access own tenant resources
     */
    abstract public function test_can_access_own_tenant_resources();

    /**
     * Test that validation throws exception for wrong tenant
     */
    abstract public function test_validation_throws_exception_for_wrong_tenant();
}
