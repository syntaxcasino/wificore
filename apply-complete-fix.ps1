# Complete Fix Script - Login Issues & Seeder Duplicates
# This script applies all fixes and cleans up the database

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Complete Fix Application" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "This script will:" -ForegroundColor Yellow
Write-Host "  1. Stop the backend container" -ForegroundColor White
Write-Host "  2. Clean up duplicate system admins in database" -ForegroundColor White
Write-Host "  3. Restart backend with fixed code" -ForegroundColor White
Write-Host "  4. Verify system admin creation" -ForegroundColor White
Write-Host ""

$confirmation = Read-Host "Continue? (y/n)"
if ($confirmation -ne 'y') {
    Write-Host "Aborted." -ForegroundColor Red
    exit
}

Write-Host ""

# Step 1: Stop backend
Write-Host "[1/4] Stopping backend container..." -ForegroundColor Yellow
docker-compose stop traidnet-backend
Write-Host "✅ Backend stopped" -ForegroundColor Green
Write-Host ""

# Step 2: Clean up database
Write-Host "[2/4] Cleaning up duplicate system admins..." -ForegroundColor Yellow

# Delete all system admins
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radius_user_schema_mapping WHERE user_role = 'system_admin';" 2>$null
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM radcheck WHERE username IN ('admin', 'sysadmin');" 2>$null
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM users WHERE role = 'system_admin';" 2>$null

Write-Host "✅ Database cleaned" -ForegroundColor Green
Write-Host ""

# Step 3: Restart backend
Write-Host "[3/4] Starting backend container..." -ForegroundColor Yellow
Write-Host "    (This will run migrations and seeders with fixed code)" -ForegroundColor Gray
docker-compose up -d traidnet-backend

Write-Host "⏳ Waiting for backend to initialize..." -ForegroundColor Yellow
Start-Sleep -Seconds 15
Write-Host "✅ Backend started" -ForegroundColor Green
Write-Host ""

# Step 4: Verify
Write-Host "[4/4] Verifying system admin creation..." -ForegroundColor Yellow

# Wait a bit more for seeding to complete
Start-Sleep -Seconds 5

# Check system admin
$result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT username, email, role FROM users WHERE role = 'system_admin';" 2>$null

if ($result -match "sysadmin") {
    Write-Host "✅ System admin created successfully!" -ForegroundColor Green
    Write-Host ""
    Write-Host "System Admin Details:" -ForegroundColor Cyan
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT username, email, role, account_number, email_verified_at IS NOT NULL as verified FROM users WHERE role = 'system_admin';"
} else {
    Write-Host "❌ System admin not found!" -ForegroundColor Red
    Write-Host "Check logs:" -ForegroundColor Yellow
    docker-compose logs --tail 50 traidnet-backend
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Fix Applied Successfully!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "System Admin (Landlord) Credentials:" -ForegroundColor Yellow
Write-Host "  Username: sysadmin" -ForegroundColor White
Write-Host "  Password: Admin@123" -ForegroundColor White
Write-Host "  Email: sysadmin@system.local" -ForegroundColor White
Write-Host ""

Write-Host "⚠️  IMPORTANT: Change the password immediately in production!" -ForegroundColor Red
Write-Host ""

Write-Host "Test Login:" -ForegroundColor Yellow
Write-Host '  curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '    -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '    -d ''{"username":"sysadmin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""

Write-Host "View Backend Logs:" -ForegroundColor Yellow
Write-Host "  docker-compose logs -f traidnet-backend" -ForegroundColor White
Write-Host ""

Write-Host "Documentation:" -ForegroundColor Cyan
Write-Host "  - LOGIN_ISSUES_FIX.md" -ForegroundColor White
Write-Host "  - SEEDER_DUPLICATE_FIX.md" -ForegroundColor White
Write-Host ""
