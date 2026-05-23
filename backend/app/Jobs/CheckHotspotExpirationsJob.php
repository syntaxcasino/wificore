<?php

namespace App\Jobs;

use App\Models\HotspotUser;
use App\Models\RadiusSession;
use App\Models\Tenant;
use App\Events\HotspotPackageExpired;
use App\Events\HotspotAccessRevoked;
use App\Traits\TenantAwareJob;
use App\Services\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Scheduled job to check for expired Hotspot subscriptions
 * and disconnect users who have exceeded their package duration.
 * 
 * Runs every minute via Laravel scheduler.
 * Tenant-aware: iterates all active tenants.
 */
class CheckHotspotExpirationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 1;
    public $timeout = 300;
    public ?string $specificTenantId;

    public function __construct(?string $tenantId = null)
    {
        $this->specificTenantId = $tenantId;
        if ($tenantId) {
            $this->setTenantContext($tenantId);
        }
        $this->onQueue('hotspot-expirations');
    }

    public function handle(TenantContext $tenantContext): void
    {
        $startTime = microtime(true);
        
        if ($this->specificTenantId) {
            $this->processForTenant($tenantContext, $this->specificTenantId);
        } else {
            $tenants = Tenant::where('is_active', true)
                ->where('schema_created', true)
                ->get();
            
            foreach ($tenants as $tenant) {
                try {
                    $this->processForTenant($tenantContext, $tenant->id);
                } catch (\Exception $e) {
                    Log::error('CheckHotspotExpirationsJob: Tenant processing failed', [
                        'tenant_id' => $tenant->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
        
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        Log::info('CheckHotspotExpirationsJob completed', [
            'duration_ms' => $duration,
            'tenant_id' => $this->specificTenantId ?? 'all',
        ]);
    }

    private function processForTenant(TenantContext $tenantContext, string $tenantId): void
    {
        DB::connection()->recordsHaveBeenModified();
        $tenantContext->setTenantById($tenantId);
        
        $expiredCount = 0;
        $disconnectedCount = 0;
        
        // Find expired hotspot users with active subscriptions
        $expiredUsers = HotspotUser::where('has_active_subscription', true)
            ->where('subscription_expires_at', '<', now())
            ->get();
        
        foreach ($expiredUsers as $user) {
            DB::beginTransaction();
            
            try {
                // Mark subscription as expired
                $user->update([
                    'has_active_subscription' => false,
                    'status' => 'expired',
                ]);
                $expiredCount++;
                
                // Block in RADIUS - add Auth-Type := Reject
                DB::table('radcheck')
                    ->where('username', $user->username)
                    ->where('attribute', 'Auth-Type')
                    ->delete();
                
                DB::table('radcheck')->insert([
                    'username' => $user->username,
                    'attribute' => 'Auth-Type',
                    'op' => ':=',
                    'value' => 'Reject',
                ]);
                
                // Find active sessions and disconnect
                $activeSessions = RadiusSession::where('hotspot_user_id', $user->id)
                    ->where('status', 'active')
                    ->get();
                
                foreach ($activeSessions as $session) {
                    DisconnectHotspotUserJob::dispatch(
                        $session->id,
                        $tenantId,
                        'Package expired',
                        null
                    )->onQueue('hotspot-sessions');
                    $disconnectedCount++;
                }
                
                DB::commit();
                
                // Broadcast expiration event
                broadcast(new HotspotPackageExpired(
                    $user->id,
                    $tenantId,
                    $user->username,
                    $user->package_name,
                    $user->subscription_expires_at?->toIso8601String(),
                    $activeSessions->count() > 0
                ))->toOthers();
                
                Log::info('Hotspot user expired and blocked', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'expired_at' => $user->subscription_expires_at,
                    'sessions_disconnected' => $activeSessions->count(),
                    'tenant_id' => $tenantId,
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to expire hotspot user', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                    'tenant_id' => $tenantId,
                ]);
            }
        }
        
        if ($expiredCount > 0) {
            Log::info('CheckHotspotExpirationsJob: Tenant processed', [
                'tenant_id' => $tenantId,
                'expired_users' => $expiredCount,
                'sessions_disconnected' => $disconnectedCount,
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CheckHotspotExpirationsJob failed', [
            'error' => $exception->getMessage(),
            'tenant_id' => $this->specificTenantId ?? 'all',
        ]);
    }
}
