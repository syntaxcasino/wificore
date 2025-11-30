<?php

namespace Tests\Unit\Services;

use App\Services\SubscriptionManager;
use App\Models\UserSubscription;

class SubscriptionManagerTest extends TenantAwareServiceTest
{
    private $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new SubscriptionManager();
    }

    public function test_cannot_access_other_tenant_resources()
    {
        $this->actingAs($this->userA);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to this tenant');

        // Try to create subscription with Tenant B's package
        $this->service->createSubscription($this->userA, $this->packageB);
    }

    public function test_can_access_own_tenant_resources()
    {
        $this->actingAs($this->userA);

        $subscription = $this->service->createSubscription($this->userA, $this->packageA);

        $this->assertInstanceOf(UserSubscription::class, $subscription);
        $this->assertEquals($this->tenantA->id, $subscription->tenant_id);
        $this->assertEquals($this->userA->id, $subscription->user_id);
        $this->assertEquals($this->packageA->id, $subscription->package_id);
    }

    public function test_validation_throws_exception_for_wrong_tenant()
    {
        $this->actingAs($this->userA);

        $this->expectException(\Exception::class);

        // Create subscription for user from different tenant
        $this->service->createSubscription($this->userB, $this->packageA);
    }

    public function test_renew_subscription_validates_tenant()
    {
        $this->actingAs($this->userA);

        // Create subscription for Tenant B
        $subscriptionB = UserSubscription::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'user_id' => $this->userB->id,
            'package_id' => $this->packageB->id
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not belong to this tenant');

        // Try to renew Tenant B's subscription while logged in as Tenant A
        $this->service->renewSubscription($subscriptionB);
    }
}
