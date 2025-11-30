# Test Dashboard API Endpoints
# This script tests all dashboard-related API endpoints

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Dashboard API Endpoint Tests" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

$baseUrl = "http://localhost/api"

# Test endpoints
$endpoints = @(
    @{
        Name = "System Metrics (Performance)"
        Url = "$baseUrl/system/metrics"
        Description = "TPS, OPS, DB stats, Response time"
    },
    @{
        Name = "Queue Statistics"
        Url = "$baseUrl/system/queue/stats"
        Description = "Pending, processing, failed jobs, workers"
    },
    @{
        Name = "System Health"
        Url = "$baseUrl/system/health"
        Description = "Database, Redis, Queue, Disk, Uptime"
    },
    @{
        Name = "Dashboard Stats"
        Url = "$baseUrl/system/dashboard/stats"
        Description = "Tenant counts, users, routers"
    }
)

$successCount = 0
$failCount = 0

foreach ($endpoint in $endpoints) {
    Write-Host "Testing: $($endpoint.Name)" -ForegroundColor Yellow
    Write-Host "  URL: $($endpoint.Url)" -ForegroundColor Gray
    Write-Host "  Description: $($endpoint.Description)" -ForegroundColor Gray
    
    try {
        $response = Invoke-WebRequest -Uri $endpoint.Url -Method GET -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
        
        if ($response.StatusCode -eq 200) {
            Write-Host "  [OK] Status: $($response.StatusCode)" -ForegroundColor Green
            
            # Try to parse JSON
            try {
                $json = $response.Content | ConvertFrom-Json
                Write-Host "  [OK] Valid JSON response" -ForegroundColor Green
                
                # Show sample data
                $preview = $response.Content.Substring(0, [Math]::Min(150, $response.Content.Length))
                Write-Host "  Preview: $preview..." -ForegroundColor DarkGray
            } catch {
                Write-Host "  [WARN] Response is not valid JSON" -ForegroundColor Yellow
            }
            
            $successCount++
        } else {
            Write-Host "  [FAIL] Status: $($response.StatusCode)" -ForegroundColor Red
            $failCount++
        }
    } catch {
        Write-Host "  [FAIL] Error: $($_.Exception.Message)" -ForegroundColor Red
        $failCount++
    }
    
    Write-Host ""
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Test Summary" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Total Endpoints Tested: $($endpoints.Count)" -ForegroundColor White
Write-Host "Successful: $successCount" -ForegroundColor Green
Write-Host "Failed: $failCount" -ForegroundColor $(if ($failCount -gt 0) { "Red" } else { "Green" })
Write-Host ""

if ($failCount -eq 0) {
    Write-Host "[SUCCESS] All API endpoints are working correctly!" -ForegroundColor Green
    Write-Host ""
    Write-Host "Next Steps:" -ForegroundColor White
    Write-Host "1. Open browser: http://localhost" -ForegroundColor Gray
    Write-Host "2. Login with system admin credentials" -ForegroundColor Gray
    Write-Host "3. Navigate to System Dashboard" -ForegroundColor Gray
    Write-Host "4. Verify all widgets display real data" -ForegroundColor Gray
} else {
    Write-Host "[WARNING] Some endpoints failed!" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Troubleshooting:" -ForegroundColor White
    Write-Host "1. Check if Docker containers are running: docker ps" -ForegroundColor Gray
    Write-Host "2. Check backend logs: docker exec traidnet-backend tail -f storage/logs/laravel.log" -ForegroundColor Gray
    Write-Host "3. Verify metrics are being collected: powershell -ExecutionPolicy Bypass -File fix-dashboard-docker.ps1" -ForegroundColor Gray
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
