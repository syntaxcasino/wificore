<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\PppoePayment;
use App\Models\PppoeUser;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\TenantTestHelper;
use Tests\Helpers\TestDatabaseState;

/**
 * Unit tests for PppoePayment model.
 *
 * Covers:
 *  - markAsCompleted()
 *  - markAsFailed()
 *  - scopePending / scopeCompleted / scopeFailed
 *  - Relationships: pppoeUser(), verifiedBy()
 */
class PppoePaymentModelTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    private PppoeUser $pppoeUser;
    private User $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->setUpTestTenant();
        $this->adminUser = $this->createAdminUser($tenant);

        // Create a PPPoE user directly in the test schema.
        $this->pppoeUser = PppoeUser::create([
            'id'             => Str::uuid()->toString(),
            'username'       => 'test_pppoe_' . Str::random(6),
            'account_number' => 'TST-P' . Str::random(10),
            'password'       => bcrypt('password'),
            'package_id'     => $this->createPackage()->id,
            'router_id'      => $this->createRouter(TestDatabaseState::$sharedTenant)->id,
            'is_active'      => true,
            'status'         => 'active',
            'payment_status' => 'unpaid',
        ]);
    }

    protected function tearDown(): void
    {
        $this->tearDownTenantContext();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makePendingPayment(array $overrides = []): PppoePayment
    {
        return PppoePayment::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $this->pppoeUser->id,
            'account_number' => $this->pppoeUser->account_number,
            'amount'         => 500.00,
            'payment_method' => 'mpesa',
            'status'         => 'pending',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // markAsCompleted()
    // -------------------------------------------------------------------------

    public function test_mark_as_completed_sets_status_and_verified_fields(): void
    {
        $payment = $this->makePendingPayment();

        $payment->markAsCompleted($this->adminUser->id);

        $payment->refresh();

        $this->assertEquals('completed', $payment->status);
        $this->assertEquals($this->adminUser->id, $payment->verified_by);
        $this->assertNotNull($payment->verified_at);
    }

    public function test_mark_as_completed_persists_to_database(): void
    {
        $payment = $this->makePendingPayment();
        $payment->markAsCompleted($this->adminUser->id);

        $this->assertDatabaseHas('pppoe_payments', [
            'id'     => $payment->id,
            'status' => 'completed',
        ]);
    }

    // -------------------------------------------------------------------------
    // markAsFailed()
    // -------------------------------------------------------------------------

    public function test_mark_as_failed_sets_status_to_failed(): void
    {
        $payment = $this->makePendingPayment();

        $payment->markAsFailed();

        $payment->refresh();
        $this->assertEquals('failed', $payment->status);
    }

    public function test_mark_as_failed_persists_to_database(): void
    {
        $payment = $this->makePendingPayment();
        $payment->markAsFailed();

        $this->assertDatabaseHas('pppoe_payments', [
            'id'     => $payment->id,
            'status' => 'failed',
        ]);
    }

    // -------------------------------------------------------------------------
    // Query scopes
    // -------------------------------------------------------------------------

    public function test_scope_pending_returns_only_pending_payments(): void
    {
        $pending   = $this->makePendingPayment(['status' => 'pending']);
        $completed = $this->makePendingPayment(['status' => 'completed', 'id' => Str::uuid()->toString()]);

        $results = PppoePayment::pending()->pluck('id');

        $this->assertContains($pending->id, $results);
        $this->assertNotContains($completed->id, $results);
    }

    public function test_scope_completed_returns_only_completed_payments(): void
    {
        $pending   = $this->makePendingPayment(['status' => 'pending']);
        $completed = $this->makePendingPayment(['status' => 'completed', 'id' => Str::uuid()->toString()]);

        $results = PppoePayment::completed()->pluck('id');

        $this->assertContains($completed->id, $results);
        $this->assertNotContains($pending->id, $results);
    }

    public function test_scope_failed_returns_only_failed_payments(): void
    {
        $failed  = $this->makePendingPayment(['status' => 'failed', 'id' => Str::uuid()->toString()]);
        $pending = $this->makePendingPayment(['status' => 'pending']);

        $results = PppoePayment::failed()->pluck('id');

        $this->assertContains($failed->id, $results);
        $this->assertNotContains($pending->id, $results);
    }

    // -------------------------------------------------------------------------
    // Relationships
    // -------------------------------------------------------------------------

    public function test_pppoe_user_relationship_returns_correct_user(): void
    {
        $payment = $this->makePendingPayment();

        $this->assertEquals($this->pppoeUser->id, $payment->pppoeUser->id);
    }

    public function test_verified_by_relationship_returns_correct_user(): void
    {
        $payment = $this->makePendingPayment();
        $payment->markAsCompleted($this->adminUser->id);

        $this->assertEquals($this->adminUser->id, $payment->verifiedBy->id);
    }

    // -------------------------------------------------------------------------
    // Soft deletes
    // -------------------------------------------------------------------------

    public function test_soft_delete_does_not_appear_in_default_query(): void
    {
        $payment = $this->makePendingPayment();
        $payment->delete();

        $found = PppoePayment::find($payment->id);
        $this->assertNull($found);
    }

    public function test_soft_deleted_payment_visible_with_withTrashed(): void
    {
        $payment = $this->makePendingPayment();
        $payment->delete();

        $found = PppoePayment::withTrashed()->find($payment->id);
        $this->assertNotNull($found);
        $this->assertNotNull($found->deleted_at);
    }
}
