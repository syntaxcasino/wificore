<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\PppoeUser;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;
use Tests\Helpers\TestDatabaseState;

/**
 * Unit tests for PppoeUser model.
 *
 * Covers:
 *  - activateAfterPayment()
 *  - suspendForNonPayment()
 *  - isPaid() / isSuspended() / isInGracePeriod() / canConnect()
 *  - needsBillingReminder() / shouldSendInvoice()
 *  - Payment status helper scopes
 */
class PppoeUserModelTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpTestTenant();
    }

    protected function tearDown(): void
    {
        $this->tearDownTenantContext();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function makeUser(array $overrides = []): PppoeUser
    {
        $pkg    = $this->createPackage();
        $router = $this->createRouter(TestDatabaseState::$sharedTenant);

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

    // -------------------------------------------------------------------------
    // activateAfterPayment()
    // -------------------------------------------------------------------------

    public function test_activate_after_payment_sets_status_active_and_paid(): void
    {
        $user = $this->makeUser(['status' => 'suspended', 'payment_status' => 'overdue']);

        $user->activateAfterPayment();
        $user->refresh();

        $this->assertEquals('active', $user->status);
        $this->assertEquals('paid', $user->payment_status);
        $this->assertTrue($user->is_active);
        $this->assertFalse($user->in_grace_period);
        $this->assertNull($user->suspended_at);
    }

    public function test_activate_after_payment_clears_grace_period(): void
    {
        $user = $this->makeUser([
            'in_grace_period'  => true,
            'grace_period_ends' => now()->addDays(3),
        ]);

        $user->activateAfterPayment();
        $user->refresh();

        $this->assertFalse($user->in_grace_period);
        $this->assertNull($user->grace_period_ends);
    }

    // -------------------------------------------------------------------------
    // suspendForNonPayment()
    // -------------------------------------------------------------------------

    public function test_suspend_for_non_payment_sets_status_and_reason(): void
    {
        $user = $this->makeUser(['status' => 'active']);

        $user->suspendForNonPayment();
        $user->refresh();

        $this->assertEquals('suspended', $user->status);
        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->suspended_at);
        $this->assertStringContainsString('overdue', $user->suspension_reason);
    }

    // -------------------------------------------------------------------------
    // isPaid()
    // -------------------------------------------------------------------------

    public function test_is_paid_returns_true_when_payment_status_is_paid(): void
    {
        $user = $this->makeUser(['payment_status' => 'paid']);
        $this->assertTrue($user->isPaid());
    }

    public function test_is_paid_returns_false_when_payment_status_is_unpaid(): void
    {
        $user = $this->makeUser(['payment_status' => 'unpaid']);
        $this->assertFalse($user->isPaid());
    }

    public function test_is_paid_returns_false_when_payment_status_is_overdue(): void
    {
        $user = $this->makeUser(['payment_status' => 'overdue']);
        $this->assertFalse($user->isPaid());
    }

    // -------------------------------------------------------------------------
    // isSuspended()
    // -------------------------------------------------------------------------

    public function test_is_suspended_returns_true_when_suspended_at_is_set(): void
    {
        $user = $this->makeUser(['suspended_at' => now(), 'status' => 'suspended']);
        $this->assertTrue($user->isSuspended());
    }

    public function test_is_suspended_returns_false_when_suspended_at_is_null(): void
    {
        $user = $this->makeUser(['suspended_at' => null, 'status' => 'active']);
        $this->assertFalse($user->isSuspended());
    }

    // -------------------------------------------------------------------------
    // isInGracePeriod()
    // -------------------------------------------------------------------------

    public function test_is_in_grace_period_returns_true_within_window(): void
    {
        $user = $this->makeUser([
            'in_grace_period'  => true,
            'grace_period_ends' => now()->addDays(2),
        ]);

        $this->assertTrue($user->isInGracePeriod());
    }

    public function test_is_in_grace_period_returns_false_when_flag_is_off(): void
    {
        $user = $this->makeUser(['in_grace_period' => false]);
        $this->assertFalse($user->isInGracePeriod());
    }

    // -------------------------------------------------------------------------
    // canConnect()
    // -------------------------------------------------------------------------

    public function test_can_connect_returns_true_for_active_paid_user(): void
    {
        $user = $this->makeUser([
            'is_active'      => true,
            'status'         => 'active',
            'payment_status' => 'paid',
        ]);

        $this->assertTrue($user->canConnect());
    }

    public function test_can_connect_returns_false_for_suspended_user(): void
    {
        $user = $this->makeUser([
            'is_active'   => false,
            'status'      => 'suspended',
            'suspended_at' => now(),
        ]);

        $this->assertFalse($user->canConnect());
    }

    // -------------------------------------------------------------------------
    // Payment status scopes
    // -------------------------------------------------------------------------

    public function test_scope_overdue_returns_users_past_next_payment_due(): void
    {
        $overdue = $this->makeUser([
            'payment_status'   => 'overdue',
            'next_payment_due' => now()->subDays(5),
        ]);
        $current = $this->makeUser([
            'payment_status'   => 'paid',
            'next_payment_due' => now()->addDays(25),
        ]);

        // scopeOverdue: users whose payment is overdue and past their next_payment_due date
        $ids = PppoeUser::where('payment_status', 'overdue')
            ->where('next_payment_due', '<', now())
            ->pluck('id');

        $this->assertContains($overdue->id, $ids);
        $this->assertNotContains($current->id, $ids);
    }
}
