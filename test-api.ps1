# Test API Endpoint
Write-Host "Testing Queue Stats API Endpoint..." -ForegroundColor Cyan
Write-Host ""

# Get auth token (if needed)
$response = docker exec traidnet-backend php -r '
require __DIR__ . "/vendor/autoload.php";
$app = require_once __DIR__ . "/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$controller = new \App\Http\Controllers\Api\SystemMetricsController();
$response = $controller->getQueueStats();
echo $response->getContent();
'

Write-Host "API Response:" -ForegroundColor Yellow
Write-Host $response
Write-Host ""

# Parse and display nicely
try {
    $data = $response | ConvertFrom-Json
    Write-Host "Workers: $($data.workers)" -ForegroundColor Green
    Write-Host "Pending: $($data.pending)" -ForegroundColor Yellow
    Write-Host "Processing: $($data.processing)" -ForegroundColor Cyan
    Write-Host "Failed: $($data.failed)" -ForegroundColor Red
    Write-Host "Completed: $($data.completed)" -ForegroundColor Green
    Write-Host "Source: $($data.source)" -ForegroundColor Magenta
    Write-Host ""
    Write-Host "Workers by Queue:" -ForegroundColor Cyan
    $data.workersByQueue.PSObject.Properties | ForEach-Object {
        Write-Host "  $($_.Name): $($_.Value)" -ForegroundColor White
    }
} catch {
    Write-Host "Could not parse JSON response" -ForegroundColor Red
}
