<?php

namespace App\Jobs;

use App\Events\AccountUnpaused;
use App\Models\Tenant;
use App\Models\PppoeUser;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UnpauseExpiredAccountsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    /**
     * The number of times the job may be attempted.
     */
    public $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('security');
    }

    /**
     * Execute the job.
     * Auto-unpause PPPoE accounts where pause period has expired.
     */
    public function handle(): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::active()->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info("Dispatched unpause expired accounts jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function () {
            Log::info('UnpauseExpiredAccountsJob started', ['tenant_id' => $this->tenantId]);

            try {
                // Find all paused accounts where pause period has expired for this tenant
                $expiredPauses = PppoeUser::query()
                    ->whereNotNull('paused_at')
                    ->whereNotNull('pause_ends_at')
                    ->where('pause_ends_at', '<=', now())
                    ->get();

                if ($expiredPauses->isEmpty()) {
                    Log::info('No expired pauses found', ['tenant_id' => $this->tenantId]);
                    return;
                }

                $unpausedCount = 0;

                foreach ($expiredPauses as $pppoeUser) {
                    // Store pause info before clearing
                    $wasPausedAt = $pppoeUser->paused_at?->toIso8601String();
                    $wasPauseEndsAt = $pppoeUser->pause_ends_at?->toIso8601String();
                    $pauseReason = $pppoeUser->pause_reason;

                    $pausedAt = $pppoeUser->paused_at;
                    $pauseEndsAt = $pppoeUser->pause_ends_at;
                    $totalPauseDays = $pausedAt && $pauseEndsAt ? (int) $pausedAt->diffInDays($pauseEndsAt, true) : 0;
                    $daysElapsed = $pausedAt ? (int) $pausedAt->diffInDays(now(), true) : 0;
                    $remainingDays = max(0, $totalPauseDays - $daysElapsed);

                    $newExpiry = ($pppoeUser->expires_at ?? now())->addDays($remainingDays);

                    // Clear pause fields and restore active status
                    $pppoeUser->update([
                        'paused_at'                 => null,
                        'pause_ends_at'             => null,
                        'pause_reason'              => null,
                        'pause_duration_days'       => null,
                        'expires_at'                => $newExpiry,
                        'status'                    => 'active',
                    ]);

                    // Remove RADIUS Auth-Type Reject to allow reconnection
                    DB::table('radcheck')
                        ->where('username', $pppoeUser->username)
                        ->where('attribute', 'Auth-Type')
                        ->where('value', 'Reject')
                        ->delete();

                    $unpausedCount++;

                    Log::info('PPPoE account auto-unpaused', [
                        'tenant_id'           => $this->tenantId,
                        'user_id'             => $pppoeUser->id,
                        'username'            => $pppoeUser->username,
                        'was_paused_at'       => $wasPausedAt,
                        'was_pause_ends_at'   => $wasPauseEndsAt,
                        'pause_reason'        => $pauseReason,
                        'days_credited'       => $remainingDays,
                        'new_expiry'          => $newExpiry->toIso8601String(),
                    ]);

                    // Broadcast unpause event to tenant/system admin
                    broadcast(new AccountUnpaused(
                        $pppoeUser,
                        $wasPausedAt,
                        $wasPauseEndsAt,
                        $pauseReason,
                        $remainingDays
                    ))->toOthers();
                }

                Log::info('UnpauseExpiredAccountsJob completed', [
                    'tenant_id'      => $this->tenantId,
                    'unpaused_count' => $unpausedCount,
                ]);

            } catch (\Exception $e) {
                Log::error('Failed to unpause expired accounts', [
                    'tenant_id' => $this->tenantId,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);

                throw $e;
            }
        });
    }
}
