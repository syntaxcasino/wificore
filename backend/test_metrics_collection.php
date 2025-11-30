<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Metrics Collection\n\n";

// Dispatch the job
$job = new \App\Jobs\CollectSystemMetricsJob();
$job->handle();

echo "\nJob executed!\n\n";

// Check cache
echo "Checking cache:\n";
$queueMetrics = \Illuminate\Support\Facades\Cache::get('metrics:queue:latest');
echo "Queue metrics cached: " . (empty($queueMetrics) ? "NO" : "YES") . "\n";

if (!empty($queueMetrics)) {
    echo "Active workers: " . ($queueMetrics['active_workers'] ?? 0) . "\n";
    echo "Workers by queue: " . json_encode($queueMetrics['workers_by_queue'] ?? []) . "\n";
}
