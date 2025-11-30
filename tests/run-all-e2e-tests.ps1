# Master E2E Test Runner
# Runs all end-to-end tests for the WiFi Hotspot Management System

$ErrorActionPreference = "Stop"

Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Magenta
Write-Host "║   WiFi Hotspot Management System - E2E Test Suite     ║" -ForegroundColor Magenta
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Magenta

$overallResults = @{
    AdminPassed = $false
    HotspotPassed = $false
    StartTime = Get-Date
}

# Pre-flight checks
Write-Host "═══ PRE-FLIGHT CHECKS ═══`n" -ForegroundColor Cyan

Write-Host "[1/5] Checking Docker containers..." -ForegroundColor Yellow
$containers = docker ps --format "{{.Names}}" 2>$null
$requiredContainers = @("traidnet-backend", "traidnet-postgres", "traidnet-freeradius", "traidnet-nginx")

foreach ($container in $requiredContainers) {
    if ($containers -contains $container) {
        Write-Host "  [OK] $container is running" -ForegroundColor Green
    } else {
        Write-Host "  [FAIL] $container is NOT running" -ForegroundColor Red
        Write-Host "`nPlease start all containers with: docker-compose up -d" -ForegroundColor Yellow
        exit 1
    }
}

Write-Host "`n[2/5] Checking database connectivity..." -ForegroundColor Yellow
try {
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT 1;" 2>$null | Out-Null
    Write-Host "  [OK] Database is accessible" -ForegroundColor Green
} catch {
    Write-Host "  [FAIL] Database is not accessible" -ForegroundColor Red
    exit 1
}

Write-Host "`n[3/5] Checking API endpoint..." -ForegroundColor Yellow
try {
    $apiTest = Invoke-WebRequest -Uri "http://localhost/api/packages" -Method GET -UseBasicParsing -TimeoutSec 5
    Write-Host "  [OK] API is responding (Status: $($apiTest.StatusCode))" -ForegroundColor Green
} catch {
    Write-Host "  [FAIL] API is not responding" -ForegroundColor Red
    Write-Host "  Error: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

Write-Host "`n[4/5] Checking queue workers..." -ForegroundColor Yellow
try {
    $workers = docker exec traidnet-backend supervisorctl status 2>$null
    $runningWorkers = ($workers | Select-String "RUNNING").Count
    
    if ($runningWorkers -gt 0) {
        Write-Host "  [OK] Queue workers are running ($runningWorkers workers)" -ForegroundColor Green
    } else {
        Write-Host "  [WARN] No queue workers running (tests may be slower)" -ForegroundColor Yellow
    }
} catch {
    Write-Host "  [WARN] Could not check queue workers" -ForegroundColor Yellow
}

Write-Host "`n[5/5] Checking required tables..." -ForegroundColor Yellow
$tables = @("users", "packages", "payments", "user_subscriptions", "radcheck", "personal_access_tokens")
$missingTables = @()

foreach ($table in $tables) {
    $query = "SELECT COUNT(*) FROM $table LIMIT 1;"
    try {
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $query 2>$null | Out-Null
        Write-Host "  [OK] Table '$table' exists" -ForegroundColor Green
    } catch {
        Write-Host "  [FAIL] Table '$table' is missing" -ForegroundColor Red
        $missingTables += $table
    }
}

if ($missingTables.Count -gt 0) {
    Write-Host "`nMissing tables detected. Please run database migrations." -ForegroundColor Red
    exit 1
}

Write-Host "`n[OK] All pre-flight checks passed!`n" -ForegroundColor Green
Start-Sleep -Seconds 2

# Run Admin Tests
Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║              RUNNING ADMIN USER TESTS                  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Cyan

try {
    & "$PSScriptRoot\e2e-admin-test.ps1"
    if ($LASTEXITCODE -eq 0) {
        $overallResults.AdminPassed = $true
        Write-Host "`n[OK] Admin tests completed successfully`n" -ForegroundColor Green
    } else {
        Write-Host "`n[FAIL] Admin tests failed`n" -ForegroundColor Red
    }
} catch {
    Write-Host "`n[FAIL] Admin tests encountered an error: $($_.Exception.Message)`n" -ForegroundColor Red
}

Start-Sleep -Seconds 3

# Run Hotspot User Tests
Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║           RUNNING HOTSPOT USER TESTS                   ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Cyan

try {
    & "$PSScriptRoot\e2e-hotspot-user-test.ps1"
    if ($LASTEXITCODE -eq 0) {
        $overallResults.HotspotPassed = $true
        Write-Host "`n[OK] Hotspot user tests completed successfully`n" -ForegroundColor Green
    } else {
        Write-Host "`n[FAIL] Hotspot user tests failed`n" -ForegroundColor Red
    }
} catch {
    Write-Host "`n[FAIL] Hotspot user tests encountered an error: $($_.Exception.Message)`n" -ForegroundColor Red
}

# Final Summary
$overallResults.EndTime = Get-Date
$duration = $overallResults.EndTime - $overallResults.StartTime

Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Magenta
Write-Host "║              OVERALL TEST RESULTS                      ║" -ForegroundColor Magenta
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Magenta

Write-Host "Test Suite Results:" -ForegroundColor Cyan
Write-Host "  Admin User Tests:     $(if ($overallResults.AdminPassed) { '[PASSED]' } else { '[FAILED]' })" -ForegroundColor $(if ($overallResults.AdminPassed) { 'Green' } else { 'Red' })
Write-Host "  Hotspot User Tests:   $(if ($overallResults.HotspotPassed) { '[PASSED]' } else { '[FAILED]' })" -ForegroundColor $(if ($overallResults.HotspotPassed) { 'Green' } else { 'Red' })

Write-Host "`nExecution Time: $($duration.TotalSeconds) seconds" -ForegroundColor Gray

if ($overallResults.AdminPassed -and $overallResults.HotspotPassed) {
    Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Green
    Write-Host "║                                                        ║" -ForegroundColor Green
    Write-Host "║           ALL TESTS PASSED!                            ║" -ForegroundColor Green
    Write-Host "║                                                        ║" -ForegroundColor Green
    Write-Host "║     System is ready for production deployment!        ║" -ForegroundColor Green
    Write-Host "║                                                        ║" -ForegroundColor Green
    Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Red
    Write-Host "║                                                        ║" -ForegroundColor Red
    Write-Host "║              SOME TESTS FAILED                         ║" -ForegroundColor Red
    Write-Host "║                                                        ║" -ForegroundColor Red
    Write-Host "║     Please review the errors above and fix issues     ║" -ForegroundColor Red
    Write-Host "║                                                        ║" -ForegroundColor Red
    Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Red
    
    Write-Host "Troubleshooting Tips:" -ForegroundColor Yellow
    Write-Host "  1. Check Docker logs: docker-compose logs" -ForegroundColor White
    Write-Host "  2. Check Laravel logs: docker exec traidnet-backend tail -100 /var/www/html/storage/logs/laravel.log" -ForegroundColor White
    Write-Host "  3. Check queue logs: docker exec traidnet-backend tail -100 /var/www/html/storage/logs/payments-queue.log" -ForegroundColor White
    Write-Host "  4. Restart services: docker-compose restart" -ForegroundColor White
    Write-Host "  5. Review documentation: docs/TROUBLESHOOTING_GUIDE.md`n" -ForegroundColor White
    
    exit 1
}
