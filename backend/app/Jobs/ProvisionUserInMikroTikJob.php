<?php

namespace App\Jobs;

use App\Models\UserSubscription;
use App\Models\Router;
use App\Events\UserProvisioned;
use App\Events\ProvisioningFailed;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProvisionUserInMikroTikJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $subscriptionId;
    public $routerId;
    
    public $tries = 5;
    public $backoff = [5, 15, 30, 60, 120];
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct($subscriptionId, $routerId, $tenantId)
    {
        $this->subscriptionId = $subscriptionId;
        $this->routerId = $routerId;
        $this->setTenantContext($tenantId);
        $this->onQueue('provisioning'); // Dedicated queue for provisioning
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executeInTenantContext(function() {
            Log::info('MikroTik provisioning job started', [
                'job_id' => $this->job->getJobId(),
                'subscription_id' => $this->subscriptionId,
                'router_id' => $this->routerId,
                'tenant_id' => $this->tenantId,
                'attempt' => $this->attempts(),
            ]);

            try {
                $subscription = UserSubscription::find($this->subscriptionId);
                
                if (!$subscription) {
                    Log::error('Subscription not found for provisioning', [
                        'subscription_id' => $this->subscriptionId,
                        'tenant_id' => $this->tenantId
                    ]);
                    return;
                }

                $router = Router::findOrFail($this->routerId);
                $package = $subscription->package;

                // Connect to MikroTik
                $client = new \RouterOS\Client([
                    'host' => $router->ip_address,
                    'user' => $router->username,
                    'pass' => $router->password,
                    'port' => $router->port ?? 8728,
                    'timeout' => 10,
                ]);

                // Check if user already exists
                $existingUser = $this->checkUserExists($client, $subscription->mikrotik_username);
                
                if ($existingUser) {
                    Log::info('User already exists in MikroTik, updating', [
                        'username' => $subscription->mikrotik_username
                    ]);
                    $this->updateUser($client, $existingUser, $subscription);
                } else {
                    $this->createUser($client, $subscription);
                }

                Log::info('User provisioned in MikroTik successfully', [
                    'subscription_id' => $this->subscriptionId,
                    'router_id' => $this->routerId,
                    'username' => $subscription->mikrotik_username,
                ]);

                // Broadcast success event to admins
                broadcast(new UserProvisioned(
                    $subscription,
                    $router
                ))->toOthers();

            } catch (\Exception $e) {
                Log::error('MikroTik provisioning failed', [
                    'subscription_id' => $this->subscriptionId,
                    'router_id' => $this->routerId,
                    'attempt' => $this->attempts(),
                    'error' => $e->getMessage(),
                ]);

                // If this is the last attempt, broadcast failure
                if ($this->attempts() >= $this->tries) {
                    // Try to get subscription for event, if possible
                    $subscription = UserSubscription::find($this->subscriptionId);
                    if ($subscription) {
                        broadcast(new ProvisioningFailed(
                            $subscription,
                            $this->routerId,
                            $e->getMessage()
                        ))->toOthers();
                    }
                }

                throw $e;
            }
        });
    }

    /**
     * Check if user exists in MikroTik
     */
    protected function checkUserExists($client, string $username)
    {
        try {
            $query = new \RouterOS\Query('/ip/hotspot/user/print');
            $query->where('name', $username);
            
            $response = $client->query($query)->read();
            
            return !empty($response) ? $response[0] : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create user in MikroTik
     */
    protected function createUser($client, UserSubscription $subscription): void
    {
        $package = $subscription->package;
        
        $query = new \RouterOS\Query('/ip/hotspot/user/add');
        $query->equal('name', $subscription->mikrotik_username);
        $query->equal('password', $subscription->mikrotik_password);
        $query->equal('limit-uptime', $this->formatDuration($package->duration));
        $query->equal('limit-bytes-total', $this->calculateDataLimit($package));
        $query->equal('profile', $this->getProfileName($package));
        $query->equal('comment', 'Auto-provisioned - Subscription #' . $subscription->id);

        $client->query($query)->read();
    }

    /**
     * Update existing user in MikroTik
     */
    protected function updateUser($client, $existingUser, UserSubscription $subscription): void
    {
        $package = $subscription->package;
        
        $query = new \RouterOS\Query('/ip/hotspot/user/set');
        $query->equal('.id', $existingUser['.id']);
        $query->equal('password', $subscription->mikrotik_password);
        $query->equal('limit-uptime', $this->formatDuration($package->duration));
        $query->equal('limit-bytes-total', $this->calculateDataLimit($package));
        $query->equal('profile', $this->getProfileName($package));

        $client->query($query)->read();
    }

    /**
     * Format duration for MikroTik (HH:MM:SS)
     */
    protected function formatDuration(string $duration): string
    {
        $minutes = $this->parseDuration($duration);
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    /**
     * Parse duration string to minutes
     */
    protected function parseDuration(string $duration): int
    {
        preg_match('/(\d+)\s*(hour|hours|day|days|minute|minutes)/i', $duration, $matches);

        if (empty($matches)) {
            return 60;
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        return match($unit) {
            'minute', 'minutes' => $value,
            'hour', 'hours' => $value * 60,
            'day', 'days' => $value * 24 * 60,
            default => 60,
        };
    }

    /**
     * Calculate data limit in bytes
     */
    protected function calculateDataLimit($package): int
    {
        preg_match('/(\d+)\s*Mbps/i', $package->download_speed, $matches);
        $speedMbps = isset($matches[1]) ? (int) $matches[1] : 3;

        $duration = $this->parseDuration($package->duration);
        $dataLimitMB = ($speedMbps * $duration * 60) / 8;

        return (int) ($dataLimitMB * 1024 * 1024);
    }

    /**
     * Get MikroTik profile name
     */
    protected function getProfileName($package): string
    {
        return strtolower($package->type) . '-profile';
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('MikroTik provisioning job failed permanently', [
            'subscription_id' => $this->subscriptionId,
            'router_id' => $this->routerId,
            'error' => $exception->getMessage(),
        ]);

        // Note: Can't broadcast ProvisioningFailed easily without model, 
        // but try catch in handle() covers it.
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'subscription:' . $this->subscriptionId,
            'router:' . $this->routerId,
            'tenant:' . $this->tenantId,
        ];
    }
}
