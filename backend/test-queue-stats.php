<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$controller = new App\Http\Controllers\Api\QueueStatsController();
$response = $controller->index();

echo json_encode($response->getData(), JSON_PRETTY_PRINT) . PHP_EOL;
