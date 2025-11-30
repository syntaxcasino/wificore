<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Testing Queue Stats API ===\n\n";

// Test 1: Check if supervisorctl works
echo "Test 1: Supervisorctl command\n";
$command = 'supervisorctl status | grep "laravel-queue" | grep "RUNNING" | wc -l';
$output = shell_exec($command);
echo "Command: $command\n";
echo "Output: " . trim($output) . "\n\n";

// Test 2: Get workers by queue
echo "Test 2: Workers by queue\n";
$command = 'supervisorctl status | grep "laravel-queue" | grep "RUNNING"';
$output = shell_exec($command);
$lines = explode("\n", trim($output));
echo "Total lines: " . count($lines) . "\n";

$workersByQueue = [];
foreach ($lines as $line) {
    if (empty($line)) continue;
    
    if (preg_match('/laravel-queue-([a-z0-9\-]+)_\d+/', $line, $matches)) {
        $queueName = $matches[1];
        if (!isset($workersByQueue[$queueName])) {
            $workersByQueue[$queueName] = 0;
        }
        $workersByQueue[$queueName]++;
    }
}

echo "Workers by queue:\n";
print_r($workersByQueue);
echo "\n";

// Test 3: Call the actual controller method
echo "Test 3: Controller method\n";
try {
    $controller = new \App\Http\Controllers\Api\SystemMetricsController();
    $response = $controller->getQueueStats();
    $data = $response->getData(true);
    
    echo "API Response:\n";
    echo json_encode($data, JSON_PRETTY_PRINT);
    echo "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
