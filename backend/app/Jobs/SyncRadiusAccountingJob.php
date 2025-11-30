<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\DataUsageLog;
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

    public $tries = 2;
    public $timeout = 120;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('hotspot-accounting');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Syncing RADIUS accounting data...');
        
        // Get all active sessions
        $activeSessions = RadiusSession::where('status', 'active')->get();
        
        if ($activeSessions->isEmpty()) {
            Log::info('No active sessions to sync');
            return;
        }
        
        $syncedCount = 0;
        $errorCount = 0;
        
        foreach ($activeSessions as $session) {
            try {
                // Get latest RADIUS accounting data
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
                $session->hotspotUser->update([
                    'data_used' => $totalBytes,
                ]);
                
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
                
                // Check if data limit exceeded
                if ($session->hotspotUser->data_limit && 
                    $totalBytes >= $session->hotspotUser->data_limit) {
                    
                    Log::warning('Data limit exceeded', [
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
                
                $syncedCount++;
                
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('Failed to sync session accounting', [
                    'session_id' => $session->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        
        Log::info('Finished syncing RADIUS accounting', [
            'total_sessions' => $activeSessions->count(),
            'synced' => $syncedCount,
            'errors' => $errorCount,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SyncRadiusAccountingJob failed', [
            'error' => $exception->getMessage(),
        ]);
    }
}
