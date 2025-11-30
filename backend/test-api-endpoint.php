<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Testing Queue Stats API Endpoint\n";
echo "=================================\n\n";

$controller = new \App\Http\Controllers\Api\SystemMetricsController();
$response = $controller->getQueueStats();
$data = json_decode($response->getContent(), true);

echo "Raw Response:\n";
echo json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

echo "Summary:\n";
echo "--------\n";
echo "Workers: " . ($data['workers'] ?? 0) . "\n";
echo "Pending: " . ($data['pending'] ?? 0) . "\n";
echo "Processing: " . ($data['processing'] ?? 0) . "\n";
echo "Failed: " . ($data['failed'] ?? 0) . "\n";
echo "Completed: " . ($data['completed'] ?? 0) . "\n";
echo "Source: " . ($data['source'] ?? 'unknown') . "\n\n";

if (!empty($data['workersByQueue'])) {
    echo "Workers by Queue:\n";
    foreach ($data['workersByQueue'] as $queue => $count) {
        echo "  - $queue: $count\n";
    }
} else {
    echo "No workers by queue data\n";
}
