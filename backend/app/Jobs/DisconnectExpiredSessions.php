<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Models\UserSession;
use App\Services\MikrotikService;
use App\Models\SystemLog;

class DisconnectExpiredSessions implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
       
        $expiredSessions = UserSession::where('end_time', '<', now())->get();
        $mikrotik = new MikrotikService();

        foreach ($expiredSessions as $session) {
            $mikrotik->disconnectUser($session->mac_address);
            SystemLog::create([
                'action' => 'user_disconnected',
                'details' => json_encode(['mac_address' => $session->mac_address])
            ]);
            $session->delete();
        }
    }
}
