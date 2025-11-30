<?php

// Direct test of the queue stats endpoint
echo "Testing Queue Stats API\n\n";

// Test 1: Shell exec
echo "1. Testing shell_exec:\n";
$command = 'supervisorctl status | grep "laravel-queue" | grep "RUNNING" | wc -l';
$count = shell_exec($command);
echo "Worker count: " . trim($count) . "\n\n";

// Test 2: Workers by queue
echo "2. Testing workers by queue:\n";
$command = 'supervisorctl status | grep "laravel-queue" | grep "RUNNING"';
$output = shell_exec($command);
$lines = explode("\n", trim($output));

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
echo json_encode($workersByQueue, JSON_PRETTY_PRINT);
echo "\n\n";

// Test 3: Check if it's empty
echo "3. Checking if empty:\n";
echo "Is empty: " . (empty($workersByQueue) ? 'YES' : 'NO') . "\n";
echo "Count: " . count($workersByQueue) . "\n";
echo "Will return: " . json_encode(empty($workersByQueue) ? (object)[] : $workersByQueue) . "\n";
