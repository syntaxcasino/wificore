<?php

namespace App\Jobs;

use App\Events\PppoeUserDisconnectedForNonPayment;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Services\MikroTik\SshExecutor;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Disconnect PPPoE User Job
 * 
 * Disconnects a PPPoE user from the router and blocks in RADIUS.
 * Tenant-aware and broadcasts real-time updates via Soketi.
 */
class DisconnectPppoeUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 120;
    public $backoff = [10, 30, 60];

    protected string $pppoeUserId;
    protected string $reason;

    public function __construct(string $pppoeUserId, string $tenantId, string $reason = 'Payment overdue')
    {
        $this->pppoeUserId = $pppoeUserId;
        $this->setTenantContext($tenantId);
        $this->reason = $reason;
        $this->onQueue('service-control');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            Log::info('DisconnectPppoeUserJob: Starting', [
                'pppoe_user_id' => $this->pppoeUserId,
                'tenant_id' => $this->tenantId,
                'reason' => $this->reason,
                'attempt' => $this->attempts(),
            ]);

            try {
                $user = PppoeUser::find($this->pppoeUserId);

                if (!$user) {
                    Log::warning('DisconnectPppoeUserJob: User not found', [
                        'pppoe_user_id' => $this->pppoeUserId,
                        'tenant_id' => $this->tenantId,
                    ]);
                    return;
                }

                // Add Auth-Type Reject to RADIUS to prevent reconnection
                $this->blockInRadius($user);

                // Disconnect active session on router
                $this->disconnectFromRouter($user);

                // Update user status
                $user->update([
                    'status' => 'suspended',
                    'payment_status' => 'overdue',
                    'is_active' => false,
                    'in_grace_period' => false,
                    'suspended_at' => now(),
                    'suspension_reason' => $this->reason,
                ]);

                Log::info('DisconnectPppoeUserJob: User disconnected successfully', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'username' => $user->username,
                    'tenant_id' => $this->tenantId,
                ]);

                // Broadcast disconnection event
                event(new PppoeUserPaymentStatusChanged(
                    $this->tenantId,
                    $this->pppoeUserId,
                    'suspended',
                    'disconnected'
                ));

                event(new PppoeUserDisconnectedForNonPayment(
                    $this->tenantId,
                    $this->pppoeUserId,
                    'suspended',
                    $this->reason,
                    'disconnect_job'
                ));

            } catch (\Exception $e) {
                Log::error('DisconnectPppoeUserJob: Failed', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() >= $this->tries) {
                    Log::critical('DisconnectPppoeUserJob: All retries exhausted', [
                        'pppoe_user_id' => $this->pppoeUserId,
                        'tenant_id' => $this->tenantId,
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Block user in RADIUS by adding Auth-Type Reject
     */
    protected function blockInRadius(PppoeUser $user): void
    {
        // Remove any existing Auth-Type entries first
        DB::table('radcheck')
            ->where('username', $user->username)
            ->where('attribute', 'Auth-Type')
            ->delete();

        // Add Auth-Type Reject
        DB::table('radcheck')->insert([
            'username' => $user->username,
            'attribute' => 'Auth-Type',
            'op' => ':=',
            'value' => 'Reject',
        ]);

        Log::info('DisconnectPppoeUserJob: User blocked in RADIUS', [
            'username' => $user->username,
        ]);
    }

    /**
     * Disconnect user from router via SSH
     */
    protected function disconnectFromRouter(PppoeUser $user): void
    {
        $router = $user->router;
        
        if (!$router) {
            Log::warning('DisconnectPppoeUserJob: No router assigned', [
                'pppoe_user_id' => $user->id,
            ]);
            return;
        }

        try {
            $ssh = new SshExecutor($router, 15);
            $ssh->connect();
            
            // Remove active PPPoE session by name
            $escapedUsername = addslashes($user->username);
            $ssh->exec(sprintf('/ppp active remove [find name="%s"]', $escapedUsername));
            
            // Also try by user field (some RouterOS versions use 'user' instead of 'name')
            $ssh->exec(sprintf('/ppp active remove [find user="%s"]', $escapedUsername));
            
            $ssh->disconnect();

            Log::info('DisconnectPppoeUserJob: Disconnected from router', [
                'username' => $user->username,
                'router_id' => $router->id,
            ]);

        } catch (\Exception $e) {
            Log::warning('DisconnectPppoeUserJob: Router disconnect failed (non-fatal)', [
                'username' => $user->username,
                'router_id' => $router->id ?? null,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - RADIUS block is more important
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical('DisconnectPppoeUserJob: Job failed permanently', [
            'pppoe_user_id' => $this->pppoeUserId,
            'tenant_id' => $this->tenantId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);
    }
}
