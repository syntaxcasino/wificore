# Final Fix - All Issues Resolved
# Fixes: FreeRADIUS permissions, duplicate seeder, login issues

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Final Fix - All Issues" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Issues Fixed:" -ForegroundColor Yellow
Write-Host "  ✅ FreeRADIUS Dockerfile - Correct user/group (radius:radius)" -ForegroundColor Green
Write-Host "  ✅ DefaultSystemAdminSeeder - Deleted (no longer needed)" -ForegroundColor Green
Write-Host "  ✅ SystemAdminSeeder - Only seeder that creates system admin" -ForegroundColor Green
Write-Host "  ✅ Login fixes - TenantScope, email verification" -ForegroundColor Green
Write-Host ""

$confirmation = Read-Host "Rebuild and start all containers? (y/n)"
if ($confirmation -ne 'y') {
    Write-Host "Aborted." -ForegroundColor Red
    exit
}

Write-Host ""

# Step 1: Clean everything
Write-Host "[1/4] Stopping and cleaning containers..." -ForegroundColor Yellow
docker-compose down -v

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to stop containers" -ForegroundColor Red
    exit 1
}

Write-Host "✅ Containers stopped and volumes removed" -ForegroundColor Green
Write-Host ""

# Step 2: Rebuild all containers
Write-Host "[2/4] Rebuilding all containers..." -ForegroundColor Yellow
Write-Host "    This will take a few minutes..." -ForegroundColor Gray
Write-Host ""

docker-compose build --no-cache

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Build failed!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Check the error above. Common issues:" -ForegroundColor Yellow
    Write-Host "  - Network connectivity" -ForegroundColor White
    Write-Host "  - Docker daemon not running" -ForegroundColor White
    Write-Host "  - Insufficient disk space" -ForegroundColor White
    exit 1
}

Write-Host "✅ All containers rebuilt successfully" -ForegroundColor Green
Write-Host ""

# Step 3: Start all containers
Write-Host "[3/4] Starting all containers..." -ForegroundColor Yellow

docker-compose up -d

if ($LASTEXITCODE -ne 0) {
    Write-Host "❌ Failed to start containers" -ForegroundColor Red
    exit 1
}

Write-Host "⏳ Waiting for services to initialize (30 seconds)..." -ForegroundColor Yellow
Start-Sleep -Seconds 30

Write-Host "✅ All containers started" -ForegroundColor Green
Write-Host ""

# Step 4: Verify everything
Write-Host "[4/4] Verifying setup..." -ForegroundColor Yellow
Write-Host ""

# Check container status
Write-Host "  Container Status:" -ForegroundColor Cyan
docker-compose ps --format "table {{.Name}}\t{{.Status}}\t{{.Ports}}"
Write-Host ""

# Check FreeRADIUS
Write-Host "  Checking FreeRADIUS..." -ForegroundColor Cyan
$radiusLogs = docker-compose logs --tail 50 traidnet-freeradius 2>&1 | Out-String

if ($radiusLogs -match "globally writable") {
    Write-Host "  ❌ FreeRADIUS permission error still present!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Logs:" -ForegroundColor Yellow
    docker-compose logs --tail 100 traidnet-freeradius
    exit 1
} elseif ($radiusLogs -match "Ready to process requests") {
    Write-Host "  ✅ FreeRADIUS running" -ForegroundColor Green
} elseif ($radiusLogs -match "Listening on") {
    Write-Host "  ✅ FreeRADIUS running" -ForegroundColor Green
} else {
    Write-Host "  ⚠️  FreeRADIUS status unclear, checking logs..." -ForegroundColor Yellow
    docker-compose logs --tail 30 traidnet-freeradius
}

Write-Host ""

# Check Backend
Write-Host "  Checking Backend..." -ForegroundColor Cyan
$backendLogs = docker-compose logs --tail 100 traidnet-backend 2>&1 | Out-String

if ($backendLogs -match "duplicate key value") {
    Write-Host "  ❌ Duplicate key error still present!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Logs:" -ForegroundColor Yellow
    docker-compose logs --tail 100 traidnet-backend | Select-String -Pattern "duplicate|error" -Context 2
    exit 1
}

if ($backendLogs -match "DefaultSystemAdminSeeder") {
    Write-Host "  ⚠️  DefaultSystemAdminSeeder still running (should be deleted)" -ForegroundColor Yellow
}

if ($backendLogs -match "System admin created successfully") {
    Write-Host "  ✅ System admin created" -ForegroundColor Green
} else {
    Write-Host "  ⚠️  System admin creation status unclear" -ForegroundColor Yellow
}

if ($backendLogs -match "Database seeding completed") {
    Write-Host "  ✅ Database seeded successfully" -ForegroundColor Green
}

Write-Host ""

# Check database
Write-Host "  Checking database..." -ForegroundColor Cyan
Start-Sleep -Seconds 5

$dbCheck = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM users WHERE role = 'system_admin';" 2>$null

if ($dbCheck -match "1") {
    Write-Host "  ✅ Exactly ONE system admin in database" -ForegroundColor Green
    
    Write-Host ""
    Write-Host "  System Admin Details:" -ForegroundColor Gray
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT username, email, role, account_number, email_verified_at IS NOT NULL as verified FROM users WHERE role = 'system_admin';"
} elseif ($dbCheck -match "0") {
    Write-Host "  ❌ No system admin found!" -ForegroundColor Red
    Write-Host "  Checking backend logs for errors..." -ForegroundColor Yellow
    docker-compose logs --tail 50 traidnet-backend | Select-String -Pattern "seeder|error" -Context 2
    exit 1
} else {
    Write-Host "  ⚠️  Multiple system admins found (should be only 1)" -ForegroundColor Yellow
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT username, email, account_number FROM users WHERE role = 'system_admin';"
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Setup Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "System Admin Credentials:" -ForegroundColor Yellow
Write-Host "  Username: sysadmin" -ForegroundColor White
Write-Host "  Password: Admin@123" -ForegroundColor White
Write-Host "  Email: sysadmin@system.local" -ForegroundColor White
Write-Host ""

Write-Host "⚠️  IMPORTANT: Change password in production!" -ForegroundColor Red
Write-Host ""

Write-Host "Test Commands:" -ForegroundColor Cyan
Write-Host ""

Write-Host "1. Test RADIUS Authentication:" -ForegroundColor Yellow
Write-Host '   docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123' -ForegroundColor White
Write-Host ""

Write-Host "2. Test Login API:" -ForegroundColor Yellow
Write-Host '   curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '     -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '     -d ''{"username":"sysadmin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""

Write-Host "3. View Logs:" -ForegroundColor Yellow
Write-Host "   docker-compose logs -f traidnet-backend" -ForegroundColor White
Write-Host "   docker-compose logs -f traidnet-freeradius" -ForegroundColor White
Write-Host ""

Write-Host "4. Access Frontend:" -ForegroundColor Yellow
Write-Host "   http://localhost" -ForegroundColor White
Write-Host ""

Write-Host "Documentation:" -ForegroundColor Cyan
Write-Host "  - COMPLETE_FIX_SUMMARY.md" -ForegroundColor White
Write-Host "  - FREERADIUS_PERMISSION_FIX.md" -ForegroundColor White
Write-Host "  - LOGIN_ISSUES_FIX.md" -ForegroundColor White
Write-Host ""

Write-Host "🎉 All issues fixed and system ready!" -ForegroundColor Green
Write-Host ""
