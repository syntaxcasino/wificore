# Login Issues Fix - Deployment Script
# This script applies the login fixes for system admin and tenant admin

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Login Issues Fix - Deployment" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Step 1: Clear caches
Write-Host "[1/4] Clearing application caches..." -ForegroundColor Yellow
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan route:clear
Write-Host "✅ Caches cleared" -ForegroundColor Green
Write-Host ""

# Step 2: Run migrations (if needed)
Write-Host "[2/4] Running migrations..." -ForegroundColor Yellow
docker exec traidnet-backend php artisan migrate --force
Write-Host "✅ Migrations completed" -ForegroundColor Green
Write-Host ""

# Step 3: Seed system admin
Write-Host "[3/4] Creating system admin..." -ForegroundColor Yellow
docker exec traidnet-backend php artisan db:seed --class=SystemAdminSeeder --force
Write-Host "✅ System admin created" -ForegroundColor Green
Write-Host ""

# Step 4: Verify setup
Write-Host "[4/4] Verifying setup..." -ForegroundColor Yellow

# Check if system admin exists
$checkAdmin = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM users WHERE role = 'system_admin';"
$adminCount = $checkAdmin.Trim()

if ($adminCount -gt 0) {
    Write-Host "✅ System admin exists ($adminCount found)" -ForegroundColor Green
} else {
    Write-Host "❌ System admin not found!" -ForegroundColor Red
    exit 1
}

# Check if radius_user_schema_mapping table exists
$checkTable = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM information_schema.tables WHERE table_name = 'radius_user_schema_mapping';"
$tableExists = $checkTable.Trim()

if ($tableExists -gt 0) {
    Write-Host "✅ radius_user_schema_mapping table exists" -ForegroundColor Green
} else {
    Write-Host "❌ radius_user_schema_mapping table not found!" -ForegroundColor Red
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Deployment Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Default System Admin Credentials:" -ForegroundColor Yellow
Write-Host "  Username: admin" -ForegroundColor White
Write-Host "  Password: Admin@123" -ForegroundColor White
Write-Host ""
Write-Host "⚠️  IMPORTANT: Change the password immediately!" -ForegroundColor Red
Write-Host ""
Write-Host "Test login:" -ForegroundColor Yellow
Write-Host '  curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '    -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '    -d ''{"username":"admin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""
Write-Host "For more details, see: LOGIN_ISSUES_FIX.md" -ForegroundColor Cyan
Write-Host ""
