<?php

namespace App\Jobs;

use App\Models\RadiusSession;
use App\Models\Router;
use App\Models\SessionDisconnection;
use App\Events\SessionExpired;
use App\Services\MikroTik\SshExecutor;
use App\Traits\TenantAwareJob;
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
    use TenantAwareJob;

    public $radiusSessionId;
    public $reason;
    public $disconnectedBy;
    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($radiusSessionId, $tenantId, $reason = 'Session expired', $disconnectedBy = null)
    {
        $this->radiusSessionId = $radiusSessionId;
        $this->setTenantContext($tenantId);
        $this->reason = $reason;
        $this->disconnectedBy = $disconnectedBy;
        $this->onQueue('hotspot-sessions');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executeInTenantContext(function() {
            $session = RadiusSession::find($this->radiusSessionId);
            
            if (!$session) {
                Log::warning('Radius session not found for disconnection', [
                    'session_id' => $this->radiusSessionId,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }
            
            if ($session->status !== 'active') {
                Log::info('Session already disconnected', [
                    'session_id' => $this->radiusSessionId,
                    'status' => $session->status,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }

            $transactionStarted = false;
            
            try {
                // 1. Block re-authentication immediately in RADIUS
                $this->blockUserInRadius((string) $session->username);

                // 2. Terminate active Hotspot session on NAS (required for immediate cutoff)
                if (!$this->sendRadiusDisconnect($session)) {
                    throw new \RuntimeException('Failed to terminate active Hotspot session on NAS');
                }
                
                // 3. Calculate session duration
                $duration = now()->diffInSeconds($session->session_start);

                DB::beginTransaction();
                $transactionStarted = true;
                
                // 4. Update session status
                $session->update([
                    'status' => 'expired',
                    'session_end' => now(),
                    'disconnect_reason' => $this->reason,
                    'duration_seconds' => $duration,
                ]);
                
                // 5. Update hotspot user
                if ($session->hotspotUser) {
                    $session->hotspotUser->update([
                        'has_active_subscription' => false,
                        'status' => 'expired',
                    ]);
                }
                
                // 6. Update RADIUS accounting rows
                $radacctUpdate = DB::table('radacct')
                    ->where('username', $session->username)
                    ->whereNull('acctstoptime');

                if ($session->radacct_id) {
                    $radacctUpdate->where('radacctid', $session->radacct_id);
                }

                $radacctUpdate->update([
                    'acctstoptime' => now(),
                    'acctterminatecause' => $this->reason,
                ]);
                
                // 7. Log disconnection
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
                $transactionStarted = false;
                
                // 8. Broadcast event
                broadcast(new SessionExpired($session, $this->reason, $this->tenantId))->toOthers();
                
                Log::info('User disconnected successfully', [
                    'session_id' => $session->id,
                    'user_id' => $session->hotspot_user_id,
                    'username' => $session->username,
                    'reason' => $this->reason,
                    'duration' => $duration,
                    'data_used' => $session->total_bytes,
                    'tenant_id' => $this->tenantId
                ]);
                
            } catch (\Exception $e) {
                if ($transactionStarted && DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                
                Log::error('Failed to disconnect user', [
                    'error' => $e->getMessage(),
                    'session_id' => $this->radiusSessionId,
                    'tenant_id' => $this->tenantId,
                    'trace' => $e->getTraceAsString(),
                ]);
                
                throw $e;
            }
        });
    }
    
    /**
     * Send RADIUS disconnect packet
     */
    private function sendRadiusDisconnect(RadiusSession $session): bool
    {
        $username = trim((string) $session->username);
        if ($username === '') {
            Log::warning('Cannot disconnect hotspot session: empty username', [
                'session_id' => $session->id,
                'tenant_id' => $this->tenantId,
            ]);
            return false;
        }

        try {
            $activeRadacct = $this->lookupActiveRadacct($session, $username);
            $nasIp = $session->nas_ip_address ?: ($activeRadacct->nasipaddress ?? null);
            $router = $this->resolveRouterForSession($session, $nasIp);

            if (!$router) {
                Log::warning('Cannot disconnect hotspot session: router not resolved', [
                    'session_id' => $session->id,
                    'username' => $username,
                    'nas_ip' => $nasIp,
                    'tenant_id' => $this->tenantId,
                ]);
                return false;
            }

            $ssh = new SshExecutor($router, 10);
            $ssh->connect();

            try {
                $escapedUsername = addslashes($username);
                $ssh->exec(sprintf(':do { /ip hotspot active remove [find user="%s"] } on-error={}', $escapedUsername));

                if (!empty($session->mac_address)) {
                    $ssh->exec(sprintf(':do { /ip hotspot host remove [find mac-address="%s"] } on-error={}', addslashes((string) $session->mac_address)));
                }
            } finally {
                $ssh->disconnect();
            }

            Log::info('Hotspot session terminated on router', [
                'session_id' => $session->id,
                'username' => $username,
                'router_id' => $router->id,
                'nas_ip' => $nasIp,
                'tenant_id' => $this->tenantId,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('Failed to terminate hotspot session on router', [
                'session_id' => $session->id,
                'username' => $username,
                'tenant_id' => $this->tenantId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function blockUserInRadius(string $username): void
    {
        DB::table('radcheck')->updateOrInsert(
            ['username' => $username, 'attribute' => 'Auth-Type'],
            ['op' => ':=', 'value' => 'Reject']
        );
    }

    private function lookupActiveRadacct(RadiusSession $session, string $username): ?object
    {
        $query = DB::table('radacct')->whereNull('acctstoptime');

        if ($session->radacct_id) {
            $query->where('radacctid', $session->radacct_id);
        } else {
            $query->where('username', $username)->orderBy('acctstarttime', 'desc');
        }

        return $query->first();
    }

    private function resolveRouterForSession(RadiusSession $session, ?string $nasIp): ?Router
    {
        if (!empty($nasIp)) {
            $router = Router::where(function ($query) use ($nasIp) {
                $query->where('ip_address', $nasIp)
                    ->orWhere('vpn_ip', $nasIp);
            })->first();

            if ($router) {
                return $router;
            }
        }

        $payment = $session->relationLoaded('payment') ? $session->payment : $session->payment()->first();
        if ($payment && !empty($payment->router_id)) {
            return Router::find($payment->router_id);
        }

        return null;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('DisconnectHotspotUserJob failed permanently', [
            'session_id' => $this->radiusSessionId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
