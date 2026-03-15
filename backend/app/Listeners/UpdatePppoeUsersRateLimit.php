<?php

namespace App\Listeners;

use App\Events\PackageUpdated;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\MikroTik\SshExecutor;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePppoeUsersRateLimit implements ShouldQueue
{
    use InteractsWithQueue;

    protected TenantContext $tenantContext;

    /**
     * Create the event listener.
     */
    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    /**
     * Handle the event.
     */
    public function handle(PackageUpdated $event): void
    {
        $tenantId = $event->tenantId;
        $packageData = $event->package;

        if (empty($tenantId) || empty($packageData['id'])) {
            return;
        }

        // Only process PPPoE packages
        if (($packageData['type'] ?? '') !== 'pppoe') {
            return;
        }

        try {
            // Set tenant context to access tenant tables
            $this->tenantContext->setTenantById($tenantId);

            $packageId = $packageData['id'];
            $newDownload = $packageData['download_speed'] ?? '0';
            $newUpload = $packageData['upload_speed'] ?? '0';
            
            // Format new rate limit (Upload/Download for MikroTik)
            $newRateLimit = BandwidthHelper::formatMikrotikRateLimit($newDownload, $newUpload);

            if (!$newRateLimit) {
                Log::warning("Invalid rate limit derived for package {$packageId}", [
                    'download' => $newDownload,
                    'upload' => $newUpload
                ]);
                return;
            }

            Log::info("Updating PPPoE users for package {$packageId} to rate limit {$newRateLimit}", [
                'tenant_id' => $tenantId
            ]);

            // 1. Get all users with this package
            $users = PppoeUser::where('package_id', $packageId)->get();

            foreach ($users as $user) {
                // Skip if user has a custom override (logic to be determined, assuming standard case for now)
                // For now, we enforce package limits. If we add custom overrides later, check here.

                $oldRateLimit = $user->rate_limit;
                
                // Update user record
                $user->rate_limit = $newRateLimit;
                $user->save();

                // Update RADIUS radreply
                DB::table('radreply')->updateOrInsert(
                    [
                        'username' => $user->username, 
                        'attribute' => 'Mikrotik-Rate-Limit'
                    ],
                    [
                        'op' => ':=', 
                        'value' => $newRateLimit
                    ]
                );

                // Disconnect user to enforce new limit if changed
                if ($oldRateLimit !== $newRateLimit) {
                    $this->disconnectUser($user);
                }
            }

        } catch (\Exception $e) {
            Log::error("Failed to update PPPoE users rate limit for package {$packageData['id']}", [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);
        } finally {
            // Always clear tenant context
            $this->tenantContext->clearTenant();
        }
    }

    /**
     * Disconnect PPPoE user to enforce new limits
     */
    protected function disconnectUser(PppoeUser $user): void
    {
        try {
            if (empty($user->router_id) || empty($user->username)) {
                return;
            }

            $router = Router::find($user->router_id);
            if (!$router) {
                return;
            }

            $username = (string) $user->username;

            // Use SshExecutor to kick the user with a short timeout
            $ssh = new SshExecutor($router, 5);
            if (!$ssh->connect()) {
                Log::warning("Could not connect to router {$router->ip_address} to disconnect user {$username}");
                return;
            }
            
            // Remove from active connections (try both selectors for compatibility)
            // PPPoE active sessions typically use the PPP secret name as the active session name.
            $ssh->exec(sprintf('/ppp active remove [find name="%s"]', addslashes($username)));
            $ssh->exec(sprintf('/ppp active remove [find user="%s"]', addslashes($username)));
            
            $ssh->disconnect();
            
            Log::info("Disconnected PPPoE user {$username} on router {$router->name} to enforce new rate limit");
            
        } catch (\Exception $e) {
            Log::warning("Failed to disconnect PPPoE user {$user->username} after rate limit update", [
                'error' => $e->getMessage()
            ]);
        }
    }
}
