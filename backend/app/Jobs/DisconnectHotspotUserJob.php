<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\SessionDisconnection;
use App\Events\SessionExpired;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class DisconnectHotspotUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $radiusSessionId;
    public $reason;
    public $disconnectedBy;
    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($radiusSessionId, $reason = 'Session expired', $disconnectedBy = null)
    {
        $this->radiusSessionId = $radiusSessionId;
        $this->reason = $reason;
        $this->disconnectedBy = $disconnectedBy;
        $this->onQueue('hotspot-sessions');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $session = RadiusSession::find($this->radiusSessionId);
        
        if (!$session) {
            Log::warning('Radius session not found for disconnection', [
                'session_id' => $this->radiusSessionId
            ]);
            return;
        }
        
        if ($session->status !== 'active') {
            Log::info('Session already disconnected', [
                'session_id' => $this->radiusSessionId,
                'status' => $session->status
            ]);
            return;
        }
        
        DB::beginTransaction();
        
        try {
            // 1. Send RADIUS disconnect request
            $this->sendRadiusDisconnect($session);
            
            // 2. Calculate session duration
            $duration = now()->diffInSeconds($session->session_start);
            
            // 3. Update session status
            $session->update([
                'status' => 'expired',
                'session_end' => now(),
                'disconnect_reason' => $this->reason,
                'duration_seconds' => $duration,
            ]);
            
            // 4. Update hotspot user
            $session->hotspotUser->update([
                'has_active_subscription' => false,
                'status' => 'expired',
            ]);
            
            // 5. Update RADIUS accounting if exists
            if ($session->radacct_id) {
                DB::table('radacct')
                    ->where('radacctid', $session->radacct_id)
                    ->whereNull('acctstoptime')
                    ->update([
                        'acctstoptime' => now(),
                        'acctterminatecause' => $this->reason,
                    ]);
            }
            
            // 6. Log disconnection
            SessionDisconnection::create([
                'radius_session_id' => $session->id,
                'hotspot_user_id' => $session->hotspot_user_id,
                'disconnect_method' => $this->disconnectedBy ? 'admin_disconnect' : 'auto_expire',
                'disconnect_reason' => $this->reason,
                'disconnected_at' => now(),
                'disconnected_by' => $this->disconnectedBy,
                'total_duration' => $duration,
                'total_data_used' => $session->total_bytes,
            ]);
            
            DB::commit();
            
            // 7. Broadcast event
            broadcast(new SessionExpired($session, $this->reason))->toOthers();
            
            Log::info('User disconnected successfully', [
                'session_id' => $session->id,
                'user_id' => $session->hotspot_user_id,
                'username' => $session->username,
                'reason' => $this->reason,
                'duration' => $duration,
                'data_used' => $session->total_bytes,
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to disconnect user', [
                'error' => $e->getMessage(),
                'session_id' => $this->radiusSessionId,
                'trace' => $e->getTraceAsString(),
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Send RADIUS disconnect packet
     */
    private function sendRadiusDisconnect(RadiusSession $session): void
    {
        // TODO: Implement RADIUS disconnect packet
        // This requires a RADIUS client library
        
        Log::info('RADIUS disconnect packet would be sent', [
            'username' => $session->username,
            'nas_ip' => $session->nas_ip_address,
            'session_id' => $session->id,
        ]);
        
        // Example implementation with RADIUS library:
        /*
        $radius = new \Dapphp\Radius\Radius();
        $radius->setServer(config('radius.host'))
               ->setSecret(config('radius.secret'))
               ->setNasIpAddress($session->nas_ip_address);
        
        $radius->disconnect([
            'User-Name' => $session->username,
            'NAS-IP-Address' => $session->nas_ip_address,
        ]);
        */
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('DisconnectHotspotUserJob failed permanently', [
            'session_id' => $this->radiusSessionId,
            'error' => $exception->getMessage(),
        ]);
    }
}
