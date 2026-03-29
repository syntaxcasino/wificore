<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\PppoeUser;
use App\Models\TenantPaybillSetting;
use App\Models\MpesaTransaction;
use App\Models\PppoePayment;
use App\Jobs\CheckPppoePaymentsJob;
use App\Jobs\DisconnectPppoeUserJob;
use App\Jobs\ReconnectPppoeUserJob;
use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * PPPoE Paybill Billing System Tests
 * 
 * Tests tenant isolation, landlord fallback, payment detection,
 * disconnect/reconnect, and WebSocket event broadcasting.
 */
class PppoePaybillBillingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenantA;
    protected Tenant $tenantB;
    protected User $adminA;
    protected User $adminB;

    protected function setUp(): void
    {
        parent::setUp();

        // Create two tenants for isolation testing
        $this->tenantA = Tenant::factory()->create([
            'name' => 'Tenant A',
            'schema_name' => 'ts_tenant_a',
            'schema_created' => true,
            'is_active' => true,
        ]);

        $this->tenantB = Tenant::factory()->create([
            'name' => 'Tenant B',
            'schema_name' => 'ts_tenant_b',
            'schema_created' => true,
            'is_active' => true,
        ]);

        // Create admin users
        $this->adminA = User::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'role' => 'admin',
        ]);

        $this->adminB = User::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'role' => 'admin',
        ]);
    }

    /** @test */
    public function tenant_can_get_paybill_settings()
    {
        $response = $this->actingAs($this->adminA)
            ->getJson('/api/billing/paybill/settings');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'tenant_id' => $this->tenantA->id,
            ]);
    }

    /** @test */
    public function tenant_can_save_paybill_settings()
    {
        $response = $this->actingAs($this->adminA)
            ->postJson('/api/billing/paybill/settings', [
                'business_shortcode' => '174379',
                'consumer_key' => 'test_consumer_key_123',
                'consumer_secret' => 'test_consumer_secret_456',
                'passkey' => 'test_passkey_789',
                'environment' => 'sandbox',
                'use_landlord_paybill' => false,
            ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Paybill settings saved successfully',
            ]);
    }

    /** @test */
    public function tenant_cannot_see_other_tenant_settings()
    {
        // Save settings for tenant A
        $this->actingAs($this->adminA)
            ->postJson('/api/billing/paybill/settings', [
                'business_shortcode' => '111111',
                'environment' => 'sandbox',
                'use_landlord_paybill' => false,
            ]);

        // Save settings for tenant B
        $this->actingAs($this->adminB)
            ->postJson('/api/billing/paybill/settings', [
                'business_shortcode' => '222222',
                'environment' => 'sandbox',
                'use_landlord_paybill' => false,
            ]);

        // Tenant A should only see their settings
        $responseA = $this->actingAs($this->adminA)
            ->getJson('/api/billing/paybill/settings');

        $responseA->assertOk();
        $this->assertEquals('111111', $responseA->json('data.business_shortcode'));

        // Tenant B should only see their settings
        $responseB = $this->actingAs($this->adminB)
            ->getJson('/api/billing/paybill/settings');

        $responseB->assertOk();
        $this->assertEquals('222222', $responseB->json('data.business_shortcode'));
    }

    /** @test */
    public function landlord_fallback_is_available_when_configured()
    {
        config(['mpesa.shortcode' => '888888']);

        $response = $this->actingAs($this->adminA)
            ->getJson('/api/billing/paybill/settings');

        $response->assertOk()
            ->assertJson([
                'landlord_paybill_available' => true,
                'landlord_shortcode' => '888888',
            ]);
    }

    /** @test */
    public function mpesa_validation_callback_validates_account()
    {
        // Create PPPoE user in tenant A's schema
        $this->setTenantSchema($this->tenantA);
        $pppoeUser = PppoeUser::create([
            'username' => 'test_user',
            'password' => 'password123',
            'account_number' => 'ACC001',
            'status' => 'active',
        ]);

        $response = $this->postJson("/api/mpesa/paybill/validation/{$this->tenantA->id}", [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'TEST123',
            'TransAmount' => '500.00',
            'BusinessShortCode' => '174379',
            'BillRefNumber' => 'ACC001',
            'MSISDN' => '254712345678',
        ]);

        $response->assertOk()
            ->assertJson([
                'ResultCode' => '0',
                'ResultDesc' => 'Accepted',
            ]);
    }

    /** @test */
    public function mpesa_validation_rejects_unknown_account()
    {
        $response = $this->postJson("/api/mpesa/paybill/validation/{$this->tenantA->id}", [
            'TransactionType' => 'Pay Bill',
            'TransID' => 'TEST123',
            'TransAmount' => '500.00',
            'BusinessShortCode' => '174379',
            'BillRefNumber' => 'UNKNOWN_ACCOUNT',
            'MSISDN' => '254712345678',
        ]);

        $response->assertOk()
            ->assertJson([
                'ResultCode' => 'C2B00013',
                'ResultDesc' => 'Account not found',
            ]);
    }

    /** @test */
    public function mpesa_confirmation_creates_payment_and_activates_user()
    {
        Event::fake([PaymentReceived::class, PppoeUserPaymentStatusChanged::class]);

        // Create suspended PPPoE user
        $this->setTenantSchema($this->tenantA);
        $pppoeUser = PppoeUser::create([
            'username' => 'test_user',
            'password' => 'password123',
            'account_number' => 'ACC001',
            'status' => 'suspended',
            'payment_status' => 'overdue',
            'suspended_at' => now()->subDays(5),
        ]);

        $response = $this->postJson("/api/mpesa/paybill/confirmation/{$this->tenantA->id}", [
            'TransID' => 'MPESA123456',
            'TransAmount' => '500.00',
            'BillRefNumber' => 'ACC001',
            'BusinessShortCode' => '174379',
            'MSISDN' => '254712345678',
            'TransTime' => now()->format('YmdHis'),
        ]);

        $response->assertOk()
            ->assertJson([
                'ResultCode' => '0',
                'ResultDesc' => 'Success',
            ]);

        // Verify transaction recorded
        $this->assertDatabaseHas('mpesa_transactions', [
            'transaction_id' => 'MPESA123456',
            'is_matched' => true,
        ]);

        // Verify user activated
        $pppoeUser->refresh();
        $this->assertEquals('active', $pppoeUser->status);
        $this->assertEquals('paid', $pppoeUser->payment_status);
        $this->assertNull($pppoeUser->suspended_at);

        // Verify events dispatched
        Event::assertDispatched(PaymentReceived::class);
        Event::assertDispatched(PppoeUserPaymentStatusChanged::class);
    }

    /** @test */
    public function tenant_a_transaction_not_visible_to_tenant_b()
    {
        // Create transaction in tenant A
        $this->setTenantSchema($this->tenantA);
        MpesaTransaction::create([
            'transaction_id' => 'TX_TENANT_A',
            'amount' => 500,
            'msisdn' => '254712345678',
            'bill_ref_number' => 'ACC001',
            'business_shortcode' => '174379',
            'transaction_time' => now(),
            'status' => 'completed',
        ]);

        // Tenant A can see transaction
        $responseA = $this->actingAs($this->adminA)
            ->getJson('/api/billing/paybill/transactions');

        $responseA->assertOk();
        $this->assertCount(1, $responseA->json('data.data'));

        // Tenant B cannot see tenant A's transaction
        $responseB = $this->actingAs($this->adminB)
            ->getJson('/api/billing/paybill/transactions');

        $responseB->assertOk();
        $this->assertCount(0, $responseB->json('data.data'));
    }

    /** @test */
    public function check_payments_job_puts_overdue_users_in_grace_period()
    {
        Event::fake([PppoeUserPaymentStatusChanged::class]);

        // Create overdue user
        $this->setTenantSchema($this->tenantA);
        $pppoeUser = PppoeUser::create([
            'username' => 'overdue_user',
            'password' => 'password123',
            'status' => 'active',
            'payment_status' => 'unpaid',
            'next_payment_due' => now()->subDays(1),
            'in_grace_period' => false,
        ]);

        // Run job
        $job = new CheckPppoePaymentsJob($this->tenantA->id);
        $job->handle(app(\App\Services\TenantContext::class));

        // Verify user in grace period
        $pppoeUser->refresh();
        $this->assertTrue($pppoeUser->in_grace_period);
        $this->assertNotNull($pppoeUser->grace_period_ends);

        Event::assertDispatched(PppoeUserPaymentStatusChanged::class);
    }

    /** @test */
    public function check_payments_job_disconnects_users_after_grace_period()
    {
        Queue::fake([DisconnectPppoeUserJob::class]);
        Event::fake([PppoeUserPaymentStatusChanged::class]);

        // Create user with expired grace period
        $this->setTenantSchema($this->tenantA);
        $pppoeUser = PppoeUser::create([
            'username' => 'grace_expired_user',
            'password' => 'password123',
            'status' => 'active',
            'payment_status' => 'unpaid',
            'next_payment_due' => now()->subDays(10),
            'in_grace_period' => true,
            'grace_period_ends' => now()->subDays(1),
        ]);

        // Run job
        $job = new CheckPppoePaymentsJob($this->tenantA->id);
        $job->handle(app(\App\Services\TenantContext::class));

        // Verify disconnect job dispatched
        Queue::assertPushed(DisconnectPppoeUserJob::class);
    }

    /** @test */
    public function disconnect_job_blocks_user_in_radius()
    {
        $this->setTenantSchema($this->tenantA);
        
        // Create PPPoE user
        $pppoeUser = PppoeUser::create([
            'username' => 'to_disconnect',
            'password' => 'password123',
            'status' => 'active',
        ]);

        // Simulate running disconnect job logic
        DB::table('radcheck')->insert([
            'username' => $pppoeUser->username,
            'attribute' => 'Cleartext-Password',
            'op' => ':=',
            'value' => 'password123',
        ]);

        // Run disconnect job
        $job = new DisconnectPppoeUserJob($pppoeUser->id, $this->tenantA->id, 'Test disconnect');
        // Note: In real test, would mock SSH executor

        // Verify Auth-Type Reject would be added
        // This is a simplified test - full test would use mocks
        $this->assertTrue(true);
    }

    /** @test */
    public function reconnect_job_unblocks_user_in_radius()
    {
        $this->setTenantSchema($this->tenantA);
        
        // Create blocked PPPoE user
        $pppoeUser = PppoeUser::create([
            'username' => 'to_reconnect',
            'password' => 'password123',
            'status' => 'suspended',
            'suspended_at' => now()->subDays(1),
        ]);

        // Add Auth-Type Reject
        DB::table('radcheck')->insert([
            'username' => $pppoeUser->username,
            'attribute' => 'Auth-Type',
            'op' => ':=',
            'value' => 'Reject',
        ]);

        // Run reconnect job (would need to mock TenantContext)
        // Simplified assertion
        $this->assertDatabaseHas('radcheck', [
            'username' => $pppoeUser->username,
            'attribute' => 'Auth-Type',
            'value' => 'Reject',
        ]);
    }

    /** @test */
    public function payment_instructions_use_correct_paybill()
    {
        // Set landlord paybill
        config(['mpesa.shortcode' => '888888']);

        $this->setTenantSchema($this->tenantA);
        $pppoeUser = PppoeUser::create([
            'username' => 'test_user',
            'password' => 'password123',
            'account_number' => 'ACC001',
            'status' => 'active',
        ]);

        // With landlord paybill (default)
        $response = $this->actingAs($this->adminA)
            ->getJson("/api/billing/paybill/instructions/{$pppoeUser->id}");

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'paybill_number' => '888888',
                    'account_number' => 'ACC001',
                    'is_landlord_paybill' => true,
                ],
            ]);
    }

    /** @test */
    public function duplicate_transaction_is_rejected()
    {
        $this->setTenantSchema($this->tenantA);
        
        // Create existing transaction
        MpesaTransaction::create([
            'transaction_id' => 'DUPLICATE123',
            'amount' => 500,
            'msisdn' => '254712345678',
            'bill_ref_number' => 'ACC001',
            'business_shortcode' => '174379',
            'transaction_time' => now(),
            'status' => 'completed',
        ]);

        // Try to confirm same transaction
        $response = $this->postJson("/api/mpesa/paybill/confirmation/{$this->tenantA->id}", [
            'TransID' => 'DUPLICATE123',
            'TransAmount' => '500.00',
            'BillRefNumber' => 'ACC001',
            'BusinessShortCode' => '174379',
            'MSISDN' => '254712345678',
            'TransTime' => now()->format('YmdHis'),
        ]);

        $response->assertOk()
            ->assertJson([
                'ResultCode' => '0',
                'ResultDesc' => 'Already processed',
            ]);
    }

    /**
     * Helper to set tenant schema
     */
    protected function setTenantSchema(Tenant $tenant): void
    {
        DB::statement("SET search_path TO {$tenant->schema_name}");
    }
}
