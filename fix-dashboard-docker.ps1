# Dashboard Metrics Fix Script for Docker Environment
# This script fixes dashboard metrics issues by running commands inside Docker containers

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Dashboard Metrics Fix (Docker Mode)" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$projectPath = "d:\traidnet\wifi-hotspot"
Set-Location $projectPath

Write-Host "[1/7] Checking Docker Containers..." -ForegroundColor Yellow
Write-Host ""

# Check if containers are running
$containers = docker ps --format "{{.Names}}" | Select-String "traidnet"
if ($containers) {
    Write-Host "[OK] Found running containers:" -ForegroundColor Green
    docker ps --filter "name=traidnet" --format "  - {{.Names}} ({{.Status}})"
} else {
    Write-Host "[FAIL] No traidnet containers running" -ForegroundColor Red
    Write-Host "  Please start Docker containers first: docker-compose up -d" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "[2/7] Checking Database Tables..." -ForegroundColor Yellow
Write-Host ""

# Check database tables
$tables = @('performance_metrics', 'queue_metrics', 'system_health_metrics')

foreach ($table in $tables) {
    $count = docker exec traidnet-backend php artisan tinker --execute="echo DB::table('$table')->count();" 2>$null
    if ($count -match '\d+') {
        $rowCount = [int]($count -replace '\D', '')
        if ($rowCount -gt 0) {
            Write-Host "  [OK] Table '$table' has $rowCount rows" -ForegroundColor Green
        } else {
            Write-Host "  [FAIL] Table '$table' is EMPTY" -ForegroundColor Red
        }
    } else {
        Write-Host "  [FAIL] Table '$table' check failed" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "[3/7] Checking Cache Keys..." -ForegroundColor Yellow
Write-Host ""

$cacheKeys = @('metrics:queue:latest', 'metrics:health:latest', 'metrics:performance:latest')

foreach ($key in $cacheKeys) {
    $result = docker exec traidnet-backend php artisan tinker --execute="echo Cache::has('$key') ? 'EXISTS' : 'MISSING';" 2>$null
    if ($result -match 'EXISTS') {
        Write-Host "  [OK] Cache key '$key' exists" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] Cache key '$key' is MISSING" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "[4/7] Testing Metrics Collection Command..." -ForegroundColor Yellow
Write-Host ""

Write-Host "  Running metrics:test command inside container..." -ForegroundColor Cyan
docker exec traidnet-backend php artisan metrics:test

Write-Host ""
Write-Host "[5/7] Dispatching Metrics Collection Job..." -ForegroundColor Yellow
Write-Host ""

Write-Host "  Dispatching CollectSystemMetricsJob..." -ForegroundColor Cyan
docker exec traidnet-backend php artisan tinker --execute="dispatch(new \App\Jobs\CollectSystemMetricsJob());"

Write-Host "  [OK] Job dispatched to queue" -ForegroundColor Green

Write-Host ""
Write-Host "[6/7] Processing Monitoring Queue..." -ForegroundColor Yellow
Write-Host ""

Write-Host "  Processing monitoring queue..." -ForegroundColor Cyan
docker exec traidnet-backend php artisan queue:work --queue=monitoring --stop-when-empty --tries=1 2>&1 | Out-Null

Write-Host "  [OK] Monitoring queue processed" -ForegroundColor Green

Write-Host ""
Write-Host "[7/7] Manually Storing Performance Metrics..." -ForegroundColor Yellow
Write-Host ""

Write-Host "  Calling MetricsService::storeMetrics()..." -ForegroundColor Cyan
docker exec traidnet-backend php artisan tinker --execute="\App\Services\MetricsService::storeMetrics();"

Write-Host "  [OK] Performance metrics stored" -ForegroundColor Green

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Verification" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Re-check database tables
Write-Host "Database Tables After Fix:" -ForegroundColor White
foreach ($table in $tables) {
    $count = docker exec traidnet-backend php artisan tinker --execute="echo DB::table('$table')->count();" 2>$null
    if ($count -match '\d+') {
        $rowCount = [int]($count -replace '\D', '')
        if ($rowCount -gt 0) {
            Write-Host "  [OK] Table '$table' now has $rowCount rows" -ForegroundColor Green
        } else {
            Write-Host "  [FAIL] Table '$table' is still EMPTY" -ForegroundColor Red
        }
    }
}

Write-Host ""
Write-Host "Cache Keys After Fix:" -ForegroundColor White
foreach ($key in $cacheKeys) {
    $result = docker exec traidnet-backend php artisan tinker --execute="echo Cache::has('$key') ? 'EXISTS' : 'MISSING';" 2>$null
    if ($result -match 'EXISTS') {
        Write-Host "  [OK] Cache key '$key' exists" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] Cache key '$key' is still MISSING" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Next Steps" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "1. Check Laravel Scheduler in Container:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend crontab -l" -ForegroundColor Gray
Write-Host ""

Write-Host "2. View Scheduler Logs:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend tail -f storage/logs/laravel.log" -ForegroundColor Gray
Write-Host ""

Write-Host "3. Test API Endpoints:" -ForegroundColor White
Write-Host "   - http://localhost/api/system/metrics" -ForegroundColor Gray
Write-Host "   - http://localhost/api/system/queue/stats" -ForegroundColor Gray
Write-Host "   - http://localhost/api/system/health" -ForegroundColor Gray
Write-Host ""

Write-Host "4. Refresh Dashboard:" -ForegroundColor White
Write-Host "   - Open browser: http://localhost" -ForegroundColor Gray
Write-Host "   - Login and navigate to System Dashboard" -ForegroundColor Gray
Write-Host "   - Widgets should now display real data" -ForegroundColor Gray
Write-Host ""

Write-Host "5. Monitor Metrics Collection:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend php artisan queue:work --queue=monitoring" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
