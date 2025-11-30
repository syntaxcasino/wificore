<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Package;
use App\Models\Router;
use Illuminate\Foundation\Testing\RefreshDatabase;

class MultiTenancyTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant1;
    protected $tenant2;
    protected $user1;
    protected $user2;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two tenants
        $this->tenant1 = Tenant::create([
            'name' => 'Tenant 1',
            'slug' => 'tenant-1',
            'is_active' => true,
        ]);

        $this->tenant2 = Tenant::create([
            'name' => 'Tenant 2',
            'slug' => 'tenant-2',
            'is_active' => true,
        ]);

        // Create users for each tenant
        $this->user1 = User::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'User 1',
            'email' => 'user1@tenant1.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->user2 = User::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'User 2',
            'email' => 'user2@tenant2.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    /** @test */
    public function tenant_isolation_works_for_packages()
    {
        // Create packages for each tenant
        $package1 = Package::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Package 1',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $package2 = Package::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Package 2',
            'type' => 'hotspot',
            'price' => 200,
            'devices' => 1,
            'speed' => '20M',
            'upload_speed' => '20M',
            'download_speed' => '20M',
            'duration' => '30d',
        ]);

        // User 1 should only see their tenant's packages
        $this->actingAs($this->user1, 'sanctum');
        $packages = Package::all();
        $this->assertCount(1, $packages);
        $this->assertEquals($package1->id, $packages->first()->id);

        // User 2 should only see their tenant's packages
        $this->actingAs($this->user2, 'sanctum');
        $packages = Package::all();
        $this->assertCount(1, $packages);
        $this->assertEquals($package2->id, $packages->first()->id);
    }

    /** @test */
    public function tenant_isolation_works_for_routers()
    {
        // Create routers for each tenant
        $router1 = Router::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Router 1',
            'ip_address' => '192.168.1.1',
            'port' => 8728,
            'username' => 'admin',
            'password' => 'password',
        ]);

        $router2 = Router::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Router 2',
            'ip_address' => '192.168.2.1',
            'port' => 8728,
            'username' => 'admin',
            'password' => 'password',
        ]);

        // User 1 should only see their tenant's routers
        $this->actingAs($this->user1, 'sanctum');
        $routers = Router::all();
        $this->assertCount(1, $routers);
        $this->assertEquals($router1->id, $routers->first()->id);

        // User 2 should only see their tenant's routers
        $this->actingAs($this->user2, 'sanctum');
        $routers = Router::all();
        $this->assertCount(1, $routers);
        $this->assertEquals($router2->id, $routers->first()->id);
    }

    /** @test */
    public function automatic_tenant_assignment_on_create()
    {
        $this->actingAs($this->user1, 'sanctum');

        $package = Package::create([
            'name' => 'Auto Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $this->assertEquals($this->tenant1->id, $package->tenant_id);
    }

    /** @test */
    public function suspended_tenant_cannot_access_system()
    {
        $this->tenant1->suspend('Non-payment');

        $response = $this->actingAs($this->user1, 'sanctum')
            ->getJson('/api/packages');

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Tenant account is suspended or inactive',
            ]);
    }

    /** @test */
    public function admin_can_create_tenant()
    {
        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson('/api/tenants', [
                'name' => 'New Tenant',
                'email' => 'admin@newtenant.com',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Tenant created successfully',
            ]);

        $this->assertDatabaseHas('tenants', [
            'name' => 'New Tenant',
            'email' => 'admin@newtenant.com',
        ]);
    }

    /** @test */
    public function admin_can_suspend_tenant()
    {
        $response = $this->actingAs($this->user1, 'sanctum')
            ->postJson("/api/tenants/{$this->tenant2->id}/suspend", [
                'reason' => 'Test suspension',
            ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Tenant suspended successfully',
            ]);

        $this->tenant2->refresh();
        $this->assertNotNull($this->tenant2->suspended_at);
        $this->assertEquals('Test suspension', $this->tenant2->suspension_reason);
    }

    /** @test */
    public function user_can_get_current_tenant_info()
    {
        $response = $this->actingAs($this->user1, 'sanctum')
            ->getJson('/api/tenant/current');

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'tenant' => [
                    'id' => $this->tenant1->id,
                    'name' => 'Tenant 1',
                    'slug' => 'tenant-1',
                ],
            ]);
    }

    /** @test */
    public function cannot_access_other_tenant_data()
    {
        $package = Package::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Other Tenant Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $this->actingAs($this->user1, 'sanctum');

        // Try to access package from different tenant
        $foundPackage = Package::find($package->id);
        $this->assertNull($foundPackage);
    }
}
