<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$failedJob = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->first();

if ($failedJob) {
    echo "Latest Failed Job Error:\n";
    echo "=======================\n";
    echo substr($failedJob->exception, 0, 2000) . "\n";
} else {
    echo "No failed jobs found.\n";
}
