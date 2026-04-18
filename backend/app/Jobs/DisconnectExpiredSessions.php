<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use App\Models\UserSession;
use App\Services\MikrotikSessionService;
use App\Models\SystemLog;
use App\Traits\TenantAwareJob;
use Illuminate\Support\Facades\Log;

class DisconnectExpiredSessions implements ShouldQueue
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
        $this->setTenantContext($tenantId);
        $this->onQueue('hotspot-sessions');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executeInTenantContext(function () {
            $expiredSessions = UserSession::where('end_time', '<', now())->get();

            if ($expiredSessions->isEmpty()) {
                return;
            }

            $mikrotik = new MikrotikSessionService();

            foreach ($expiredSessions as $session) {
                try {
                    $mikrotik->disconnectUser($session->mac_address);
                    SystemLog::create([
                        'action' => 'user_disconnected',
                        'details' => ['mac_address' => $session->mac_address],
                    ]);
                    $session->delete();
                } catch (\Exception $e) {
                    Log::warning('Failed to disconnect expired session', [
                        'session_id' => $session->id,
                        'mac_address' => $session->mac_address,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }
}
