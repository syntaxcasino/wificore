<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking Cache Contents\n";
echo "=======================\n\n";

$cacheKey = 'metrics:queue:latest';
$data = \Illuminate\Support\Facades\Cache::get($cacheKey);

if ($data) {
    echo "Cache key '$cacheKey' exists:\n";
    echo json_encode($data, JSON_PRETTY_PRINT) . "\n\n";
    
    echo "Workers: " . ($data['active_workers'] ?? 0) . "\n";
    echo "Workers by queue: " . json_encode($data['workers_by_queue'] ?? []) . "\n";
} else {
    echo "Cache key '$cacheKey' is EMPTY\n\n";
}

// Check Redis directly
echo "\nChecking Redis directly...\n";
try {
    $redis = \Illuminate\Support\Facades\Redis::connection();
    $keys = $redis->keys('*metrics*queue*');
    echo "Found " . count($keys) . " keys matching '*metrics*queue*':\n";
    foreach ($keys as $key) {
        echo "  - $key\n";
        $value = $redis->get($key);
        if ($value) {
            $decoded = json_decode($value, true);
            if ($decoded) {
                echo "    Workers: " . ($decoded['active_workers'] ?? 'N/A') . "\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "Error checking Redis: " . $e->getMessage() . "\n";
}
