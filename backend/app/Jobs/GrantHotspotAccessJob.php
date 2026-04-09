<?php

namespace App\Jobs;

use App\Models\HotspotUser;
use App\Models\Package;
use App\Events\HotspotAccessGranted;
use App\Services\MikroTik\BandwidthHelper;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Queued job to grant Hotspot access to a user.
 * 
 * This job:
 * 1. Removes Auth-Type := Reject from RADIUS
 * 2. Updates RADIUS reply attributes with package limits
 * 3. Updates HotspotUser subscription status
 * 4. Broadcasts access granted event
 */
class GrantHotspotAccessJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public string $hotspotUserId;
    public ?string $packageId;
    public string $reason;
    public $tries = 3;
    public $timeout = 30;

    public function __construct(
        string $hotspotUserId,
        string $tenantId,
        ?string $packageId = null,
        string $reason = 'payment'
    ) {
        $this->hotspotUserId = $hotspotUserId;
        $this->packageId = $packageId;
        $this->reason = $reason;
        $this->setTenantContext($tenantId);
        $this->onQueue('hotspot-access');
    }

    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            $user = HotspotUser::find($this->hotspotUserId);
            
            if (!$user) {
                Log::warning('GrantHotspotAccessJob: User not found', [
                    'user_id' => $this->hotspotUserId,
                    'tenant_id' => $this->tenantId,
                ]);
                return;
            }
            
            DB::beginTransaction();
            
            try {
                // Remove Auth-Type := Reject (unblock user)
                DB::table('radcheck')
                    ->where('username', $user->username)
                    ->where('attribute', 'Auth-Type')
                    ->where('value', 'Reject')
                    ->delete();
                
                // Get package for RADIUS attributes
                $package = $this->packageId ? Package::find($this->packageId) : $user->package;
                
                if ($package) {
                    // Update/insert RADIUS reply attributes
                    $this->updateRadiusAttributes($user->username, $package);
                }
                
                // Calculate expiration
                $expiresAt = $this->calculateExpiration($package);
                
                // Update user status
                $user->update([
                    'has_active_subscription' => true,
                    'status' => 'active',
                    'subscription_starts_at' => now(),
                    'subscription_expires_at' => $expiresAt,
                    'package_id' => $package?->id ?? $user->package_id,
                    'package_name' => $package?->name ?? $user->package_name,
                ]);
                
                DB::commit();
                
                // Broadcast access granted event
                broadcast(new HotspotAccessGranted(
                    $user->id,
                    $this->tenantId,
                    $user->username,
                    $package?->name,
                    $expiresAt?->toIso8601String(),
                    $this->reason
                ))->toOthers();
                
                Log::info('Hotspot access granted', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'package' => $package?->name,
                    'expires_at' => $expiresAt,
                    'reason' => $this->reason,
                    'tenant_id' => $this->tenantId,
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('GrantHotspotAccessJob failed', [
                    'user_id' => $this->hotspotUserId,
                    'error' => $e->getMessage(),
                    'tenant_id' => $this->tenantId,
                ]);
                throw $e;
            }
        });
    }

    private function updateRadiusAttributes(string $username, Package $package): void
    {
        // Remove old reply attributes
        DB::table('radreply')
            ->where('username', $username)
            ->whereIn('attribute', [
                'Session-Timeout',
                'Idle-Timeout',
                'WISPr-Bandwidth-Max-Up',
                'WISPr-Bandwidth-Max-Down',
                'Mikrotik-Rate-Limit',
                'ChilliSpot-Max-Total-Octets',
            ])
            ->delete();
        
        $attributes = [];
        
        // Session timeout (duration in seconds)
        $durationSeconds = $this->parseDurationToSeconds($package->duration);
        if ($durationSeconds > 0) {
            $attributes[] = [
                'username' => $username,
                'attribute' => 'Session-Timeout',
                'op' => ':=',
                'value' => (string) $durationSeconds,
            ];
        }
        
        // Idle timeout (default 5 minutes)
        $attributes[] = [
            'username' => $username,
            'attribute' => 'Idle-Timeout',
            'op' => ':=',
            'value' => '300',
        ];
        
        // Rate limit (MikroTik format: upload/download) — normalised via BandwidthHelper
        $downloadSpeed = (string) ($package->download_speed ?? $package->speed ?? '10M');
        $uploadSpeed   = (string) ($package->upload_speed  ?? $package->speed ?? '10M');
        $rateLimit = BandwidthHelper::formatMikrotikRateLimit($downloadSpeed, $uploadSpeed);

        if ($rateLimit) {
            $attributes[] = [
                'username'  => $username,
                'attribute' => 'Mikrotik-Rate-Limit',
                'op'        => ':=',
                'value'     => $rateLimit,
            ];
        }
        
        // Data limit (if specified)
        if ($package->data_limit) {
            $dataLimitBytes = $this->parseDataLimit($package->data_limit);
            if ($dataLimitBytes > 0) {
                $attributes[] = [
                    'username' => $username,
                    'attribute' => 'ChilliSpot-Max-Total-Octets',
                    'op' => ':=',
                    'value' => (string) $dataLimitBytes,
                ];
            }
        }
        
        if (!empty($attributes)) {
            DB::table('radreply')->insert($attributes);
        }
    }

    private function calculateExpiration(?Package $package): ?\Carbon\Carbon
    {
        if (!$package || !$package->duration) {
            return null;
        }
        
        $seconds = $this->parseDurationToSeconds($package->duration);
        return $seconds > 0 ? now()->addSeconds($seconds) : null;
    }

    private function parseDurationToSeconds($duration): int
    {
        if (is_numeric($duration)) {
            return (int) $duration * 3600; // Assume hours
        }
        
        $duration = strtolower(trim($duration));
        
        if (preg_match('/^(\d+)\s*h(our)?s?$/i', $duration, $matches)) {
            return (int) $matches[1] * 3600;
        }
        if (preg_match('/^(\d+)\s*d(ay)?s?$/i', $duration, $matches)) {
            return (int) $matches[1] * 86400;
        }
        if (preg_match('/^(\d+)\s*m(in(ute)?)?s?$/i', $duration, $matches)) {
            return (int) $matches[1] * 60;
        }
        if (preg_match('/^(\d+)\s*w(eek)?s?$/i', $duration, $matches)) {
            return (int) $matches[1] * 604800;
        }
        if (preg_match('/^(\d+)\s*mo(nth)?s?$/i', $duration, $matches)) {
            return (int) $matches[1] * 2592000; // 30 days
        }
        
        return 0;
    }

    private function parseDataLimit($dataLimit): int
    {
        if (is_numeric($dataLimit)) {
            return (int) $dataLimit;
        }
        
        $dataLimit = strtoupper(trim($dataLimit));
        
        if (preg_match('/^(\d+(?:\.\d+)?)\s*GB?$/i', $dataLimit, $matches)) {
            return (int) ((float) $matches[1] * 1073741824);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*MB?$/i', $dataLimit, $matches)) {
            return (int) ((float) $matches[1] * 1048576);
        }
        if (preg_match('/^(\d+(?:\.\d+)?)\s*KB?$/i', $dataLimit, $matches)) {
            return (int) ((float) $matches[1] * 1024);
        }
        
        return (int) $dataLimit;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('GrantHotspotAccessJob failed permanently', [
            'user_id' => $this->hotspotUserId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
