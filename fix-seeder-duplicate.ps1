# Fix Seeder Duplicate Issue
# This script fixes the duplicate system admin account_number issue

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Fix Seeder Duplicate Issue" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "This will:" -ForegroundColor Yellow
Write-Host "  1. Clear application caches" -ForegroundColor White
Write-Host "  2. Restart the backend container" -ForegroundColor White
Write-Host ""

# Step 1: Clear caches
Write-Host "[1/2] Clearing application caches..." -ForegroundColor Yellow
docker exec traidnet-backend php artisan config:clear 2>$null
docker exec traidnet-backend php artisan cache:clear 2>$null
docker exec traidnet-backend php artisan route:clear 2>$null
Write-Host "✅ Caches cleared" -ForegroundColor Green
Write-Host ""

# Step 2: Restart backend to trigger auto-seed with fixed seeder
Write-Host "[2/2] Restarting backend container..." -ForegroundColor Yellow
Write-Host "    (This will re-run migrations and seeders with the fix)" -ForegroundColor Gray
docker-compose restart traidnet-backend
Write-Host "✅ Backend restarted" -ForegroundColor Green
Write-Host ""

# Wait for backend to be ready
Write-Host "⏳ Waiting for backend to be ready..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# Check logs
Write-Host ""
Write-Host "📋 Checking backend logs..." -ForegroundColor Yellow
Write-Host "========================================" -ForegroundColor Gray
docker-compose logs --tail 50 traidnet-backend | Select-String -Pattern "System admin|seeding|DONE|ERROR" -Context 0,0
Write-Host "========================================" -ForegroundColor Gray
Write-Host ""

Write-Host "✅ Fix applied!" -ForegroundColor Green
Write-Host ""
Write-Host "System Admin Credentials:" -ForegroundColor Yellow
Write-Host "  Username: admin" -ForegroundColor White
Write-Host "  Password: Admin@123" -ForegroundColor White
Write-Host ""
Write-Host "Test login:" -ForegroundColor Yellow
Write-Host '  curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '    -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '    -d ''{"username":"admin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""
