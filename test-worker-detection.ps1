# Test Worker Detection
Write-Host "Testing if PHP can execute supervisorctl..." -ForegroundColor Cyan
Write-Host ""

# Test 1: Can PHP execute shell commands?
Write-Host "[1/4] Testing shell_exec availability..." -ForegroundColor Yellow
docker exec traidnet-backend php -r 'echo function_exists("shell_exec") ? "YES" : "NO";'
Write-Host ""

# Test 2: Can PHP execute supervisorctl?
Write-Host "[2/4] Testing supervisorctl via shell_exec..." -ForegroundColor Yellow
docker exec traidnet-backend php -r 'echo shell_exec("supervisorctl status 2>&1");'
Write-Host ""

# Test 3: Can PHP execute via exec()?
Write-Host "[3/4] Testing supervisorctl via exec()..." -ForegroundColor Yellow
docker exec traidnet-backend php -r 'exec("supervisorctl status 2>&1", $output); echo implode("\n", $output);'
Write-Host ""

# Test 4: Test the actual API endpoint
Write-Host "[4/4] Testing API endpoint..." -ForegroundColor Yellow
docker exec traidnet-backend php artisan route:list | Select-String "queue/stats"
Write-Host ""

Write-Host "Calling API endpoint directly..." -ForegroundColor Cyan
$response = docker exec traidnet-backend php -r '$controller = new \App\Http\Controllers\Api\SystemMetricsController(); $response = $controller->getQueueStats(); echo $response->getContent();'
Write-Host $response
Write-Host ""
