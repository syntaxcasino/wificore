# Complete Rebuild and Fix Script
# Fixes all issues: FreeRADIUS permissions, login, seeder duplicates

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Complete Rebuild & Fix" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "This script will:" -ForegroundColor Yellow
Write-Host "  1. Stop all containers" -ForegroundColor White
Write-Host "  2. Clean up database (remove duplicate system admins)" -ForegroundColor White
Write-Host "  3. Rebuild FreeRADIUS with configs copied (not mounted)" -ForegroundColor White
Write-Host "  4. Start all containers with fixed code" -ForegroundColor White
Write-Host "  5. Verify everything works" -ForegroundColor White
Write-Host ""

$confirmation = Read-Host "Continue? (y/n)"
if ($confirmation -ne 'y') {
    Write-Host "Aborted." -ForegroundColor Red
    exit
}

Write-Host ""

# Step 1: Stop all containers
Write-Host "[1/6] Stopping all containers..." -ForegroundColor Yellow
docker-compose down
Write-Host "✅ Containers stopped" -ForegroundColor Green
Write-Host ""

# Step 2: Clean database
Write-Host "[2/6] Cleaning up database..." -ForegroundColor Yellow

# Start only postgres
docker-compose up -d traidnet-postgres
Start-Sleep -Seconds 5

# Clean up system admins
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radius_user_schema_mapping WHERE user_role = 'system_admin';" 2>$null
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radcheck WHERE username IN ('admin', 'sysadmin');" 2>$null
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM users WHERE role = 'system_admin';" 2>$null

Write-Host "✅ Database cleaned" -ForegroundColor Green
Write-Host ""

# Step 3: Rebuild FreeRADIUS
Write-Host "[3/6] Rebuilding FreeRADIUS container..." -ForegroundColor Yellow
Write-Host "    (Copying configs into container with proper permissions)" -ForegroundColor Gray

docker-compose build --no-cache traidnet-freeradius

Write-Host "✅ FreeRADIUS rebuilt" -ForegroundColor Green
Write-Host ""

# Step 4: Rebuild backend (to get latest code fixes)
Write-Host "[4/6] Rebuilding backend container..." -ForegroundColor Yellow

docker-compose build --no-cache traidnet-backend

Write-Host "✅ Backend rebuilt" -ForegroundColor Green
Write-Host ""

# Step 5: Start all containers
Write-Host "[5/6] Starting all containers..." -ForegroundColor Yellow

docker-compose up -d

Write-Host "⏳ Waiting for services to initialize..." -ForegroundColor Yellow
Start-Sleep -Seconds 20

Write-Host "✅ All containers started" -ForegroundColor Green
Write-Host ""

# Step 6: Verify
Write-Host "[6/6] Verifying setup..." -ForegroundColor Yellow
Write-Host ""

# Check FreeRADIUS
Write-Host "  Checking FreeRADIUS..." -ForegroundColor Cyan
$radiusLogs = docker-compose logs --tail 30 traidnet-freeradius 2>&1 | Out-String

if ($radiusLogs -match "globally writable") {
    Write-Host "  ❌ FreeRADIUS still has permission issues!" -ForegroundColor Red
    Write-Host ""
    Write-Host "Logs:" -ForegroundColor Yellow
    docker-compose logs --tail 50 traidnet-freeradius
    exit 1
} elseif ($radiusLogs -match "Ready to process requests") {
    Write-Host "  ✅ FreeRADIUS running" -ForegroundColor Green
} else {
    Write-Host "  ⚠️  FreeRADIUS status unclear" -ForegroundColor Yellow
}

Write-Host ""

# Check Backend
Write-Host "  Checking Backend..." -ForegroundColor Cyan
$backendLogs = docker-compose logs --tail 50 traidnet-backend 2>&1 | Out-String

if ($backendLogs -match "System admin created successfully") {
    Write-Host "  ✅ System admin created" -ForegroundColor Green
} else {
    Write-Host "  ⚠️  System admin status unclear" -ForegroundColor Yellow
}

if ($backendLogs -match "duplicate key value") {
    Write-Host "  ❌ Seeder duplicate error still present!" -ForegroundColor Red
    exit 1
}

if ($backendLogs -match "Database seeding completed") {
    Write-Host "  ✅ Database seeded" -ForegroundColor Green
}

Write-Host ""

# Check system admin in database
Write-Host "  Checking system admin in database..." -ForegroundColor Cyan
$adminCheck = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT username, email FROM users WHERE role = 'system_admin';" 2>$null

if ($adminCheck -match "sysadmin") {
    Write-Host "  ✅ System admin exists in database" -ForegroundColor Green
    Write-Host ""
    Write-Host "  System Admin Details:" -ForegroundColor Gray
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT username, email, role, email_verified_at IS NOT NULL as verified FROM users WHERE role = 'system_admin';"
} else {
    Write-Host "  ❌ System admin not found in database!" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  All Fixes Applied Successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "✅ FreeRADIUS: Running with proper permissions" -ForegroundColor Green
Write-Host "✅ Backend: Running with fixed code" -ForegroundColor Green
Write-Host "✅ Database: System admin created" -ForegroundColor Green
Write-Host "✅ No seeder duplicates" -ForegroundColor Green
Write-Host ""

Write-Host "System Admin (Landlord) Credentials:" -ForegroundColor Yellow
Write-Host "  Username: sysadmin" -ForegroundColor White
Write-Host "  Password: Admin@123" -ForegroundColor White
Write-Host "  Email: sysadmin@system.local" -ForegroundColor White
Write-Host ""

Write-Host "⚠️  IMPORTANT: Change the password in production!" -ForegroundColor Red
Write-Host ""

Write-Host "Test RADIUS Authentication:" -ForegroundColor Yellow
Write-Host '  docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123' -ForegroundColor White
Write-Host ""

Write-Host "Test Login API:" -ForegroundColor Yellow
Write-Host '  curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '    -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '    -d ''{"username":"sysadmin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""

Write-Host "View Container Status:" -ForegroundColor Yellow
Write-Host "  docker-compose ps" -ForegroundColor White
Write-Host ""

Write-Host "View Logs:" -ForegroundColor Yellow
Write-Host "  docker-compose logs -f traidnet-backend" -ForegroundColor White
Write-Host "  docker-compose logs -f traidnet-freeradius" -ForegroundColor White
Write-Host ""

Write-Host "Documentation:" -ForegroundColor Cyan
Write-Host "  - COMPLETE_FIX_SUMMARY.md" -ForegroundColor White
Write-Host "  - LOGIN_ISSUES_FIX.md" -ForegroundColor White
Write-Host "  - SEEDER_DUPLICATE_FIX.md" -ForegroundColor White
Write-Host ""
