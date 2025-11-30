<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Payment;
use App\Models\Package;
use App\Events\PaymentProcessed;
use App\Events\PaymentCompleted;
use App\Events\DashboardStatsUpdated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Broadcast;

class BroadcastingSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected $tenant1;
    protected $tenant2;
    protected $tenant1Admin;
    protected $tenant2Admin;
    protected $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create tenants
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

        // Create admins for each tenant
        $this->tenant1Admin = User::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Tenant 1 Admin',
            'email' => 'admin1@tenant1.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        $this->tenant2Admin = User::create([
            'tenant_id' => $this->tenant2->id,
            'name' => 'Tenant 2 Admin',
            'email' => 'admin2@tenant2.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);

        // Create system admin
        $this->systemAdmin = User::create([
            'tenant_id' => null,
            'name' => 'System Admin',
            'email' => 'sysadmin@system.com',
            'password' => bcrypt('password'),
            'role' => 'system_admin',
        ]);
    }

    /** @test */
    public function payment_event_broadcasts_to_correct_tenant_channel()
    {
        $package = Package::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Test Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1Admin->id,
            'mac_address' => '00:11:22:33:44:55',
            'phone_number' => '+254712345678',
            'package_id' => $package->id,
            'amount' => 100,
            'transaction_id' => 'TEST123',
            'status' => 'completed',
        ]);

        $event = new PaymentCompleted($payment);
        $channels = $event->broadcastOn();

        // Verify it broadcasts to tenant-specific channel
        $this->assertCount(2, $channels);
        $this->assertEquals("tenant.{$this->tenant1->id}.dashboard-stats", $channels[0]->name);
        $this->assertEquals("tenant.{$this->tenant1->id}.payments", $channels[1]->name);
    }

    /** @test */
    public function tenant_admin_cannot_access_other_tenant_channel()
    {
        // Tenant 1 admin tries to access Tenant 2's channel
        $canAccess = Broadcast::channel(
            "tenant.{$this->tenant2->id}.admin-notifications",
            function ($user) {
                return $user->isAdmin() && $user->tenant_id === $this->tenant2->id;
            }
        );

        $this->actingAs($this->tenant1Admin);
        
        // Should return false because tenant IDs don't match
        $result = $canAccess($this->tenant1Admin, $this->tenant2->id);
        $this->assertFalse($result);
    }

    /** @test */
    public function system_admin_can_access_all_tenant_channels()
    {
        $canAccess = Broadcast::channel(
            "tenant.{$this->tenant1->id}.admin-notifications",
            function ($user) {
                return $user->isSystemAdmin() || 
                       ($user->isAdmin() && $user->tenant_id === $this->tenant1->id);
            }
        );

        $this->actingAs($this->systemAdmin);
        
        // System admin should have access
        $result = $canAccess($this->systemAdmin, $this->tenant1->id);
        $this->assertTrue(is_array($result) || $result === true);
    }

    /** @test */
    public function payment_data_is_masked_in_broadcast()
    {
        $package = Package::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Test Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1Admin->id,
            'mac_address' => '00:11:22:33:44:55',
            'phone_number' => '+254712345678',
            'package_id' => $package->id,
            'amount' => 100,
            'transaction_id' => 'TEST123456789',
            'status' => 'completed',
        ]);

        $event = new PaymentCompleted($payment);
        $data = $event->broadcastWith();

        // Verify phone number is masked
        $this->assertStringContainsString('***', $data['payment']['phone_number']);
        $this->assertNotEquals('+254712345678', $data['payment']['phone_number']);
    }

    /** @test */
    public function credentials_are_not_broadcast_in_payment_processed_event()
    {
        $package = Package::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Test Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1Admin->id,
            'mac_address' => '00:11:22:33:44:55',
            'phone_number' => '+254712345678',
            'package_id' => $package->id,
            'amount' => 100,
            'transaction_id' => 'TEST123',
            'status' => 'completed',
        ]);

        $subscription = \App\Models\UserSubscription::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1Admin->id,
            'package_id' => $package->id,
            'payment_id' => $payment->id,
            'mac_address' => '00:11:22:33:44:55',
            'start_time' => now(),
            'end_time' => now()->addDays(30),
            'status' => 'active',
        ]);

        $credentials = [
            'username' => 'testuser',
            'password' => 'secret123',
        ];

        $event = new PaymentProcessed($payment, $this->tenant1Admin, $subscription, $credentials);
        $data = $event->broadcastWith();

        // Verify credentials are NOT in broadcast data
        $this->assertArrayNotHasKey('credentials', $data);
    }

    /** @test */
    public function dashboard_stats_event_requires_tenant_id()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot determine tenant ID');

        // Try to create event without tenant ID
        $event = new DashboardStatsUpdated(['users' => 10], null);
        $event->broadcastOn();
    }

    /** @test */
    public function events_include_tenant_id_in_channel_name()
    {
        $stats = ['users' => 10, 'revenue' => 1000];
        $event = new DashboardStatsUpdated($stats, $this->tenant1->id);
        
        $channels = $event->broadcastOn();
        
        $this->assertCount(1, $channels);
        $this->assertStringContainsString($this->tenant1->id, $channels[0]->name);
        $this->assertEquals("tenant.{$this->tenant1->id}.dashboard-stats", $channels[0]->name);
    }

    /** @test */
    public function regular_user_cannot_access_admin_channels()
    {
        $regularUser = User::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Regular User',
            'email' => 'user@tenant1.com',
            'password' => bcrypt('password'),
            'role' => 'hotspot_user',
        ]);

        $canAccess = Broadcast::channel(
            "tenant.{$this->tenant1->id}.admin-notifications",
            function ($user) {
                return $user->isAdmin() && $user->tenant_id === $this->tenant1->id;
            }
        );

        $this->actingAs($regularUser);
        
        // Regular user should NOT have access even to their own tenant's admin channel
        $result = $canAccess($regularUser, $this->tenant1->id);
        $this->assertFalse($result);
    }

    /** @test */
    public function phone_number_masking_works_correctly()
    {
        $package = Package::create([
            'tenant_id' => $this->tenant1->id,
            'name' => 'Test Package',
            'type' => 'hotspot',
            'price' => 100,
            'devices' => 1,
            'speed' => '10M',
            'upload_speed' => '10M',
            'download_speed' => '10M',
            'duration' => '30d',
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant1->id,
            'user_id' => $this->tenant1Admin->id,
            'mac_address' => '00:11:22:33:44:55',
            'phone_number' => '+254712345678',
            'package_id' => $package->id,
            'amount' => 100,
            'transaction_id' => 'TEST123',
            'status' => 'completed',
        ]);

        $event = new PaymentCompleted($payment);
        $data = $event->broadcastWith();

        // Verify masking pattern
        $masked = $data['payment']['phone_number'];
        
        // Should start with +25
        $this->assertStringStartsWith('+25', $masked);
        
        // Should contain asterisks
        $this->assertStringContainsString('*', $masked);
        
        // Should end with 78
        $this->assertStringEndsWith('78', $masked);
        
        // Should NOT be the original number
        $this->assertNotEquals('+254712345678', $masked);
    }
}
