<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use App\Events\RouterStatusUpdated;

class RouterStatusController extends BaseApiController
{
    public function getStatus()
    {
        $status = $this->checkRouterStatus();
        return response()->json(['online' => $status]);
    }

    private function checkRouterStatus()
    {
        $routerIp = '192.168.88.1';  // Replace with your Mikrotik IP

        // Simple ping check (works on Linux/Mac/Windows)
        $pingCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' 
            ? "ping -n 1 -w 1 $routerIp" 
            : "ping -c 1 -W 1 $routerIp";
        
        exec($pingCommand, $output, $result);
        $isOnline = $result === 0;

        // Alternative: Use RouterOS API for more reliable check
        // require_once 'vendor/autoload.php';
        // use PEAR2\Net\RouterOS;
        // try {
        //     $client = new RouterOS\Client($routerIp, 'admin', 'password');
        //     $isOnline = true;  // Login success means online
        // } catch (\Exception $e) {
        //     $isOnline = false;
        // }

        // Broadcast only if status changed
        $previousStatus = Cache::get('router_online', false);
        if ($isOnline !== $previousStatus) {
            Cache::put('router_online', $isOnline, now()->addMinutes(10));
            broadcast(new RouterStatusUpdated($isOnline));
        }

        return $isOnline;
    }
}