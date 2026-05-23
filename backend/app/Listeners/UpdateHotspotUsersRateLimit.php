<?php

namespace App\Listeners;

use App\Events\PackageUpdated;
use App\Models\HotspotUser;
use App\Services\MikroTik\BandwidthHelper;
use App\Services\RADIUS\CoAService;
use App\Services\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * When a hotspot package's speed changes, push the new Mikrotik-Rate-Limit
 * into radreply for every active hotspot user on that package, then apply
 * it to live sessions via RADIUS CoA.
 */
class UpdateHotspotUsersRateLimit implements ShouldQueue
{
    use InteractsWithQueue;

    protected TenantContext $tenantContext;

    public function __construct(TenantContext $tenantContext)
    {
        $this->tenantContext = $tenantContext;
    }

    public function handle(PackageUpdated $event): void
    {
        $tenantId    = $event->tenantId;
        $packageData = $event->package;

        if (empty($tenantId) || empty($packageData['id'])) {
            return;
        }

        // Only process hotspot packages
        if (($packageData['type'] ?? '') !== 'hotspot') {
            return;
        }

        try {
            DB::transaction(function () use ($tenantId, $packageData) {
            DB::connection()->recordsHaveBeenModified();
            $this->tenantContext->setTenantById($tenantId);
            try {

            $packageId   = $packageData['id'];
            $newDownload = (string) ($packageData['download_speed'] ?? $packageData['speed'] ?? '0');
            $newUpload   = (string) ($packageData['upload_speed']   ?? $packageData['speed'] ?? '0');

            $newRateLimit = BandwidthHelper::formatMikrotikRateLimit($newDownload, $newUpload);

            if (!$newRateLimit) {
                Log::warning("UpdateHotspotUsersRateLimit: invalid rate limit for package {$packageId}", [
                    'download' => $newDownload,
                    'upload'   => $newUpload,
                ]);
                return;
            }

            Log::info("Updating hotspot users for package {$packageId} to rate limit {$newRateLimit}", [
                'tenant_id' => $tenantId,
            ]);

            $users = HotspotUser::where('package_id', $packageId)->get();

            foreach ($users as $user) {
                try {
                    // 1. Persist to radreply (affects next auth and CoA response)
                    DB::table('radreply')->updateOrInsert(
                        ['username' => $user->username, 'attribute' => 'Mikrotik-Rate-Limit'],
                        ['op' => ':=', 'value' => $newRateLimit]
                    );

                    // 2. Apply to live session via CoA CoA-Request (no disconnect needed)
                    try {
                        $coaService = new CoAService();
                        $result = $coaService->changeBandwidth((string) $user->username, $newRateLimit);

                        if ($result->isSuccessful()) {
                            Log::info("Rate limit updated via CoA for hotspot user {$user->username}", [
                                'rate_limit' => $newRateLimit,
                            ]);
                        } else {
                            Log::warning("CoA changeBandwidth failed for hotspot user {$user->username}", [
                                'message' => $result->message,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning("CoA exception for hotspot user {$user->username}", [
                            'error' => $e->getMessage(),
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::error("Failed to update hotspot user {$user->username} rate limit", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info("Finished updating hotspot users rate limit for package {$packageId}", [
                'tenant_id'   => $tenantId,
                'users_count' => $users->count(),
                'rate_limit'  => $newRateLimit,
            ]);
            } finally {
                $this->tenantContext->clearTenant();
            }
            }); // end DB::transaction
        } catch (\Exception $e) {
            Log::error("UpdateHotspotUsersRateLimit: failed for package {$packageData['id']}", [
                'error'     => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
        }
    }
}
