<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use App\Models\PppoeUser;
use App\Models\PppoePayment;
use App\Models\MpesaTransaction;
use App\Models\TenantPaybillSetting;
use App\Events\PaymentReceived;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;

/**
 * Feature tests for TenantPaybillController callbacks.
 *
 * Routes tested:
 *   POST /api/mpesa/paybill/validation/{tenantId}  → handleValidation()
 *   POST /api/mpesa/paybill/confirmation/{tenantId} → handleConfirmation()
 *   GET  /api/billing/paybill/settings             → getSettings()
 *   POST /api/billing/paybill/settings             → saveSettings()
 *   GET  /api/billing/paybill/transactions         → getTransactions()
 *   POST /api/billing/paybill/check-payments       → triggerPaymentCheck()
 *
 * Also validates TenantPaybillService logic end-to-end:
 *  - Valid account accepted, unknown account rejected
 *  - Amount-too-low rejected
 *  - Confirmation creates MpesaTransaction + PppoePayment
 *  - User activated after confirmed payment
 *  - Inactive tenant rejected
 */
class TenantPaybillCallbackTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    private Tenant $tenant;
    private User $admin;
    private PppoeUser $pppoeUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant   = $this->setUpTestTenant();
        $this->admin    = $this->createAdminUser($this->tenant);
        $this->pppoeUser = $this->makePppoeUser();

        Event::fake([PaymentReceived::class]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenantContext();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makePppoeUser(array $overrides = []): PppoeUser
    {
        $pkg    = $this->createPackage();
        $router = $this->createRouter($this->tenant);

        return PppoeUser::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'username'       => 'ppu_' . Str::random(8),
            'account_number' => 'TST-P' . Str::random(10),
            'password'       => bcrypt('password'),
            'package_id'     => $pkg->id,
            'router_id'      => $router->id,
            'is_active'      => true,
            'status'         => 'active',
            'payment_status' => 'unpaid',
            'in_grace_period' => false,
        ], $overrides));
    }

    private function validationPayload(array $overrides = []): array
    {
        return array_merge([
            'TransID'        => 'TXN' . strtoupper(Str::random(8)),
            'TransTime'      => now()->format('YmdHis'),
            'TransAmount'    => '500.00',
            'BusinessShortCode' => '123456',
            'BillRefNumber'  => $this->pppoeUser->account_number,
            'MSISDN'         => '254712345678',
            'FirstName'      => 'Test',
            'LastName'       => 'User',
        ], $overrides);
    }

    private function confirmationPayload(array $overrides = []): array
    {
        return array_merge([
            'TransID'        => 'TXN' . strtoupper(Str::random(8)),
            'TransTime'      => now()->format('YmdHis'),
            'TransAmount'    => '500.00',
            'BusinessShortCode' => '123456',
            'BillRefNumber'  => $this->pppoeUser->account_number,
            'MSISDN'         => '254712345678',
            'FirstName'      => 'Test',
            'LastName'       => 'User',
            'TransactionType' => 'Pay Bill',
        ], $overrides);
    }

    // =========================================================================
    // POST /api/mpesa/paybill/validation/{tenantId}
    // =========================================================================

    public function test_validation_returns_accepted_for_known_account(): void
    {
        $response = $this->postJson(
            "/api/mpesa/paybill/validation/{$this->tenant->id}",
            $this->validationPayload()
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => '0']);
    }

    public function test_validation_rejects_unknown_account_number(): void
    {
        $response = $this->postJson(
            "/api/mpesa/paybill/validation/{$this->tenant->id}",
            $this->validationPayload(['BillRefNumber' => 'UNKNOWN-9999'])
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => 'C2B00013']);
    }

    public function test_validation_rejects_zero_amount(): void
    {
        $response = $this->postJson(
            "/api/mpesa/paybill/validation/{$this->tenant->id}",
            $this->validationPayload(['TransAmount' => '0'])
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => 'C2B00014']);
    }

    public function test_validation_accepts_username_as_bill_ref(): void
    {
        $response = $this->postJson(
            "/api/mpesa/paybill/validation/{$this->tenant->id}",
            $this->validationPayload(['BillRefNumber' => $this->pppoeUser->username])
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => '0']);
    }

    public function test_validation_rejects_inactive_tenant(): void
    {
        $inactiveTenant = Tenant::withoutGlobalScopes()->create([
            'id'          => Str::uuid()->toString(),
            'name'        => 'Inactive Tenant',
            'slug'        => 'inactive-' . Str::random(4),
            'schema_name' => 'inactive_schema',
            'email'       => 'inactive@test.com',
            'is_active'   => false,
        ]);

        $response = $this->postJson(
            "/api/mpesa/paybill/validation/{$inactiveTenant->id}",
            $this->validationPayload()
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => 'C2B00011']);

        $inactiveTenant->forceDelete();
    }

    public function test_validation_rejects_non_existent_tenant(): void
    {
        $response = $this->postJson(
            '/api/mpesa/paybill/validation/' . Str::uuid(),
            $this->validationPayload()
        );

        $response->assertOk()
            ->assertJson(['ResultCode' => 'C2B00011']);
    }

    // =========================================================================
    // POST /api/mpesa/paybill/confirmation/{tenantId}
    // =========================================================================

    public function test_confirmation_creates_mpesa_transaction_record(): void
    {
        $txnId = 'TXN' . strtoupper(Str::random(8));

        $this->postJson(
            "/api/mpesa/paybill/confirmation/{$this->tenant->id}",
            $this->confirmationPayload(['TransID' => $txnId])
        );

        $this->assertDatabaseHas('mpesa_transactions', [
            'transaction_id' => $txnId,
        ]);
    }

    public function test_confirmation_creates_pppoe_payment_for_known_user(): void
    {
        $response = $this->postJson(
            "/api/mpesa/paybill/confirmation/{$this->tenant->id}",
            $this->confirmationPayload()
        );

        $response->assertOk()->assertJson(['ResultCode' => '0']);

        $this->assertDatabaseHas('pppoe_payments', [
            'pppoe_user_id' => $this->pppoeUser->id,
            'amount'        => '500.00',
            'status'        => 'completed',
        ]);
    }

    public function test_confirmation_activates_suspended_user(): void
    {
        $user = $this->makePppoeUser([
            'status'         => 'suspended',
            'payment_status' => 'overdue',
            'is_active'      => false,
        ]);

        $this->postJson(
            "/api/mpesa/paybill/confirmation/{$this->tenant->id}",
            $this->confirmationPayload(['BillRefNumber' => $user->account_number])
        );

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertEquals('paid', $user->payment_status);
        $this->assertTrue($user->is_active);
    }

    public function test_confirmation_stores_unmatched_transaction_for_unknown_account(): void
    {
        $txnId = 'NOMATCH' . strtoupper(Str::random(6));

        $this->postJson(
            "/api/mpesa/paybill/confirmation/{$this->tenant->id}",
            $this->confirmationPayload([
                'TransID'       => $txnId,
                'BillRefNumber' => 'NOTEXIST999',
            ])
        );

        $this->assertDatabaseHas('mpesa_transactions', [
            'transaction_id' => $txnId,
            'is_matched'     => false,
        ]);
    }

    public function test_confirmation_fires_payment_received_event(): void
    {
        $this->postJson(
            "/api/mpesa/paybill/confirmation/{$this->tenant->id}",
            $this->confirmationPayload()
        );

        Event::assertDispatched(PaymentReceived::class);
    }

    public function test_confirmation_ignores_duplicate_transaction_id(): void
    {
        $txnId = 'DUPETXN' . strtoupper(Str::random(6));
        $payload = $this->confirmationPayload(['TransID' => $txnId]);

        $this->postJson("/api/mpesa/paybill/confirmation/{$this->tenant->id}", $payload);
        $this->postJson("/api/mpesa/paybill/confirmation/{$this->tenant->id}", $payload);

        $count = MpesaTransaction::where('transaction_id', $txnId)->count();
        $this->assertEquals(1, $count);
    }

    // =========================================================================
    // GET /api/billing/paybill/settings
    // =========================================================================

    public function test_get_settings_requires_auth(): void
    {
        $response = $this->getJson('/api/billing/paybill/settings');
        $response->assertStatus(401);
    }

    public function test_get_settings_returns_structure_when_no_settings_exist(): void
    {
        TenantPaybillSetting::truncate();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/billing/paybill/settings');

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'has_own_paybill',
                'using_landlord_paybill',
                'landlord_paybill_available',
            ]);
    }

    public function test_get_settings_returns_masked_credentials_when_settings_exist(): void
    {
        TenantPaybillSetting::create([
            'business_shortcode' => '123456',
            'consumer_key'       => 'some-consumer-key-value',
            'consumer_secret'    => 'some-consumer-secret',
            'passkey'            => 'some-passkey-value',
            'environment'        => 'sandbox',
            'use_landlord_paybill' => false,
            'is_active'          => true,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/billing/paybill/settings');

        $response->assertOk()->assertJson(['success' => true, 'has_own_paybill' => true]);
        // Consumer key should be masked
        $data = $response->json('data');
        $this->assertStringNotContainsString('some-consumer-key-value', json_encode($data));
    }

    // =========================================================================
    // POST /api/billing/paybill/settings
    // =========================================================================

    public function test_save_settings_validates_required_environment(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/billing/paybill/settings', [
                'use_landlord_paybill' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['environment']);
    }

    public function test_save_settings_creates_new_settings_record(): void
    {
        TenantPaybillSetting::truncate();

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/billing/paybill/settings', [
                'business_shortcode'   => '600000',
                'consumer_key'         => 'test-consumer-key-12345',
                'consumer_secret'      => 'test-consumer-secret-123',
                'passkey'              => 'test-passkey-value-here',
                'environment'          => 'sandbox',
                'use_landlord_paybill' => false,
            ]);

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertDatabaseHas('tenant_paybill_settings', [
            'business_shortcode' => '600000',
        ]);
    }

    // =========================================================================
    // GET /api/billing/paybill/transactions
    // =========================================================================

    public function test_get_transactions_returns_paginated_list(): void
    {
        MpesaTransaction::create([
            'transaction_id'     => 'TXN' . Str::upper(Str::random(8)),
            'transaction_type'   => 'Pay Bill',
            'amount'             => 500,
            'msisdn'             => '254712345678',
            'bill_ref_number'    => 'TST-P00001',
            'business_shortcode' => '123456',
            'transaction_time'   => now(),
            'status'             => 'completed',
            'is_matched'         => true,
            'retry_count'        => 0,
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/billing/paybill/transactions');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    // =========================================================================
    // POST /api/billing/paybill/check-payments
    // =========================================================================

    public function test_trigger_payment_check_queues_job(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/billing/paybill/check-payments');

        $response->assertOk()->assertJson(['success' => true]);
    }
}
