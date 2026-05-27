<?php

namespace App\Jobs;

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

    public $tries = 3;
    public $timeout = 120;

    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('security');
    }

    public function handle(): void
    {
        if (!$this->tenantId) {
            $tenants = Tenant::active()->get();

            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }

            Log::info("Dispatched unpause expired accounts jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            Log::info('UnpauseExpiredAccountsJob started', ['tenant_id' => $this->tenantId]);

            try {
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
                    $pausedAt = $pppoeUser->paused_at;
                    $pauseEndsAt = $pppoeUser->pause_ends_at;
                    $totalPauseDays = $pausedAt && $pauseEndsAt ? (int) $pausedAt->diffInDays($pauseEndsAt, true) : 0;
                    $daysElapsed = $pausedAt ? (int) $pausedAt->diffInDays(now(), true) : 0;
                    $remainingDays = max(0, $totalPauseDays - $daysElapsed);

                    $newExpiry = ($pppoeUser->expires_at ?? now())->addDays($remainingDays);

                    $pppoeUser->update([
                        'paused_at'                 => null,
                        'pause_ends_at'             => null,
                        'pause_reason'              => null,
                        'pause_duration_days'       => null,
                        'expires_at'                => $newExpiry,
                        'status'                    => 'active',
                    ]);

                    DB::table('radcheck')
                        ->where('username', $pppoeUser->username)
                        ->where('attribute', 'Auth-Type')
                        ->where('value', 'Reject')
                        ->delete();

                    $unpausedCount++;

                    Log::info('PPPoE account auto-unpaused', [
                        'tenant_id'      => $this->tenantId,
                        'user_id'        => $pppoeUser->id,
                        'username'       => $pppoeUser->username,
                        'paused_at'      => $pausedAt?->toIso8601String(),
                        'pause_ends_at'  => $pauseEndsAt?->toIso8601String(),
                        'days_credited'  => $remainingDays,
                        'new_expiry'     => $newExpiry->toIso8601String(),
                    ]);
                }

                Log::info('UnpauseExpiredAccountsJob completed', [
                    'tenant_id'       => $this->tenantId,
                    'unpaused_count'  => $unpausedCount,
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
