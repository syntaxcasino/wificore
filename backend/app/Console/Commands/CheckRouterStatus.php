<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Events\RouterStatusUpdated;
use Illuminate\Support\Facades\Log;

class CheckRouterStatus extends Command
{
    protected $signature = 'router:check-status';
    protected $description = 'Check MikroTik router online status';

    public function handle()
    {
        try {
            $routerIp = config('mikrotik.host');

            // Simple ping-based connectivity check (no RouterOS API)
            $pingCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN'
                ? "ping -n 1 -w 1000 {$routerIp}"
                : "ping -c 1 -W 1 {$routerIp}";

            exec($pingCommand, $output, $resultCode);
            $status = $resultCode === 0 ? 'online' : 'offline';

            Log::info('Router status (ping): ' . $status, [
                'router_ip' => $routerIp,
                'result_code' => $resultCode,
            ]);
        } catch (\Exception $e) {
            $status = 'offline';
            Log::error('Router check failed: ' . $e->getMessage());
        }

        // Broadcast the status
        broadcast(new RouterStatusUpdated($status))->toOthers();

        $this->info('Status broadcast: ' . $status);
    }
}