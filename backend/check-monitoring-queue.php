<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "Checking monitoring queue...\n\n";

$jobs = DB::table('jobs')->where('queue', 'monitoring')->get();
echo "Jobs in monitoring queue: " . $jobs->count() . "\n\n";

if ($jobs->count() > 0) {
    foreach ($jobs as $job) {
        $payload = json_decode($job->payload, true);
        echo "Job ID: {$job->id}\n";
        echo "Command: " . ($payload['displayName'] ?? 'Unknown') . "\n";
        echo "Attempts: {$job->attempts}\n";
        echo "Reserved: " . ($job->reserved_at ? 'Yes' : 'No') . "\n";
        echo "---\n";
    }
}

// Check if monitoring worker is running
echo "\nChecking supervisor status for monitoring worker...\n";
$output = shell_exec('supervisorctl status | grep monitoring');
echo $output ?: "No monitoring worker found\n";
