<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\Tenant;
use App\Events\AccountUnsuspended;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UnsuspendExpiredAccountsJob implements ShouldQueue
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
    public $timeout = 60;

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
     * Unsuspend accounts where suspension period has expired
     */
    public function handle(): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched unsuspend expired accounts jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            Log::info('UnsuspendExpiredAccountsJob started', ['tenant_id' => $this->tenantId]);
            
            try {
                // Find all suspended accounts where suspension period has expired for this tenant
                $expiredSuspensions = User::where('tenant_id', $this->tenantId)
                    ->whereNotNull('suspended_until')
                    ->where('suspended_until', '<=', now())
                    ->get();
                
                if ($expiredSuspensions->isEmpty()) {
                    Log::info('No expired suspensions found', ['tenant_id' => $this->tenantId]);
                    return;
                }
                
                $unsuspendedCount = 0;
                
                foreach ($expiredSuspensions as $user) {
                    // Store suspension info before clearing
                    $wasSuspendedUntil = $user->suspended_until?->toIso8601String();
                    $suspensionReason = $user->suspension_reason;
                    
                    // Clear suspension
                    $user->update([
                        'failed_login_attempts' => 0,
                        'last_failed_login_at' => null,
                        'suspended_at' => null,
                        'suspended_until' => null,
                        'suspension_reason' => null
                    ]);
                    
                    $unsuspendedCount++;
                    
                    Log::info('Account unsuspended', [
                        'tenant_id' => $this->tenantId,
                        'user_id' => $user->id,
                        'username' => $user->username,
                        'was_suspended_until' => $wasSuspendedUntil,
                        'reason' => $suspensionReason
                    ]);
                    
                    // Broadcast unsuspension event to tenant/system admin
                    broadcast(new AccountUnsuspended(
                        $user,
                        $wasSuspendedUntil,
                        $suspensionReason
                    ))->toOthers();
                }
                
                Log::info('UnsuspendExpiredAccountsJob completed', [
                    'tenant_id' => $this->tenantId,
                    'unsuspended_count' => $unsuspendedCount
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to unsuspend expired accounts', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                throw $e;
            }
        });
    }
}
