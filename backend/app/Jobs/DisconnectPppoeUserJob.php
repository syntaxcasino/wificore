<?php

namespace App\Jobs;

use App\Events\PppoeUserDisconnectedForNonPayment;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\RouterTask;
use App\Events\PppoeUserPaymentStatusChanged;
use App\Services\ProvisioningServiceClient;
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

    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $this->executeInTenantContext(function () use ($provisioningClient) {
            Log::info('DisconnectPppoeUserJob: Starting', [
                'pppoe_user_id' => $this->pppoeUserId,
                'tenant_id' => $this->tenantId,
                'reason' => $this->reason,
                'attempt' => $this->attempts(),
            ]);

            try {
                // OPTIMIZED: Select only needed columns
                $user = PppoeUser::query()
                    ->select(['id', 'username', 'router_id'])
                    ->find($this->pppoeUserId);

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
                $this->disconnectFromRouter($user, $provisioningClient);

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
     * Disconnect user from router via the Go provisioning service
     */
    protected function disconnectFromRouter(PppoeUser $user, ProvisioningServiceClient $provisioningClient): void
    {
        // OPTIMIZED: Select only needed columns
        $router = Router::query()
            ->select(['id', 'host', 'ssh_port', 'ssh_user', 'ssh_pass', 'ssh_private_key', 'ip_address', 'vpn_ip', 'username', 'password', 'port'])
            ->find($user->router_id);
        
        if (!$router) {
            Log::warning('DisconnectPppoeUserJob: No router assigned', [
                'pppoe_user_id' => $user->id,
            ]);
            return;
        }

        try {
            $escapedUsername = addslashes($user->username);
            $commands = [
                sprintf(':do { /ppp active remove [find name="%s"] } on-error={}', $escapedUsername),
                sprintf(':do { /ppp active remove [find user="%s"] } on-error={}', $escapedUsername),
            ];

            $task = RouterTask::create([
                'tenant_id' => $this->tenantId,
                'router_id' => $router->id,
                'user_id' => $user->id,
                'type' => RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                'status' => RouterTask::STATUS_QUEUED,
                'progress' => 0,
                'message' => 'Queueing PPPoE disconnect',
                'request_payload' => [
                    'context' => 'disconnect_pppoe_user',
                    'action' => 'disconnect_pppoe_user',
                    'username' => $user->username,
                    'reason' => $this->reason,
                    'commands' => $commands,
                ],
            ]);

            $provisioningClient->submitTaskCommand(
                $router,
                $this->tenantId,
                RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                ['commands' => $commands, 'context' => 'disconnect_pppoe_user', 'action' => 'disconnect_pppoe_user'],
                $task
            );

            Log::info('DisconnectPppoeUserJob: Disconnection command submitted via provisioning service', [
                'username' => $user->username,
                'router_id' => $router->id,
                'task_id' => $task->id,
            ]);

        } catch (\Exception $e) {
            Log::warning('DisconnectPppoeUserJob: Async router disconnect submission failed', [
                'username' => $user->username,
                'router_id' => $router->id ?? null,
                'error' => $e->getMessage(),
            ]);
            // Best-effort fallback to immediate execution so enforcement still completes.
            try {
                $provisioningClient->executeCommands($router, $commands ?? [
                    sprintf(':do { /ppp active remove [find name="%s"] } on-error={}', addslashes($user->username)),
                    sprintf(':do { /ppp active remove [find user="%s"] } on-error={}', addslashes($user->username)),
                ], $this->tenantId);
            } catch (\Exception $fallbackException) {
                Log::warning('DisconnectPppoeUserJob: Router disconnect fallback failed (non-fatal)', [
                    'username' => $user->username,
                    'router_id' => $router->id ?? null,
                    'error' => $fallbackException->getMessage(),
                ]);
            }
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
