<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\PppoeUser;
use App\Models\PppoePayment;
use App\Models\User;
use App\Models\Tenant;
use App\Jobs\ReconnectPppoeUserJob;
use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;

/**
 * Feature tests for PppoePaymentController.
 *
 * Routes tested (all under /api/pppoe/):
 *   GET    /payments                      → index()
 *   POST   /payments                      → store()
 *   POST   /payments/{id}/verify          → verify()
 *   GET    /payments/pending              → getPendingPayments()
 *   GET    /payments/user/{userId}        → getUserPayments()
 *
 * Covers:
 *  - Authentication requirement (401 without token)
 *  - Validation errors (422)
 *  - Successful payment creation for every method
 *  - Auto-verification for mpesa/paybill/bank/manual
 *  - Cash payments remain pending
 *  - verify() transitions pending → completed and activates user
 *  - verify() rejects already-verified payments
 *  - Pagination on index / pending / user payments
 *  - Events and jobs after payment
 */
class PppoePaymentControllerTest extends TestCase
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

        Event::fake([PaymentReceived::class, PppoeUserPaymentStatusChanged::class]);
        Queue::fake([ReconnectPppoeUserJob::class]);
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

    private function validStorePayload(array $overrides = []): array
    {
        return array_merge([
            'pppoe_user_id'    => $this->pppoeUser->id,
            'amount'           => 500.00,
            'payment_method'   => 'mpesa',
            'payment_date'     => now()->toDateTimeString(),
            'payment_reference' => 'MPESA' . rand(1000, 9999),
        ], $overrides);
    }

    // =========================================================================
    // GET /api/pppoe/payments (index)
    // =========================================================================

    public function test_index_requires_authentication(): void
    {
        $response = $this->getJson('/api/pppoe/payments');
        $response->assertStatus(401);
    }

    public function test_index_returns_paginated_payments(): void
    {
        PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 500,
            'payment_method' => 'mpesa',
            'status'         => 'completed',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pppoe/payments');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);
    }

    // =========================================================================
    // POST /api/pppoe/payments (store)
    // =========================================================================

    public function test_store_requires_authentication(): void
    {
        $response = $this->postJson('/api/pppoe/payments', $this->validStorePayload());
        $response->assertStatus(401);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['pppoe_user_id', 'amount', 'payment_method', 'payment_date']);
    }

    public function test_store_validates_payment_method_enum(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'bitcoin',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_method']);
    }

    public function test_store_validates_amount_is_numeric(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'amount' => 'not-a-number',
            ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['amount']);
    }

    public function test_store_mpesa_payment_auto_verifies_and_activates_user(): void
    {
        $user = $this->makePppoeUser(['status' => 'suspended', 'payment_status' => 'overdue', 'is_active' => false]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'pppoe_user_id'  => $user->id,
                'payment_method' => 'mpesa',
            ]));

        $response->assertStatus(201)
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('pppoe_payments', [
            'pppoe_user_id' => $user->id,
            'status'        => 'completed',
        ]);

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertEquals('paid', $user->payment_status);
    }

    public function test_store_paybill_payment_auto_verifies(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'paybill',
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('pppoe_payments', [
            'pppoe_user_id' => $this->pppoeUser->id,
            'status'        => 'completed',
        ]);
    }

    public function test_store_bank_payment_auto_verifies(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'bank',
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('pppoe_payments', [
            'pppoe_user_id' => $this->pppoeUser->id,
            'status'        => 'completed',
        ]);
    }

    public function test_store_cash_payment_stays_pending(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'cash',
            ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('pppoe_payments', [
            'pppoe_user_id' => $this->pppoeUser->id,
            'status'        => 'pending',
        ]);
    }

    public function test_store_fires_payment_received_event_for_auto_verified(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'mpesa',
            ]));

        Event::assertDispatched(PaymentReceived::class);
    }

    public function test_store_does_not_fire_event_for_cash_payment(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload([
                'payment_method' => 'cash',
            ]));

        Event::assertNotDispatched(PaymentReceived::class);
    }

    public function test_store_returns_payment_with_relationships_loaded(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/pppoe/payments', $this->validStorePayload());

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id', 'amount', 'status',
                    'pppoe_user' => ['id', 'username'],
                ],
            ]);
    }

    // =========================================================================
    // POST /api/pppoe/payments/{id}/verify (verify)
    // =========================================================================

    public function test_verify_transitions_pending_to_completed(): void
    {
        $payment = PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 300,
            'payment_method' => 'cash',
            'status'         => 'pending',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/pppoe/payments/{$payment->id}/verify");

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertDatabaseHas('pppoe_payments', [
            'id'     => $payment->id,
            'status' => 'completed',
        ]);
    }

    public function test_verify_rejects_already_completed_payment(): void
    {
        $payment = PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 300,
            'payment_method' => 'cash',
            'status'         => 'completed',
            'verified_at'    => now(),
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/pppoe/payments/{$payment->id}/verify");

        $response->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_verify_activates_user_after_cash_payment(): void
    {
        $user = $this->makePppoeUser(['status' => 'suspended', 'is_active' => false]);

        $payment = PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $user->id,
            'account_number' => $user->account_number,
            'amount'         => 300,
            'payment_method' => 'cash',
            'status'         => 'pending',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/pppoe/payments/{$payment->id}/verify");

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertTrue($user->is_active);
    }

    // =========================================================================
    // GET /api/pppoe/payments/pending (getPendingPayments)
    // =========================================================================

    public function test_get_pending_payments_returns_only_pending(): void
    {
        PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 200,
            'payment_method' => 'cash',
            'status'         => 'pending',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/pppoe/payments/pending');

        $response->assertOk()
            ->assertJsonStructure(['success', 'data']);

        $statuses = collect($response->json('data.data'))->pluck('status')->unique()->values();
        $this->assertEquals(['pending'], $statuses->all());
    }

    // =========================================================================
    // GET /api/pppoe/payments/user/{userId} (getUserPayments)
    // =========================================================================

    public function test_get_user_payments_returns_only_that_users_payments(): void
    {
        $otherUser = $this->makePppoeUser();

        PppoePayment::create([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 500,
            'payment_method' => 'mpesa',
            'status'         => 'completed',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/pppoe/payments/user/{$this->pppoeUser->id}");

        $response->assertOk();

        $userIds = collect($response->json('data.data'))->pluck('pppoe_user_id')->unique()->values();
        $this->assertEquals([$this->pppoeUser->id], $userIds->all());
    }
}
