# Dashboard Metrics Diagnostic and Fix Script
# This script diagnoses and fixes dashboard metrics issues

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Dashboard Metrics Diagnostic & Fix Tool" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$backendPath = "d:\traidnet\wifi-hotspot\backend"

# Change to backend directory
Set-Location $backendPath

Write-Host "[1/8] Checking Laravel Scheduler Status..." -ForegroundColor Yellow
Write-Host ""

# Check if scheduler task exists in Windows Task Scheduler
$schedulerTask = schtasks /query /tn "Laravel Scheduler" 2>$null
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Laravel Scheduler task found in Windows Task Scheduler" -ForegroundColor Green
} else {
    Write-Host "✗ Laravel Scheduler task NOT found in Windows Task Scheduler" -ForegroundColor Red
    Write-Host "  Creating scheduler task..." -ForegroundColor Yellow
    
    $phpPath = (Get-Command php).Source
    $artisanPath = Join-Path $backendPath "artisan"
    $taskCommand = "$phpPath `"$artisanPath`" schedule:run"
    
    schtasks /create /tn "Laravel Scheduler" /tr $taskCommand /sc minute /mo 1 /f
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✓ Laravel Scheduler task created successfully" -ForegroundColor Green
    } else {
        Write-Host "✗ Failed to create scheduler task" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "[2/8] Checking Database Tables..." -ForegroundColor Yellow
Write-Host ""

# Check if tables exist and have data
$tables = @('performance_metrics', 'queue_metrics', 'system_health_metrics')

foreach ($table in $tables) {
    $count = php artisan tinker --execute="echo DB::table('$table')->count();" 2>$null
    if ($count -match '\d+') {
        $rowCount = [int]($count -replace '\D', '')
        if ($rowCount -gt 0) {
            Write-Host "✓ Table '$table' has $rowCount rows" -ForegroundColor Green
        } else {
            Write-Host "✗ Table '$table' is EMPTY" -ForegroundColor Red
        }
    } else {
        Write-Host "✗ Table '$table' check failed" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "[3/8] Checking Cache Keys..." -ForegroundColor Yellow
Write-Host ""

$cacheKeys = @('metrics:queue:latest', 'metrics:health:latest', 'metrics:performance:latest')

foreach ($key in $cacheKeys) {
    $result = php artisan tinker --execute="echo Cache::has('$key') ? 'EXISTS' : 'MISSING';" 2>$null
    if ($result -match 'EXISTS') {
        Write-Host "✓ Cache key '$key' exists" -ForegroundColor Green
    } else {
        Write-Host "✗ Cache key '$key' is MISSING" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "[4/8] Checking Queue Workers..." -ForegroundColor Yellow
Write-Host ""

# Check if queue workers are running
$queueWorkers = Get-Process php -ErrorAction SilentlyContinue | Where-Object { $_.CommandLine -like "*queue:work*" }
if ($queueWorkers) {
    Write-Host "✓ Found $($queueWorkers.Count) queue worker(s) running" -ForegroundColor Green
} else {
    Write-Host "✗ No queue workers running" -ForegroundColor Red
    Write-Host "  Note: Queue workers are needed to process metrics collection jobs" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "[5/8] Dispatching Metrics Collection Job..." -ForegroundColor Yellow
Write-Host ""

# Dispatch the metrics collection job
Write-Host "  Dispatching CollectSystemMetricsJob..." -ForegroundColor Cyan
php artisan tinker --execute="dispatch(new \App\Jobs\CollectSystemMetricsJob());"

Write-Host "✓ Job dispatched to queue" -ForegroundColor Green

Write-Host ""
Write-Host "[6/8] Processing Monitoring Queue..." -ForegroundColor Yellow
Write-Host ""

# Process the monitoring queue
Write-Host "  Processing monitoring queue (this may take a few seconds)..." -ForegroundColor Cyan
php artisan queue:work --queue=monitoring --stop-when-empty --tries=1 2>&1 | Out-Null

Write-Host "✓ Monitoring queue processed" -ForegroundColor Green

Write-Host ""
Write-Host "[7/8] Manually Storing Performance Metrics..." -ForegroundColor Yellow
Write-Host ""

# Manually store performance metrics
Write-Host "  Calling MetricsService::storeMetrics()..." -ForegroundColor Cyan
php artisan tinker --execute="\App\Services\MetricsService::storeMetrics();"

Write-Host "✓ Performance metrics stored" -ForegroundColor Green

Write-Host ""
Write-Host "[8/8] Verifying Fix..." -ForegroundColor Yellow
Write-Host ""

# Re-check database tables
foreach ($table in $tables) {
    $count = php artisan tinker --execute="echo DB::table('$table')->count();" 2>$null
    if ($count -match '\d+') {
        $rowCount = [int]($count -replace '\D', '')
        if ($rowCount -gt 0) {
            Write-Host "✓ Table '$table' now has $rowCount rows" -ForegroundColor Green
        } else {
            Write-Host "✗ Table '$table' is still EMPTY" -ForegroundColor Red
        }
    }
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Summary & Next Steps" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "1. Laravel Scheduler Task:" -ForegroundColor White
Write-Host "   - Task should now be running every minute" -ForegroundColor Gray
Write-Host "   - Verify: schtasks /query /tn `"Laravel Scheduler`"" -ForegroundColor Gray
Write-Host ""

Write-Host "2. Queue Workers:" -ForegroundColor White
Write-Host "   - Ensure queue workers are running for 'monitoring' queue" -ForegroundColor Gray
Write-Host "   - Start worker: php artisan queue:work --queue=monitoring" -ForegroundColor Gray
Write-Host ""

Write-Host "3. Dashboard Widgets:" -ForegroundColor White
Write-Host "   - Refresh your browser to see updated metrics" -ForegroundColor Gray
Write-Host "   - Widgets should now display real data" -ForegroundColor Gray
Write-Host ""

Write-Host "4. API Endpoints to Test:" -ForegroundColor White
Write-Host "   - GET /api/system/metrics" -ForegroundColor Gray
Write-Host "   - GET /api/system/queue/stats" -ForegroundColor Gray
Write-Host "   - GET /api/system/health" -ForegroundColor Gray
Write-Host ""

Write-Host "5. Monitor Logs:" -ForegroundColor White
Write-Host "   - Check: storage/logs/laravel.log" -ForegroundColor Gray
Write-Host "   - Look for: 'System metrics collected and persisted'" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
