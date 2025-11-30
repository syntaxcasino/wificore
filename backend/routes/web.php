<?php

use Illuminate\Support\Facades\Route;

/* Route::get('/{any}', function () {
    return view('app');
})->where('any', '.*');

 */

// routes/web.php
Route::get('/test-broadcast', function () {
    \Log::info('Test broadcast route called');
    
    // Test broadcast
    event(new App\Events\RouterLiveDataUpdated(999, [
        'test' => 'via_web_route',
        'timestamp' => now()->toISOString()
    ]));
    
    \Log::info('Test event broadcast attempted');
    
    return response()->json([
        'message' => 'Broadcast test completed',
        'timestamp' => now()->toISOString()
    ]);
});

// Test RSC generation
Route::get('/test/rsc-generation', function () {
    $hotspotService = new App\Services\MikroTik\HotspotService();
    
    $interfaces = ['ether3', 'ether4'];
    $routerId = 999;
    $options = [
        'network' => '192.168.88.0/24',
        'gateway' => '192.168.88.1',
        'ip_pool' => '192.168.88.10-192.168.88.254',
        'dns_servers' => '8.8.8.8,1.1.1.1',
        'radius_ip' => 'traidnet-freeradius',
        'radius_secret' => 'testing123',
        'portal_url' => 'https://hotspot.traidnet.co.ke/login',
        'rate_limit' => '10M/10M'
    ];
    
    $rscContent = $hotspotService->generateConfig($interfaces, $routerId, $options);
    
    return response($rscContent, 200, [
        'Content-Type' => 'text/plain',
    ]);
});