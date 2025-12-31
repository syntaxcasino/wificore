<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\DataUsageLog;
use App\Models\Tenant;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SyncRadiusAccountingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $tries = 2;
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->tenantId = $tenantId;
        $this->onQueue('hotspot-accounting');
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
            
            Log::info("Dispatched radius accounting sync jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() {
            Log::info('Syncing RADIUS accounting data...', ['tenant_id' => $this->tenantId]);
            
            // Get all active sessions
            // RadiusSession is in tenant schema
            $activeSessions = RadiusSession::where('status', 'active')->get();
            
            if ($activeSessions->isEmpty()) {
                Log::info('No active sessions to sync', ['tenant_id' => $this->tenantId]);
                return;
            }
            
            $syncedCount = 0;
            $errorCount = 0;
            
            foreach ($activeSessions as $session) {
                try {
                    // Get latest RADIUS accounting data
                    // radacct is in tenant schema
                    $radacct = DB::table('radacct')
                        ->where('username', $session->username)
                        ->whereNull('acctstoptime')
                        ->orderBy('acctstarttime', 'desc')
                        ->first();
                    
                    if (!$radacct) {
                        continue;
                    }
                    
                    // Calculate data usage
                    $bytesIn = $radacct->acctinputoctets ?? 0;
                    $bytesOut = $radacct->acctoutputoctets ?? 0;
                    $totalBytes = $bytesIn + $bytesOut;
                    
                    // Update radius session
                    $session->update([
                        'radacct_id' => $radacct->radacctid,
                        'bytes_in' => $bytesIn,
                        'bytes_out' => $bytesOut,
                        'total_bytes' => $totalBytes,
                        'duration_seconds' => $radacct->acctsessiontime ?? 0,
                        'ip_address' => $radacct->framedipaddress,
                        'nas_ip_address' => $radacct->nasipaddress,
                    ]);
                    
                    // Update hotspot user data usage
                    if ($session->hotspotUser) {
                        $session->hotspotUser->update([
                            'data_used' => $totalBytes,
                        ]);
                        
                        // Check if data limit exceeded
                        if ($session->hotspotUser->data_limit && 
                            $totalBytes >= $session->hotspotUser->data_limit) {
                            
                            Log::warning('Data limit exceeded', [
                                'tenant_id' => $this->tenantId,
                                'session_id' => $session->id,
                                'username' => $session->username,
                                'data_used' => $totalBytes,
                                'data_limit' => $session->hotspotUser->data_limit,
                            ]);
                            
                            // Dispatch disconnect job
                            DisconnectHotspotUserJob::dispatch(
                                $session->id,
                                'Data limit exceeded'
                            )->onQueue('hotspot-sessions');
                        }
                    }
                    
                    // Log data usage
                    DataUsageLog::create([
                        'hotspot_user_id' => $session->hotspot_user_id,
                        'radius_session_id' => $session->id,
                        'bytes_in' => $bytesIn,
                        'bytes_out' => $bytesOut,
                        'total_bytes' => $totalBytes,
                        'recorded_at' => now(),
                        'source' => 'radius_accounting',
                    ]);
                    
                    $syncedCount++;
                    
                } catch (\Exception $e) {
                    $errorCount++;
                    Log::error('Failed to sync session accounting', [
                        'tenant_id' => $this->tenantId,
                        'session_id' => $session->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            Log::info('Finished syncing RADIUS accounting', [
                'tenant_id' => $this->tenantId,
                'total_sessions' => $activeSessions->count(),
                'synced' => $syncedCount,
                'errors' => $errorCount,
            ]);
        });
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncRadiusAccountingJob failed', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
