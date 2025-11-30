<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "==============================================\n";
echo "COMPREHENSIVE WORKER DETECTION CHECK\n";
echo "==============================================\n\n";

// 1. Check Supervisor Status
echo "[1/7] Supervisor Status\n";
echo "------------------------\n";
$supervisorOutput = shell_exec('supervisorctl status 2>&1');
$lines = explode("\n", $supervisorOutput);
$runningWorkers = 0;
$workerLines = [];
foreach ($lines as $line) {
    if (strpos($line, 'laravel-queue') !== false && strpos($line, 'RUNNING') !== false) {
        $runningWorkers++;
        $workerLines[] = $line;
    }
}
echo "Total RUNNING workers in supervisor: $runningWorkers\n";
echo "Sample (first 5):\n";
foreach (array_slice($workerLines, 0, 5) as $line) {
    echo "  " . trim($line) . "\n";
}
echo "\n";

// 2. Test getWorkersByQueue in Job
echo "[2/7] Testing CollectSystemMetricsJob::getWorkersByQueue()\n";
echo "------------------------------------------------------------\n";
$job = new \App\Jobs\CollectSystemMetricsJob();
$reflection = new ReflectionClass($job);
$method = $reflection->getMethod('getWorkersByQueue');
$method->setAccessible(true);
$workersFromJob = $method->invoke($job);
echo "Workers detected by job: " . array_sum($workersFromJob) . "\n";
echo "Breakdown: " . json_encode($workersFromJob) . "\n\n";

// 3. Test getWorkersByQueue in Controller
echo "[3/7] Testing SystemMetricsController::getWorkersByQueue()\n";
echo "------------------------------------------------------------\n";
$controller = new \App\Http\Controllers\Api\SystemMetricsController();
$reflection = new ReflectionClass($controller);
$method = $reflection->getMethod('getWorkersByQueue');
$method->setAccessible(true);
$workersFromController = $method->invoke($controller);
echo "Workers detected by controller: " . array_sum($workersFromController) . "\n";
echo "Breakdown: " . json_encode($workersFromController) . "\n\n";

// 4. Check Cache
echo "[4/7] Checking Cache\n";
echo "---------------------\n";
$cacheData = \Illuminate\Support\Facades\Cache::get('metrics:queue:latest');
if ($cacheData) {
    echo "Cache exists:\n";
    echo "  active_workers: " . ($cacheData['active_workers'] ?? 'N/A') . "\n";
    echo "  workers_by_queue: " . json_encode($cacheData['workers_by_queue'] ?? []) . "\n";
} else {
    echo "Cache is EMPTY\n";
}
echo "\n";

// 5. Test API Endpoint
echo "[5/7] Testing API Endpoint\n";
echo "---------------------------\n";
$response = $controller->getQueueStats();
$apiData = json_decode($response->getContent(), true);
echo "API Response:\n";
echo "  workers: " . ($apiData['workers'] ?? 'N/A') . "\n";
echo "  workersByQueue: " . json_encode($apiData['workersByQueue'] ?? []) . "\n";
echo "  source: " . ($apiData['source'] ?? 'N/A') . "\n\n";

// 6. Check Queue Table
echo "[6/7] Checking Jobs Queue\n";
echo "--------------------------\n";
$monitoringJobs = DB::table('jobs')->where('queue', 'monitoring')->count();
echo "Jobs in monitoring queue: $monitoringJobs\n";
$allJobs = DB::table('jobs')->count();
echo "Total jobs in queue: $allJobs\n\n";

// 7. Check Recent Logs
echo "[7/7] Checking Recent Logs\n";
echo "---------------------------\n";
$logFile = '/var/www/html/storage/logs/laravel.log';
$logContent = shell_exec("tail -100 $logFile | grep -i 'collected workers\\|system metrics'");
if ($logContent) {
    echo "Recent metrics collection logs:\n";
    $logLines = explode("\n", trim($logContent));
    foreach (array_slice($logLines, -5) as $line) {
        echo "  " . trim($line) . "\n";
    }
} else {
    echo "No recent metrics collection logs found\n";
}

echo "\n==============================================\n";
echo "SUMMARY\n";
echo "==============================================\n";
echo "Supervisor:  $runningWorkers workers\n";
echo "Job Method:  " . array_sum($workersFromJob) . " workers\n";
echo "Controller:  " . array_sum($workersFromController) . " workers\n";
echo "Cache:       " . ($cacheData['active_workers'] ?? 0) . " workers\n";
echo "API:         " . ($apiData['workers'] ?? 0) . " workers\n";
echo "\n";

if ($runningWorkers > 0 && ($apiData['workers'] ?? 0) == 0) {
    echo "❌ PROBLEM: Supervisor has workers but API returns 0\n";
    echo "   This means the job is not updating the cache correctly.\n";
} elseif ($runningWorkers > 0 && ($apiData['workers'] ?? 0) > 0) {
    echo "✅ SUCCESS: Workers are being detected correctly!\n";
} else {
    echo "⚠️  WARNING: No workers running in supervisor\n";
}
