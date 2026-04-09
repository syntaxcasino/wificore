<?php

namespace App\Listeners;

use App\Events\PackageUpdated;
use App\Models\PppoeUser;
use App\Models\Router;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\MikroTik\SshExecutor;
use App\Services\RADIUS\CoAService;
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
     * Apply new rate limit to a PPPoE user's live session.
     *
     * Strategy:
     *  1. Try RADIUS CoA CoA-Request with Mikrotik-Rate-Limit VSA — applies the new
     *     rate limit to the active session immediately without dropping it.
     *  2. On CoA failure, fall back to SSH disconnect so the user re-auths and
     *     picks up the new radreply Mikrotik-Rate-Limit on reconnect.
     */
    protected function disconnectUser(PppoeUser $user): void
    {
        $username = (string) $user->username;

        if (empty($username)) {
            return;
        }

        // ------------------------------------------------------------------
        // 1. CoA CoA-Request: apply new rate limit to the live session
        // ------------------------------------------------------------------
        try {
            $coaService = new CoAService();
            $result = $coaService->changeBandwidth($username, (string) $user->rate_limit);

            if ($result->isSuccessful()) {
                Log::info("Rate limit updated via CoA for PPPoE user {$username}", [
                    'rate_limit' => $user->rate_limit,
                ]);
                return;
            }

            Log::warning("CoA changeBandwidth failed for PPPoE user {$username}, falling back to SSH disconnect", [
                'message' => $result->message,
            ]);
        } catch (\Exception $e) {
            Log::warning("CoA exception for PPPoE user {$username}, falling back to SSH disconnect", [
                'error' => $e->getMessage(),
            ]);
        }

        // ------------------------------------------------------------------
        // 2. SSH fallback: disconnect the session so re-auth picks up new radreply
        // ------------------------------------------------------------------
        try {
            if (empty($user->router_id)) {
                return;
            }

            $router = Router::find($user->router_id);
            if (!$router) {
                return;
            }

            $ssh = new SshExecutor($router, 5);
            $ssh->connect();

            $ssh->exec(sprintf(':do { /ppp active remove [find name="%s"] } on-error={}', addslashes($username)));
            $ssh->exec(sprintf(':do { /ppp active remove [find user="%s"] } on-error={}', addslashes($username)));

            $ssh->disconnect();

            Log::info("PPPoE user {$username} disconnected via SSH to enforce new rate limit");
        } catch (\Exception $e) {
            Log::warning("Failed to disconnect PPPoE user {$username} after rate limit update", [
                'error' => $e->getMessage(),
            ]);
        }
    }
}
