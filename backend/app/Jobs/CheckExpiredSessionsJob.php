<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\Tenant;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckExpiredSessionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('hotspot-sessions');
    }

    /**
     * Execute the job.
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
            
            Log::info("Dispatched check expired sessions jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            Log::info('Checking for expired sessions...', ['tenant_id' => $this->tenantId]);
            
            // Get all active sessions that have expired
            // RadiusSession is in tenant schema
            $expiredSessions = RadiusSession::where('status', 'active')
                ->where('expected_end', '<=', now())
                ->get();
            
            if ($expiredSessions->isEmpty()) {
                Log::info('No expired sessions found', ['tenant_id' => $this->tenantId]);
                return;
            }
            
            Log::info('Found expired sessions', [
                'tenant_id' => $this->tenantId,
                'count' => $expiredSessions->count()
            ]);
            
            foreach ($expiredSessions as $session) {
                try {
                    // Dispatch disconnect job for each expired session
                    DisconnectHotspotUserJob::dispatch(
                        $session->id,
                        $this->tenantId,
                        'Session time expired'
                    )->onQueue('hotspot-sessions');
                    
                    Log::info('Dispatched disconnect job for expired session', [
                        'tenant_id' => $this->tenantId,
                        'session_id' => $session->id,
                        'username' => $session->username,
                        'expected_end' => $session->expected_end,
                    ]);
                    
                } catch (\Exception $e) {
                    Log::error('Failed to dispatch disconnect job', [
                        'tenant_id' => $this->tenantId,
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            Log::info('Finished checking expired sessions', [
                'tenant_id' => $this->tenantId,
                'processed' => $expiredSessions->count()
            ]);
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckExpiredSessionsJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
