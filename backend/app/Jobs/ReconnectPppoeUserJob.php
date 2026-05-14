<?php

namespace App\Jobs;

use App\Models\PppoeUser;
use App\Models\Router;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Events\PppoeUserReconnectedAfterPayment;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconnect PPPoE User Job
 * 
 * Removes RADIUS block to allow user to reconnect after payment.
 * Tenant-aware and broadcasts real-time updates via Soketi.
 */
class ReconnectPppoeUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;
    public $backoff = [10, 30, 60];

    protected string $pppoeUserId;

    public function __construct(string $pppoeUserId, string $tenantId)
    {
        $this->pppoeUserId = $pppoeUserId;
        $this->setTenantContext($tenantId);
        $this->onQueue('service-control');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            Log::info('ReconnectPppoeUserJob: Starting', [
                'pppoe_user_id' => $this->pppoeUserId,
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
            ]);

            try {
                // OPTIMIZED: Select only needed columns
                $user = PppoeUser::query()
                    ->select(['id', 'username'])
                    ->find($this->pppoeUserId);

                if (!$user) {
                    Log::warning('ReconnectPppoeUserJob: User not found', [
                        'pppoe_user_id' => $this->pppoeUserId,
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                // Remove Auth-Type Reject from RADIUS
                $this->unblockInRadius($user);

                // Update user status
                $user->update([
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'is_active' => true,
                    'suspended_at' => null,
                    'suspension_reason' => null,
                    'in_grace_period' => false,
                    'grace_period_ends' => null,
                ]);

                Log::info('ReconnectPppoeUserJob: User reconnection enabled', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'username' => $user->username,
                    'tenant_id' => $this->tenantId,
                ]);

                // Broadcast reconnection event
                event(new PppoeUserPaymentStatusChanged(
                    $this->tenantId,
                    $this->pppoeUserId,
                    'paid',
                    'reconnected'
                ));

                event(new PppoeUserReconnectedAfterPayment(
                    $this->tenantId,
                    $this->pppoeUserId,
                    'paid',
                    null,
                    'reconnect_job'
                ));

            } catch (\Exception $e) {
                Log::error('ReconnectPppoeUserJob: Failed', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() >= $this->tries) {
                    Log::critical('ReconnectPppoeUserJob: All retries exhausted', [
                        'pppoe_user_id' => $this->pppoeUserId,
                        'tenant_id' => $this->tenantId,
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Unblock user in RADIUS by removing Auth-Type Reject
     */
    protected function unblockInRadius(PppoeUser $user): void
    {
        DB::table('radcheck')
            ->where('username', $user->username)
            ->where('attribute', 'Auth-Type')
            ->where('value', 'Reject')
            ->delete();

        Log::info('ReconnectPppoeUserJob: User unblocked in RADIUS', [
            'username' => $user->username,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('ReconnectPppoeUserJob: Job failed permanently', [
            'pppoe_user_id' => $this->pppoeUserId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
