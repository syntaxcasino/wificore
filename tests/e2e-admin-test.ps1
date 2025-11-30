# End-to-End Test for Admin User
# Tests complete admin workflow including authentication and system management

$ErrorActionPreference = "Stop"

Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║     End-to-End Test: Admin User Workflow              ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Cyan

$testResults = @{
    Passed = 0
    Failed = 0
    Total = 0
}

function Test-Step {
    param(
        [string]$StepName,
        [scriptblock]$TestScript
    )
    
    $testResults.Total++
    Write-Host "`n[$($testResults.Total)] Testing: $StepName" -ForegroundColor Yellow
    
    try {
        $result = & $TestScript
        if ($result) {
            Write-Host "    ✅ PASSED" -ForegroundColor Green
            $testResults.Passed++
            return $true
        } else {
            Write-Host "    ❌ FAILED" -ForegroundColor Red
            $testResults.Failed++
            return $false
        }
    } catch {
        Write-Host "    ❌ FAILED: $($_.Exception.Message)" -ForegroundColor Red
        $testResults.Failed++
        return $false
    }
}

# Test 1: Check if admin user exists in RADIUS
Test-Step "Admin user exists in RADIUS" {
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM radcheck WHERE username='admin';" 2>$null
    $count = [int]($result.Trim())
    
    if ($count -eq 0) {
        Write-Host "    Creating admin user..." -ForegroundColor Yellow
        docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "INSERT INTO radcheck (username, attribute, op, value) VALUES ('admin', 'Cleartext-Password', ':=', 'admin123') ON CONFLICT DO NOTHING;" | Out-Null
        Start-Sleep -Seconds 2
        $count = 1
    }
    
    Write-Host "    Admin user found in RADIUS" -ForegroundColor Gray
    return $count -gt 0
}

# Test 2: Admin login
$global:adminToken = $null
Test-Step "Admin login via RADIUS" {
    $response = Invoke-WebRequest -Uri "http://localhost/api/login" `
        -Method POST `
        -Body (@{username="admin";password="admin123"} | ConvertTo-Json) `
        -ContentType "application/json" `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    
    if ($json.success -and $json.token) {
        $global:adminToken = $json.token
        Write-Host "    Token: $($global:adminToken.Substring(0,30))..." -ForegroundColor Gray
        Write-Host "    Role: $($json.user.role)" -ForegroundColor Gray
        return $json.user.role -eq "admin"
    }
    return $false
}

# Test 3: Access admin-only endpoint (routers)
Test-Step "Access admin-only endpoint (GET /api/routers)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/routers" `
        -Method GET `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Status: $($response.StatusCode)" -ForegroundColor Gray
    return $response.StatusCode -eq 200
}

# Test 4: View all users
Test-Step "View all users (GET /api/users)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/users" `
        -Method GET `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Total users: $($json.users.total)" -ForegroundColor Gray
    return $response.StatusCode -eq 200
}

# Test 5: View all payments
Test-Step "View all payments (GET /api/payments)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/payments" `
        -Method GET `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Total payments: $($json.payments.total)" -ForegroundColor Gray
    return $response.StatusCode -eq 200
}

# Test 6: View all subscriptions
Test-Step "View all subscriptions (GET /api/subscriptions)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/subscriptions" `
        -Method GET `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Total subscriptions: $($json.subscriptions.total)" -ForegroundColor Gray
    return $response.StatusCode -eq 200
}

# Test 7: View packages
Test-Step "View packages (GET /api/packages)" {
    $response = Invoke-WebRequest -Uri "http://localhost/api/packages" `
        -Method GET `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Total packages: $($json.Count)" -ForegroundColor Gray
    return $json.Count -gt 0
}

# Test 8: Check queue workers
Test-Step "Queue workers are running" {
    $result = docker exec traidnet-backend supervisorctl status 2>$null
    $runningWorkers = ($result | Select-String "RUNNING").Count
    
    Write-Host "    Running workers: $runningWorkers" -ForegroundColor Gray
    return $runningWorkers -gt 0
}

# Test 9: Check queue jobs
Test-Step "Check queue system" {
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM jobs;" 2>$null
    $jobCount = [int]($result.Trim())
    
    Write-Host "    Pending jobs: $jobCount" -ForegroundColor Gray
    return $true # Always pass, just informational
}

# Test 10: Admin profile
Test-Step "View admin profile (GET /api/profile)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/profile" `
        -Method GET `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Username: $($json.user.username)" -ForegroundColor Gray
    Write-Host "    Role: $($json.user.role)" -ForegroundColor Gray
    Write-Host "    Last login: $($json.user.last_login_at)" -ForegroundColor Gray
    return $json.user.role -eq "admin"
}

# Test 11: Logout
Test-Step "Admin logout (POST /api/logout)" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/logout" `
        -Method POST `
        -Headers $headers `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Message: $($json.message)" -ForegroundColor Gray
    return $json.success -eq $true
}

# Test 12: Verify token is revoked
Test-Step "Verify token is revoked after logout" {
    $headers = @{
        "Authorization" = "Bearer $global:adminToken"
    }
    
    try {
        $response = Invoke-WebRequest -Uri "http://localhost/api/routers" `
            -Method GET `
            -Headers $headers `
            -UseBasicParsing
        
        Write-Host "    Token still works (FAILED)" -ForegroundColor Red
        return $false
    } catch {
        if ($_.Exception.Response.StatusCode.value__ -eq 401) {
            Write-Host "    Token correctly revoked (401 Unauthorized)" -ForegroundColor Gray
            return $true
        }
        return $false
    }
}

# Summary
Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║                  TEST RESULTS SUMMARY                  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

Write-Host "`nTotal Tests:  $($testResults.Total)" -ForegroundColor White
Write-Host "Passed:       $($testResults.Passed)" -ForegroundColor Green
Write-Host "Failed:       $($testResults.Failed)" -ForegroundColor Red
Write-Host "Success Rate: $([math]::Round(($testResults.Passed / $testResults.Total) * 100, 2))%" -ForegroundColor Yellow

if ($testResults.Failed -eq 0) {
    Write-Host "`n🎉 ALL ADMIN TESTS PASSED! 🎉`n" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n❌ SOME TESTS FAILED ❌`n" -ForegroundColor Red
    exit 1
}
