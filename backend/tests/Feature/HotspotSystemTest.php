<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\HotspotUser;
use App\Models\Package;
use App\Models\Router;
use App\Models\RadiusSession;
use App\Jobs\CreateHotspotUserJob;
use App\Jobs\DisconnectHotspotUserJob;
use App\Jobs\GrantHotspotAccessJob;
use App\Jobs\CheckHotspotExpirationsJob;
use App\Events\HotspotAccessGranted;
use App\Events\HotspotAccessRevoked;
use App\Events\HotspotPackageExpired;
use App\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

class HotspotSystemTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $admin;
    protected Package $package;
    protected Router $router;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenant
        $this->tenant = Tenant::factory()->create([
            'is_active' => true,
            'schema_created' => true,
        ]);

        // Set tenant context
        app(TenantContext::class)->setTenant($this->tenant);

        // Create admin user
        $this->admin = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create hotspot package
        $this->package = Package::factory()->create([
            'tenant_id' => $this->tenant->id,
            'type' => 'hotspot',
            'name' => 'Daily Package',
            'price' => 50,
            'duration' => '24h',
            'upload_speed' => '5M',
            'download_speed' => '10M',
            'is_active' => true,
            'is_public' => true,
        ]);

        // Create router
        $this->router = Router::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Test Router',
            'status' => 'online',
        ]);
    }

    /** @test */
    public function admin_can_list_hotspot_users()
    {
        // Create some hotspot users
        HotspotUser::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/hotspot/users');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);
    }

    /** @test */
    public function admin_can_view_hotspot_user_details()
    {
        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_test123',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson("/api/hotspot/users/{$user->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.username', 'hs_test123');
    }

    /** @test */
    public function admin_can_disconnect_hotspot_user()
    {
        Queue::fake();

        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'active',
            'has_active_subscription' => true,
        ]);

        $session = RadiusSession::factory()->create([
            'hotspot_user_id' => $user->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/hotspot/users/{$user->id}/disconnect", [
                'reason' => 'Test disconnect',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        Queue::assertPushed(DisconnectHotspotUserJob::class);
    }

    /** @test */
    public function admin_can_grant_access_to_user()
    {
        Queue::fake();

        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'expired',
            'has_active_subscription' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/hotspot/users/{$user->id}/grant-access", [
                'package_id' => $this->package->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        Queue::assertPushed(GrantHotspotAccessJob::class);
    }

    /** @test */
    public function admin_can_revoke_access_from_user()
    {
        Event::fake();
        Queue::fake();

        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_revoke_test',
            'status' => 'active',
            'has_active_subscription' => true,
        ]);

        // Create radcheck entry
        DB::table('radcheck')->insert([
            'username' => 'hs_revoke_test',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'testpass',
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson("/api/hotspot/users/{$user->id}/revoke-access", [
                'reason' => 'Test revoke',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Check user status updated
        $user->refresh();
        $this->assertEquals('revoked', $user->status);
        $this->assertFalse($user->has_active_subscription);

        // Check RADIUS block added
        $this->assertDatabaseHas('radcheck', [
            'username' => 'hs_revoke_test',
            'attribute' => 'Auth-Type',
            'value' => 'Reject',
        ]);

        Event::assertDispatched(HotspotAccessRevoked::class);
    }

    /** @test */
    public function admin_can_list_active_sessions()
    {
        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        RadiusSession::factory()->count(3)->create([
            'hotspot_user_id' => $user->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/hotspot/sessions?active_only=1');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function admin_can_get_hotspot_statistics()
    {
        HotspotUser::factory()->count(5)->create([
            'tenant_id' => $this->tenant->id,
            'has_active_subscription' => true,
        ]);

        HotspotUser::factory()->count(3)->create([
            'tenant_id' => $this->tenant->id,
            'status' => 'expired',
            'has_active_subscription' => false,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/hotspot/stats');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'total_users',
                    'active_users',
                    'expired_users',
                    'active_sessions',
                ],
            ]);
    }

    /** @test */
    public function captive_portal_returns_config_for_valid_router()
    {
        $response = $this->getJson("/api/portal/config?router_id={$this->router->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'tenant',
                    'router',
                    'packages',
                    'payment_methods',
                ],
            ]);
    }

    /** @test */
    public function captive_portal_returns_404_for_invalid_router()
    {
        $response = $this->getJson('/api/portal/config?router_id=invalid-uuid');

        $response->assertStatus(404);
    }

    /** @test */
    public function captive_portal_login_validates_credentials()
    {
        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_login_test',
            'status' => 'active',
            'has_active_subscription' => true,
            'subscription_expires_at' => now()->addDay(),
        ]);

        // Create radcheck entry
        DB::table('radcheck')->insert([
            'username' => 'hs_login_test',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'testpass123',
        ]);

        $response = $this->postJson('/api/portal/login', [
            'router_id' => $this->router->id,
            'username' => 'hs_login_test',
            'password' => 'testpass123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function captive_portal_rejects_expired_subscription()
    {
        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_expired_test',
            'status' => 'active',
            'has_active_subscription' => false,
            'subscription_expires_at' => now()->subDay(),
        ]);

        DB::table('radcheck')->insert([
            'username' => 'hs_expired_test',
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'testpass',
        ]);

        $response = $this->postJson('/api/portal/login', [
            'router_id' => $this->router->id,
            'username' => 'hs_expired_test',
            'password' => 'testpass',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'SUBSCRIPTION_EXPIRED');
    }

    /** @test */
    public function grant_access_job_updates_radius_and_user()
    {
        Event::fake();

        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_grant_test',
            'status' => 'expired',
            'has_active_subscription' => false,
        ]);

        // Add blocked entry
        DB::table('radcheck')->insert([
            'username' => 'hs_grant_test',
            'attribute' => 'Auth-Type',
            'op' => ':=',
            'value' => 'Reject',
        ]);

        // Run job
        $job = new GrantHotspotAccessJob(
            $user->id,
            $this->tenant->id,
            $this->package->id,
            'test'
        );
        $job->handle();

        // Check block removed
        $this->assertDatabaseMissing('radcheck', [
            'username' => 'hs_grant_test',
            'attribute' => 'Auth-Type',
            'value' => 'Reject',
        ]);

        // Check user updated
        $user->refresh();
        $this->assertTrue($user->has_active_subscription);
        $this->assertEquals('active', $user->status);

        Event::assertDispatched(HotspotAccessGranted::class);
    }

    /** @test */
    public function expiration_job_blocks_expired_users()
    {
        Event::fake();
        Queue::fake();

        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
            'username' => 'hs_expire_test',
            'status' => 'active',
            'has_active_subscription' => true,
            'subscription_expires_at' => now()->subHour(),
        ]);

        // Run expiration check
        $job = new CheckHotspotExpirationsJob($this->tenant->id);
        $job->handle(app(TenantContext::class));

        // Check user blocked
        $user->refresh();
        $this->assertFalse($user->has_active_subscription);
        $this->assertEquals('expired', $user->status);

        // Check RADIUS block added
        $this->assertDatabaseHas('radcheck', [
            'username' => 'hs_expire_test',
            'attribute' => 'Auth-Type',
            'value' => 'Reject',
        ]);

        Event::assertDispatched(HotspotPackageExpired::class);
    }

    /** @test */
    public function tenant_isolation_is_enforced()
    {
        // Create another tenant
        $otherTenant = Tenant::factory()->create([
            'is_active' => true,
            'schema_created' => true,
        ]);

        $otherAdmin = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create user in first tenant
        $user = HotspotUser::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        // Try to access from other tenant
        $response = $this->actingAs($otherAdmin)
            ->getJson("/api/hotspot/users/{$user->id}");

        // Should not find the user (tenant isolation)
        $response->assertStatus(404);
    }

    /** @test */
    public function unauthenticated_users_cannot_access_admin_endpoints()
    {
        $response = $this->getJson('/api/hotspot/users');

        $response->assertStatus(401);
    }

    /** @test */
    public function non_admin_users_cannot_access_admin_endpoints()
    {
        $regularUser = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'role' => 'hotspot_user',
            'is_active' => true,
        ]);

        $response = $this->actingAs($regularUser)
            ->getJson('/api/hotspot/users');

        $response->assertStatus(403);
    }
}
