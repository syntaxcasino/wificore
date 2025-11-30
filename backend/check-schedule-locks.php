<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking schedule locks...\n\n";

// Check Redis for schedule locks
try {
    $redis = \Illuminate\Support\Facades\Redis::connection();
    $keys = $redis->keys('*schedule*');
    
    echo "Schedule-related keys in Redis: " . count($keys) . "\n";
    foreach ($keys as $key) {
        $value = $redis->get($key);
        $ttl = $redis->ttl($key);
        echo "  - $key (TTL: {$ttl}s)\n";
        if ($value) {
            echo "    Value: " . substr($value, 0, 100) . "\n";
        }
    }
    
    echo "\n";
    
    // Check for framework locks
    $lockKeys = $redis->keys('*framework*lock*');
    echo "Framework lock keys: " . count($lockKeys) . "\n";
    foreach ($lockKeys as $key) {
        $ttl = $redis->ttl($key);
        echo "  - $key (TTL: {$ttl}s)\n";
    }
    
} catch (\Exception $e) {
    echo "Error checking Redis: " . $e->getMessage() . "\n";
}

echo "\n";

// Try to manually dispatch the job
echo "Manually dispatching CollectSystemMetricsJob...\n";
try {
    \App\Jobs\CollectSystemMetricsJob::dispatch();
    echo "Job dispatched successfully!\n";
    
    // Check if it's in the queue
    sleep(1);
    $count = DB::table('jobs')->where('queue', 'monitoring')->count();
    echo "Jobs in monitoring queue after dispatch: $count\n";
} catch (\Exception $e) {
    echo "Error dispatching job: " . $e->getMessage() . "\n";
}
