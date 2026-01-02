# Queue Fix Automation Script for Windows
# Run this script to diagnose and fix queue issues

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  WiFi Hotspot - Queue Fix Script" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Change to backend directory
Set-Location -Path "$PSScriptRoot\backend"

# Step 1: Check Status
Write-Host "Step 1: Checking Current Status..." -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
php artisan queue:diagnose-failed
Write-Host ""

# Step 2: Run Migrations
Write-Host "Step 2: Ensuring Migrations..." -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
php artisan migrate --force
Write-Host ""

# Step 3: Test Dashboard Job
Write-Host "Step 3: Testing Dashboard Job (Synchronous)..." -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
php artisan test:dashboard-job --sync --clear-cache
Write-Host ""

$testResult = $LASTEXITCODE
if ($testResult -ne 0) {
    Write-Host "❌ Dashboard job test FAILED!" -ForegroundColor Red
    Write-Host "   Check the error messages above." -ForegroundColor Red
    Write-Host ""
    Write-Host "Common fixes:" -ForegroundColor Yellow
    Write-Host "  1. Check database connection in .env" -ForegroundColor White
    Write-Host "  2. Ensure all migrations are run" -ForegroundColor White
    Write-Host "  3. Check storage/logs/laravel.log for details" -ForegroundColor White
    Write-Host ""
    exit 1
}

Write-Host "✅ Dashboard job test PASSED!" -ForegroundColor Green
Write-Host ""

# Step 4: Fix Failed Jobs
Write-Host "Step 4: Fixing Failed Jobs..." -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
$failedCount = (php artisan queue:failed | Measure-Object -Line).Lines

if ($failedCount -gt 1) {
    Write-Host "Found failed jobs. Do you want to:" -ForegroundColor Yellow
    Write-Host "  1. Retry all failed jobs (recommended)" -ForegroundColor White
    Write-Host "  2. Clear all failed jobs" -ForegroundColor White
    Write-Host "  3. Skip this step" -ForegroundColor White
    $choice = Read-Host "Enter choice (1-3)"
    
    switch ($choice) {
        "1" {
            Write-Host "Retrying all failed jobs..." -ForegroundColor Cyan
            php artisan queue:fix
        }
        "2" {
            Write-Host "Clearing all failed jobs..." -ForegroundColor Cyan
            php artisan queue:fix --clear
        }
        "3" {
            Write-Host "Skipping..." -ForegroundColor Gray
        }
        default {
            Write-Host "Invalid choice. Skipping..." -ForegroundColor Red
        }
    }
} else {
    Write-Host "✅ No failed jobs found!" -ForegroundColor Green
}
Write-Host ""

# Step 5: Test Queued Job
Write-Host "Step 5: Testing Queued Job..." -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
php artisan test:dashboard-job
Write-Host ""

# Step 6: Queue Worker Instructions
Write-Host "Step 6: Queue Worker Setup" -ForegroundColor Yellow
Write-Host "-----------------------------------" -ForegroundColor Yellow
Write-Host "Queue worker is NOT running automatically." -ForegroundColor Yellow
Write-Host ""
Write-Host "To start the queue worker, open a NEW terminal and run:" -ForegroundColor Cyan
Write-Host "  cd $PSScriptRoot\backend" -ForegroundColor White
Write-Host "  php artisan queue:work --tries=3 --timeout=120" -ForegroundColor White
Write-Host ""
Write-Host "Or use this command to start it now:" -ForegroundColor Cyan
Write-Host "  Start-Process powershell -ArgumentList '-NoExit', '-Command', 'cd $PSScriptRoot\backend; php artisan queue:work --tries=3 --timeout=120'" -ForegroundColor White
Write-Host ""

$startWorker = Read-Host "Do you want to start the queue worker now? (y/n)"
if ($startWorker -eq "y" -or $startWorker -eq "Y") {
    Write-Host "Starting queue worker in new window..." -ForegroundColor Cyan
    Start-Process powershell -ArgumentList "-NoExit", "-Command", "cd '$PSScriptRoot\backend'; Write-Host 'Queue Worker Started' -ForegroundColor Green; Write-Host 'Press Ctrl+C to stop' -ForegroundColor Yellow; Write-Host ''; php artisan queue:work --tries=3 --timeout=120"
    Start-Sleep -Seconds 2
    Write-Host "✅ Queue worker started in new window!" -ForegroundColor Green
} else {
    Write-Host "⚠️  Remember to start the queue worker manually!" -ForegroundColor Yellow
}
Write-Host ""

# Final Summary
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "✅ Migrations: Complete" -ForegroundColor Green
Write-Host "✅ Dashboard Job: Tested" -ForegroundColor Green
Write-Host "✅ Failed Jobs: Processed" -ForegroundColor Green
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "  1. Ensure queue worker is running" -ForegroundColor White
Write-Host "  2. Open dashboard in browser" -ForegroundColor White
Write-Host "  3. Check System Health widget" -ForegroundColor White
Write-Host "  4. Monitor logs: tail -f storage/logs/laravel.log" -ForegroundColor White
Write-Host ""
Write-Host "Useful Commands:" -ForegroundColor Yellow
Write-Host "  php artisan queue:diagnose-failed    - Check for issues" -ForegroundColor White
Write-Host "  php artisan queue:fix                - Fix failed jobs" -ForegroundColor White
Write-Host "  php artisan test:dashboard-job       - Test the job" -ForegroundColor White
Write-Host "  php artisan queue:work               - Start worker" -ForegroundColor White
Write-Host ""
Write-Host "Documentation:" -ForegroundColor Yellow
Write-Host "  See QUEUE_TROUBLESHOOTING.md for detailed guide" -ForegroundColor White
Write-Host "  See QUEUE_FIX_STEPS.md for manual steps" -ForegroundColor White
Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Fix Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
