<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use RouterOS\Client;
use App\Events\RouterStatusUpdated;
use Illuminate\Support\Facades\Log;

class CheckRouterStatus extends Command
{
    protected $signature = 'router:check-status';
    protected $description = 'Check MikroTik router online status';

    public function handle()
    {
        try {
            $client = new Client([
                'host' => config('mikrotik.host'),
                'user' => config('mikrotik.user'),
                'pass' => config('mikrotik.pass'),
                'port' => config('mikrotik.port'),
            ]);

            // Simple query to test connection
            $query = new \RouterOS\Query('/system/identity/print');
            $response = $client->query($query)->read();

            $status = !empty($response) ? 'online' : 'offline';
            Log::info('Router status: ' . $status);
        } catch (\Exception $e) {
            $status = 'offline';
            Log::error('Router check failed: ' . $e->getMessage());
        }

        // Broadcast the status
        broadcast(new RouterStatusUpdated($status))->toOthers();

        $this->info('Status broadcast: ' . $status);
    }
}