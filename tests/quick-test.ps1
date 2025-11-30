# Quick Test Script for User Management Restructure
# Run this to quickly verify all routes are accessible

Write-Host "==================================" -ForegroundColor Cyan
Write-Host "User Management Quick Test Script" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""

# Check if dev server is running
Write-Host "Checking if dev server is running..." -ForegroundColor Yellow
$response = $null
try {
    $response = Invoke-WebRequest -Uri "http://localhost:3000" -Method Head -TimeoutSec 2 -ErrorAction SilentlyContinue
} catch {
    Write-Host "❌ Dev server is not running!" -ForegroundColor Red
    Write-Host "Please start the dev server first:" -ForegroundColor Yellow
    Write-Host "  cd frontend" -ForegroundColor White
    Write-Host "  npm run dev" -ForegroundColor White
    exit 1
}

Write-Host "✅ Dev server is running" -ForegroundColor Green
Write-Host ""

# Test routes
$routes = @(
    @{Name="Admin Users"; Path="/dashboard/users/all"},
    @{Name="Create Admin"; Path="/dashboard/users/create"},
    @{Name="Roles & Permissions"; Path="/dashboard/users/roles"},
    @{Name="PPPoE Users"; Path="/dashboard/pppoe/users"},
    @{Name="Hotspot Users"; Path="/dashboard/hotspot/users"},
    @{Name="Component Showcase"; Path="/component-showcase"}
)

Write-Host "Testing Routes:" -ForegroundColor Yellow
Write-Host "----------------" -ForegroundColor Yellow

foreach ($route in $routes) {
    $url = "http://localhost:3000$($route.Path)"
    Write-Host "Testing: $($route.Name)..." -NoNewline
    
    try {
        $response = Invoke-WebRequest -Uri $url -Method Head -TimeoutSec 5 -ErrorAction Stop
        if ($response.StatusCode -eq 200) {
            Write-Host " ✅ OK" -ForegroundColor Green
        } else {
            Write-Host " ⚠️  Status: $($response.StatusCode)" -ForegroundColor Yellow
        }
    } catch {
        Write-Host " ❌ FAILED" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "==================================" -ForegroundColor Cyan
Write-Host "Quick Test Complete!" -ForegroundColor Cyan
Write-Host "==================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next Steps:" -ForegroundColor Yellow
Write-Host "1. Open browser to http://localhost:3000" -ForegroundColor White
Write-Host "2. Login to the dashboard" -ForegroundColor White
Write-Host "3. Follow the manual test guide in tests/MANUAL_TEST_GUIDE.md" -ForegroundColor White
Write-Host ""
Write-Host "Quick Links:" -ForegroundColor Yellow
Write-Host "- Admin Users:    http://localhost:3000/dashboard/users/all" -ForegroundColor Cyan
Write-Host "- PPPoE Users:    http://localhost:3000/dashboard/pppoe/users" -ForegroundColor Cyan
Write-Host "- Hotspot Users:  http://localhost:3000/dashboard/hotspot/users" -ForegroundColor Cyan
Write-Host "- Component Demo: http://localhost:3000/component-showcase" -ForegroundColor Cyan
Write-Host ""
