<?php

// Test script to debug worker counting

echo "=== Testing Worker Count ===\n\n";

// Get supervisor output
$command = 'supervisorctl status | grep "laravel-queue" | grep "RUNNING"';
$output = shell_exec($command);

echo "Command: $command\n\n";
echo "Raw Output:\n";
echo $output;
echo "\n\n";

// Count total
$lines = explode("\n", trim($output));
echo "Total lines: " . count($lines) . "\n\n";

// Parse workers by queue
$workersByQueue = [];
foreach ($lines as $line) {
    if (empty($line)) continue;
    
    echo "Processing line: $line\n";
    
    // Try the regex
    if (preg_match('/laravel-queue-([a-z0-9\-]+)_\d+/', $line, $matches)) {
        echo "  ✓ Matched! Queue: {$matches[1]}\n";
        $queueName = $matches[1];
        if (!isset($workersByQueue[$queueName])) {
            $workersByQueue[$queueName] = 0;
        }
        $workersByQueue[$queueName]++;
    } else {
        echo "  ✗ No match\n";
    }
}

echo "\n\n=== Results ===\n";
echo "Total workers: " . array_sum($workersByQueue) . "\n";
echo "Workers by queue:\n";
print_r($workersByQueue);
