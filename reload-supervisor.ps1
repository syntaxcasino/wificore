# Reload Supervisor Configuration
# This script reloads Supervisor to apply the new monitoring queue worker configuration

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Reload Supervisor Configuration" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$projectPath = "d:\traidnet\wifi-hotspot"
Set-Location $projectPath

Write-Host "[1/5] Checking Docker Container..." -ForegroundColor Yellow
Write-Host ""

$backend = docker ps --filter "name=traidnet-backend" --format "{{.Names}}"
if ($backend) {
    Write-Host "  [OK] Backend container is running" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] Backend container is not running" -ForegroundColor Red
    Write-Host "  Please start containers: docker-compose up -d" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "[2/5] Copying Updated Supervisor Config..." -ForegroundColor Yellow
Write-Host ""

# Copy the updated laravel-queue.conf (which now includes monitoring queue)
docker cp backend/supervisor/laravel-queue.conf traidnet-backend:/etc/supervisor/conf.d/laravel-queue.conf

if ($LASTEXITCODE -eq 0) {
    Write-Host "  [OK] Queue config (with monitoring queue) copied to container" -ForegroundColor Green
} else {
    Write-Host "  [FAIL] Failed to copy config" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "[3/5] Reloading Supervisor..." -ForegroundColor Yellow
Write-Host ""

# Reload Supervisor configuration
docker exec traidnet-backend supervisorctl reread
docker exec traidnet-backend supervisorctl update

Write-Host "  [OK] Supervisor configuration reloaded" -ForegroundColor Green

Write-Host ""
Write-Host "[4/5] Starting Monitoring Queue Worker..." -ForegroundColor Yellow
Write-Host ""

# Start the new monitoring queue worker
docker exec traidnet-backend supervisorctl start laravel-queue-monitoring:*

Write-Host "  [OK] Monitoring queue worker started" -ForegroundColor Green

Write-Host ""
Write-Host "[5/5] Verifying Supervisor Status..." -ForegroundColor Yellow
Write-Host ""

# Show status of all programs
Write-Host "  Current Supervisor Status:" -ForegroundColor Cyan
docker exec traidnet-backend supervisorctl status | Select-String -Pattern "laravel-scheduler|laravel-queue-monitoring"

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Configuration Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Supervisor Programs Configured:" -ForegroundColor White
Write-Host "  1. laravel-scheduler" -ForegroundColor Gray
Write-Host "     - Runs: php artisan schedule:work" -ForegroundColor DarkGray
Write-Host "     - Purpose: Executes scheduled tasks every minute" -ForegroundColor DarkGray
Write-Host ""
Write-Host "  2. laravel-queue-monitoring (NEW)" -ForegroundColor Gray
Write-Host "     - Runs: php artisan queue:work --queue=monitoring" -ForegroundColor DarkGray
Write-Host "     - Purpose: Processes metrics collection jobs" -ForegroundColor DarkGray
Write-Host ""

Write-Host "Scheduled Tasks (via laravel-scheduler):" -ForegroundColor White
Write-Host "  - CollectSystemMetricsJob: Every minute" -ForegroundColor Gray
Write-Host "  - Reset TPS Counter: Every minute" -ForegroundColor Gray
Write-Host "  - Store Performance Metrics: Every 5 minutes" -ForegroundColor Gray
Write-Host "  - Cleanup Old Metrics: Daily at 2 AM" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Next Steps" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "1. Monitor Supervisor Logs:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend tail -f /var/www/html/storage/logs/monitoring-queue.log" -ForegroundColor Gray
Write-Host ""

Write-Host "2. Check Scheduler Logs:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log | Select-String 'metrics'" -ForegroundColor Gray
Write-Host ""

Write-Host "3. Verify Metrics Collection:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend php artisan tinker --execute=`"echo 'Queue: ' . DB::table('queue_metrics')->count(); echo 'Health: ' . DB::table('system_health_metrics')->count();`"" -ForegroundColor Gray
Write-Host ""

Write-Host "4. Check Supervisor Status Anytime:" -ForegroundColor White
Write-Host "   docker exec traidnet-backend supervisorctl status" -ForegroundColor Gray
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Reload Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "The monitoring queue worker is now running and will automatically" -ForegroundColor White
Write-Host "process metrics collection jobs dispatched by the scheduler." -ForegroundColor White
Write-Host ""
