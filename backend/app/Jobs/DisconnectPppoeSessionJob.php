<?php

namespace App\Jobs;

use App\Models\PppoeUser;
use App\Models\Router;
use App\Models\RouterTask;
use App\Services\ProvisioningServiceClient;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Disconnect PPPoE Session Job
 * 
 * Lightweight job to disconnect a PPPoE user's active session from the router.
 * This runs asynchronously to avoid blocking HTTP responses during user operations.
 */
class DisconnectPppoeSessionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 2;
    public $timeout = 30;
    public $backoff = [5, 15];

    protected string $pppoeUserId;
    protected ?string $reason;

    /**
     * Create a new job instance.
     *
     * @param string $pppoeUserId The PPPoE user ID
     * @param string $tenantId The tenant ID
     * @param string|null $reason Optional reason for disconnection
     */
    public function __construct(string $pppoeUserId, string $tenantId, ?string $reason = null)
    {
        $this->pppoeUserId = $pppoeUserId;
        $this->setTenantContext($tenantId);
        $this->reason = $reason;
        $this->onQueue('service-control');
    }

    /**
     * Execute the job.
     */
    public function handle(ProvisioningServiceClient $provisioningClient): void
    {
        $this->executeInTenantContext(function () use ($provisioningClient) {
            Log::info('DisconnectPppoeSessionJob: Starting', [
                'pppoe_user_id' => $this->pppoeUserId,
                'tenant_id' => $this->tenantId,
                'reason' => $this->reason,
                'attempt' => $this->attempts(),
            ]);

            try {
                // Select only needed columns for efficiency
                $user = PppoeUser::query()
                    ->select(['id', 'username', 'router_id'])
                    ->find($this->pppoeUserId);

                if (!$user) {
                    Log::warning('DisconnectPppoeSessionJob: User not found', [
                        'pppoe_user_id' => $this->pppoeUserId,
                    ]);
                    return;
                }

                if (empty($user->router_id) || empty($user->username)) {
                    Log::info('DisconnectPppoeSessionJob: No router or username', [
                        'pppoe_user_id' => $this->pppoeUserId,
                    ]);
                    return;
                }

                // Get router details
                $router = Router::query()
                    ->select(['id', 'host', 'ssh_port', 'ssh_user', 'ssh_pass', 'ssh_private_key'])
                    ->find($user->router_id);

                if (!$router) {
                    Log::warning('DisconnectPppoeSessionJob: Router not found', [
                        'pppoe_user_id' => $this->pppoeUserId,
                        'router_id' => $user->router_id,
                    ]);
                    return;
                }

                // Execute SSH disconnect
                $this->disconnectFromRouter($user, $router, $provisioningClient);

                Log::info('DisconnectPppoeSessionJob: Completed successfully', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'username' => $user->username,
                    'reason' => $this->reason,
                ]);

            } catch (\Exception $e) {
                Log::error('DisconnectPppoeSessionJob: Failed', [
                    'pppoe_user_id' => $this->pppoeUserId,
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'attempt' => $this->attempts(),
                ]);

                if ($this->attempts() >= $this->tries) {
                    Log::critical('DisconnectPppoeSessionJob: All retries exhausted', [
                        'pppoe_user_id' => $this->pppoeUserId,
                    ]);
                }

                throw $e;
            }
        });
    }

    /**
     * Disconnect user session from router via the Go provisioning service
     */
    protected function disconnectFromRouter(PppoeUser $user, Router $router, ProvisioningServiceClient $provisioningClient): void
    {
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
                'message' => 'Queueing PPPoE session disconnect',
                'request_payload' => [
                    'context' => 'disconnect_pppoe_session',
                    'action' => 'disconnect_pppoe_session',
                    'username' => $user->username,
                    'reason' => $this->reason,
                    'commands' => $commands,
                ],
            ]);

            $provisioningClient->submitTaskCommand(
                $router,
                $this->tenantId,
                RouterTask::TYPE_SERVICE_CONTROL_ACTION,
                ['commands' => $commands, 'context' => 'disconnect_pppoe_session', 'action' => 'disconnect_pppoe_session'],
                $task
            );

            Log::info('DisconnectPppoeSessionJob: Disconnection command submitted via provisioning service', [
                'username' => $user->username,
                'router_id' => $router->id,
                'reason' => $this->reason,
                'task_id' => $task->id,
            ]);

        } catch (\Exception $e) {
            Log::warning('DisconnectPppoeSessionJob: Async router disconnect submission failed', [
                'username' => $user->username,
                'router_id' => $router->id,
                'error' => $e->getMessage(),
            ]);
            // Non-fatal - don't throw, the session will naturally expire
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('DisconnectPppoeSessionJob: Job failed permanently', [
            'pppoe_user_id' => $this->pppoeUserId,
            'tenant_id' => $this->tenantId,
            'reason' => $this->reason,
            'error' => $exception->getMessage(),
        ]);
    }
}
