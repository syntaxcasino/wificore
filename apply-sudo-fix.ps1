# Apply sudo fix for supervisorctl access
Write-Host "Applying sudo fix for reliable worker detection..." -ForegroundColor Cyan
Write-Host ""

# Step 1: Install sudo in container
Write-Host "[1/5] Installing sudo in container..." -ForegroundColor Yellow
docker exec traidnet-backend apt-get update
docker exec traidnet-backend apt-get install -y sudo
Write-Host "✓ sudo installed" -ForegroundColor Green
Write-Host ""

# Step 2: Copy sudoers file
Write-Host "[2/5] Configuring sudoers for www-data..." -ForegroundColor Yellow
docker cp backend/supervisor/sudoers-supervisorctl traidnet-backend:/etc/sudoers.d/supervisorctl
docker exec traidnet-backend chmod 0440 /etc/sudoers.d/supervisorctl
docker exec traidnet-backend chown root:root /etc/sudoers.d/supervisorctl
Write-Host "✓ sudoers configured" -ForegroundColor Green
Write-Host ""

# Step 3: Verify sudo configuration
Write-Host "[3/5] Verifying sudo configuration..." -ForegroundColor Yellow
$testResult = docker exec -u www-data traidnet-backend sudo -n /usr/bin/supervisorctl status 2>&1
if ($testResult -match "RUNNING") {
    Write-Host "✓ sudo configuration working!" -ForegroundColor Green
} else {
    Write-Host "✗ sudo configuration failed" -ForegroundColor Red
    Write-Host "Output: $testResult"
    exit 1
}
Write-Host ""

# Step 4: Copy updated job file
Write-Host "[4/5] Updating CollectSystemMetricsJob..." -ForegroundColor Yellow
docker cp backend/app/Jobs/CollectSystemMetricsJob.php traidnet-backend:/var/www/html/app/Jobs/CollectSystemMetricsJob.php
Write-Host "✓ Job file updated" -ForegroundColor Green
Write-Host ""

# Step 5: Restart monitoring worker
Write-Host "[5/5] Restarting monitoring worker..." -ForegroundColor Yellow
docker exec traidnet-backend supervisorctl restart laravel-queues:laravel-queue-monitoring_00
Write-Host "✓ Worker restarted" -ForegroundColor Green
Write-Host ""

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Fix Applied Successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "The system will now:" -ForegroundColor White
Write-Host "  - Detect actual running workers (not static count)" -ForegroundColor Green
Write-Host "  - Show 0 workers if they are actually down" -ForegroundColor Green
Write-Host "  - Update every minute via scheduler" -ForegroundColor Green
Write-Host ""
Write-Host "Wait 60 seconds for the next scheduled run then refresh your dashboard" -ForegroundColor Yellow
Write-Host ""
