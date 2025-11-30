<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Package;
use App\Jobs\CheckExpiredSubscriptionsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

class TenantAwareJobsTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant1;
    protected $tenant2;

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
    }

    /** @test */
    public function job_processes_only_specified_tenant_data()
    {
        // Create users for each tenant
        $user1 = User::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'User 1',
            'email' => 'user1@tenant1.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $user2 = User::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'User 2',
            'email' => 'user2@tenant2.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

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

        // Create expired subscriptions for each tenant
        $sub1 = UserSubscription::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $user1->id,
            'package_id' => $package1->id,
            'mac_address' => '00:11:22:33:44:55',
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDay(),
            'status' => 'active',
        ]);

        $sub2 = UserSubscription::create([
            'tenant_id' => $this->tenant2->id,
            'user_id' => $user2->id,
            'package_id' => $package2->id,
            'mac_address' => '00:11:22:33:44:66',
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDay(),
            'status' => 'active',
        ]);

        // Dispatch job for tenant 1 only
        $job = new CheckExpiredSubscriptionsJob($this->tenant1->id);
        $job->handle(app(\App\Services\SubscriptionManager::class));

        // Verify tenant 1's subscription was processed
        $sub1->refresh();
        $this->assertEquals('expired', $sub1->status);

        // Verify tenant 2's subscription was NOT touched
        $sub2->refresh();
        $this->assertEquals('active', $sub2->status);
    }

    /** @test */
    public function job_fails_if_tenant_not_found()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tenant not found');

        $job = new CheckExpiredSubscriptionsJob('non-existent-uuid');
        $job->handle(app(\App\Services\SubscriptionManager::class));
    }

    /** @test */
    public function job_fails_if_tenant_suspended()
    {
        // Suspend tenant
        $this->tenant1->suspend('Non-payment');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tenant is not active');

        $job = new CheckExpiredSubscriptionsJob($this->tenant1->id);
        $job->handle(app(\App\Services\SubscriptionManager::class));
    }

    /** @test */
    public function job_includes_tenant_in_tags()
    {
        $job = new CheckExpiredSubscriptionsJob($this->tenant1->id);
        
        $tags = $job->tags();

        $this->assertContains('tenant:' . $this->tenant1->id, $tags);
    }

    /** @test */
    public function multiple_jobs_can_process_different_tenants_simultaneously()
    {
        Queue::fake();

        // Dispatch jobs for both tenants
        CheckExpiredSubscriptionsJob::dispatch($this->tenant1->id);
        CheckExpiredSubscriptionsJob::dispatch($this->tenant2->id);

        // Verify both jobs were dispatched
        Queue::assertPushed(CheckExpiredSubscriptionsJob::class, 2);

        // Verify they have different tenant contexts
        Queue::assertPushed(CheckExpiredSubscriptionsJob::class, function ($job) {
            return $job->tenantId === $this->tenant1->id;
        });

        Queue::assertPushed(CheckExpiredSubscriptionsJob::class, function ($job) {
            return $job->tenantId === $this->tenant2->id;
        });
    }

    /** @test */
    public function job_automatically_gets_tenant_from_authenticated_user()
    {
        $user = User::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Test User',
            'email' => 'test@tenant1.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->actingAs($user);

        // Create job without specifying tenant
        $job = new CheckExpiredSubscriptionsJob();

        // Should automatically get tenant from authenticated user
        $this->assertEquals($this->tenant1->id, $job->tenantId);
    }
}
