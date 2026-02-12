#!/usr/bin/env php
<?php

// Bootstrap Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "=== VictoriaMetrics Configuration Debug ===\n\n";

echo "ENV VICTORIA_METRICS_WRITE_URL: " . env('VICTORIA_METRICS_WRITE_URL', 'NOT SET') . "\n";
echo "config('victoriametrics.write_url'): " . config('victoriametrics.write_url', 'NOT SET') . "\n";

echo "\n=== Full victoriametrics config ===\n";
print_r(config('victoriametrics'));

echo "\n=== Checking if config is cached ===\n";
$cacheFile = base_path('bootstrap/cache/config.php');
if (file_exists($cacheFile)) {
    echo "Config cache EXISTS at: $cacheFile\n";
    echo "Last modified: " . date('Y-m-d H:i:s', filemtime($cacheFile)) . "\n";
} else {
    echo "Config cache does NOT exist\n";
}

echo "\n=== Checking .env file ===\n";
$envFile = base_path('.env.production');
if (file_exists($envFile)) {
    $lines = file($envFile);
    foreach ($lines as $line) {
        if (strpos($line, 'VICTORIA_METRICS_WRITE_URL') !== false) {
            echo trim($line) . "\n";
        }
    }
} else {
    echo ".env.production not found\n";
}
