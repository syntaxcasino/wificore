<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\PppoeUser;
use App\Models\PppoePayment;
use App\Services\PppoeBillingLifecycleService;
use App\Events\PaymentReceived;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Jobs\ReconnectPppoeUserJob;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Helpers\TenantTestHelper;
use Tests\Helpers\TestDatabaseState;

/**
 * Unit tests for PppoeBillingLifecycleService.
 *
 * Covers:
 *  - handleSuccessfulPayment() when user was already active (renewal)
 *  - handleSuccessfulPayment() when user was suspended/expired (reconnect)
 *  - RADIUS radcheck reject entry cleanup
 *  - Events dispatched: PaymentReceived, PppoeUserPaymentStatusChanged
 *  - ReconnectPppoeUserJob dispatched only for previously inactive users
 */
class PppoeBillingLifecycleServiceTest extends TestCase
{
    use DatabaseTransactions, TenantTestHelper;

    private PppoeBillingLifecycleService $service;
    private string $tenantId;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant          = $this->setUpTestTenant();
        $this->tenantId  = $tenant->id;
        $this->service   = app(PppoeBillingLifecycleService::class);

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
            'payment_status' => 'paid',
            'in_grace_period' => false,
        ], $overrides));
    }

    private function makeCompletedPayment(PppoeUser $user, array $overrides = []): PppoePayment
    {
        return PppoePayment::create(array_merge([
            'id'             => Str::uuid()->toString(),
            'pppoe_user_id'  => $user->id,
            'account_number' => $user->account_number,
            'amount'         => 500.00,
            'payment_method' => 'mpesa',
            'status'         => 'completed',
            'payment_date'   => now(),
            'period_start'   => now(),
            'period_end'     => now()->addDays(30),
        ], $overrides));
    }

    // -------------------------------------------------------------------------
    // Renewal path (user was already active)
    // -------------------------------------------------------------------------

    public function test_successful_payment_for_active_user_updates_billing_fields(): void
    {
        $user    = $this->makeUser(['status' => 'active', 'payment_status' => 'paid']);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertEquals('paid', $user->payment_status);
        $this->assertEquals('mpesa', $user->payment_method);
        $this->assertNotNull($user->last_payment_date);
        $this->assertNotNull($user->next_payment_due);
    }

    public function test_renewal_does_not_dispatch_reconnect_job(): void
    {
        $user    = $this->makeUser(['status' => 'active']);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        Queue::assertNotPushed(ReconnectPppoeUserJob::class);
    }

    public function test_renewal_fires_payment_received_event(): void
    {
        $user    = $this->makeUser(['status' => 'active']);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        Event::assertDispatched(PaymentReceived::class, function ($e) use ($user, $payment) {
            return $e->tenantId  === $this->tenantId
                && $e->userId    === $user->id
                && $e->paymentId === $payment->id;
        });
    }

    public function test_renewal_fires_payment_status_changed_event_with_renewed(): void
    {
        $user    = $this->makeUser(['status' => 'active']);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        Event::assertDispatched(PppoeUserPaymentStatusChanged::class, function ($e) {
            return $e->action === 'renewed';
        });
    }

    // -------------------------------------------------------------------------
    // Reconnect path (user was inactive: suspended / expired)
    // -------------------------------------------------------------------------

    public function test_payment_for_suspended_user_activates_and_dispatches_reconnect_job(): void
    {
        $user = $this->makeUser([
            'status'         => 'suspended',
            'payment_status' => 'overdue',
            'is_active'      => false,
            'suspended_at'   => now()->subDays(5),
        ]);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        $user->refresh();
        $this->assertEquals('active', $user->status);
        $this->assertEquals('paid', $user->payment_status);
        Queue::assertPushed(ReconnectPppoeUserJob::class);
    }

    public function test_payment_for_expired_user_dispatches_reconnect_job(): void
    {
        $user    = $this->makeUser(['status' => 'expired', 'is_active' => false]);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        Queue::assertPushed(ReconnectPppoeUserJob::class);
    }

    public function test_reconnect_fires_payment_status_changed_event_with_reconnected(): void
    {
        $user    = $this->makeUser(['status' => 'suspended', 'is_active' => false]);
        $payment = $this->makeCompletedPayment($user);

        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        Event::assertDispatched(PppoeUserPaymentStatusChanged::class, function ($e) {
            return $e->action === 'reconnected';
        });
    }

    // -------------------------------------------------------------------------
    // RADIUS radcheck cleanup
    // -------------------------------------------------------------------------

    public function test_reject_entry_removed_from_radcheck_on_payment(): void
    {
        $user = $this->makeUser(['status' => 'suspended', 'is_active' => false]);

        // Insert a reject entry as the suspend process would have done.
        DB::table('radcheck')->insert([
            'username'  => $user->username,
            'attribute' => 'Auth-Type',
            'op'        => ':=',
            'value'     => 'Reject',
        ]);

        $payment = $this->makeCompletedPayment($user);
        $this->service->handleSuccessfulPayment($user, $payment, $this->tenantId, 'test');

        $this->assertDatabaseMissing('radcheck', [
            'username'  => $user->username,
            'attribute' => 'Auth-Type',
            'value'     => 'Reject',
        ]);
    }
}
