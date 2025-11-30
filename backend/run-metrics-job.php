<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Running CollectSystemMetricsJob...\n\n";

try {
    $job = new \App\Jobs\CollectSystemMetricsJob();
    $job->handle();
    
    echo "Job completed successfully!\n\n";
    
    // Check cache
    echo "Checking cache...\n";
    $queueMetrics = \Illuminate\Support\Facades\Cache::get('metrics:queue:latest');
    
    if ($queueMetrics) {
        echo "Cache updated successfully!\n";
        echo "Workers: " . ($queueMetrics['active_workers'] ?? 0) . "\n";
        echo "Workers by queue: " . json_encode($queueMetrics['workers_by_queue'] ?? []) . "\n";
    } else {
        echo "Cache is empty!\n";
    }
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
