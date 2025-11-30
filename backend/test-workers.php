<?php

echo "===========================================\n";
echo "Testing Worker Detection\n";
echo "===========================================\n\n";

// Test 1: shell_exec availability
echo "[1/5] Testing shell_exec availability...\n";
echo "shell_exec function exists: " . (function_exists('shell_exec') ? 'YES' : 'NO') . "\n";
echo "exec function exists: " . (function_exists('exec') ? 'YES' : 'NO') . "\n\n";

// Test 2: Execute supervisorctl
echo "[2/5] Testing supervisorctl via shell_exec...\n";
$output = shell_exec('supervisorctl status 2>&1');
if ($output) {
    echo "Output length: " . strlen($output) . " bytes\n";
    echo "First 500 chars:\n";
    echo substr($output, 0, 500) . "\n\n";
} else {
    echo "NO OUTPUT\n\n";
}

// Test 3: Execute via exec()
echo "[3/5] Testing supervisorctl via exec()...\n";
$lines = [];
$return_var = 0;
exec('supervisorctl status 2>&1', $lines, $return_var);
echo "Return code: $return_var\n";
echo "Lines count: " . count($lines) . "\n";
if (count($lines) > 0) {
    echo "First 5 lines:\n";
    foreach (array_slice($lines, 0, 5) as $line) {
        echo "  - $line\n";
    }
}
echo "\n";

// Test 4: Parse worker lines
echo "[4/5] Parsing worker lines...\n";
$workersByQueue = [];
foreach ($lines as $line) {
    if (empty($line) || !preg_match('/RUNNING/', $line)) {
        continue;
    }
    
    if (preg_match('/laravel-queue(?:s)?:laravel-queue-([a-z0-9\-]+)_\d+/i', $line, $matches)) {
        $queueName = $matches[1];
        if (!isset($workersByQueue[$queueName])) {
            $workersByQueue[$queueName] = 0;
        }
        $workersByQueue[$queueName]++;
    }
}

echo "Workers detected: " . array_sum($workersByQueue) . "\n";
echo "Queues: " . count($workersByQueue) . "\n";
echo "Breakdown:\n";
foreach ($workersByQueue as $queue => $count) {
    echo "  - $queue: $count\n";
}
echo "\n";

// Test 5: Test via Laravel
echo "[5/5] Testing via Laravel controller...\n";
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $controller = new \App\Http\Controllers\Api\SystemMetricsController();
    $response = $controller->getQueueStats();
    $data = json_decode($response->getContent(), true);
    
    echo "API Response:\n";
    echo "  - Workers: " . ($data['workers'] ?? 0) . "\n";
    echo "  - Pending: " . ($data['pending'] ?? 0) . "\n";
    echo "  - Failed: " . ($data['failed'] ?? 0) . "\n";
    echo "  - Source: " . ($data['source'] ?? 'unknown') . "\n";
    echo "  - Workers by queue: " . (isset($data['workersByQueue']) ? json_encode($data['workersByQueue']) : 'none') . "\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n===========================================\n";
echo "Test Complete\n";
echo "===========================================\n";
