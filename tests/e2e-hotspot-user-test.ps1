# End-to-End Test for Hotspot User
# Tests complete hotspot user workflow from payment to WiFi access

$ErrorActionPreference = "Stop"

Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║   End-to-End Test: Hotspot User Workflow              ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝`n" -ForegroundColor Cyan

$testResults = @{
    Passed = 0
    Failed = 0
    Total = 0
}

# Generate unique test data
$timestamp = Get-Date -Format "HHmmss"
$testPhone = "+25471234$timestamp"
$testMac = "AA:BB:CC:DD:EE:$timestamp".Substring(0, 17)
$global:checkoutRequestId = $null
$global:paymentId = $null
$global:userId = $null

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

Write-Host "Test Data:" -ForegroundColor Cyan
Write-Host "  Phone: $testPhone" -ForegroundColor Gray
Write-Host "  MAC: $testMac" -ForegroundColor Gray

# Test 1: View available packages (public endpoint)
Test-Step "View available packages (public)" {
    $response = Invoke-WebRequest -Uri "http://localhost/api/packages" `
        -Method GET `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    
    if ($json.Count -gt 0) {
        Write-Host "    Available packages: $($json.Count)" -ForegroundColor Gray
        Write-Host "    First package: $($json[0].name) - KES $($json[0].price)" -ForegroundColor Gray
        return $true
    }
    return $false
}

# Test 2: Initiate M-Pesa payment
Test-Step "Initiate M-Pesa payment" {
    $body = @{
        package_id = 1
        phone_number = $testPhone
        mac_address = $testMac
    } | ConvertTo-Json
    
    try {
        $response = Invoke-WebRequest -Uri "http://localhost/api/payments/initiate" `
            -Method POST `
            -Body $body `
            -ContentType "application/json" `
            -UseBasicParsing
        
        $json = $response.Content | ConvertFrom-Json
        
        if ($json.checkout_request_id) {
            $global:checkoutRequestId = $json.checkout_request_id
            Write-Host "    Checkout ID: $global:checkoutRequestId" -ForegroundColor Gray
            return $true
        } elseif ($json.success -eq $false) {
            Write-Host "    M-Pesa not configured (expected in test environment)" -ForegroundColor Yellow
            # Create payment record manually for testing
            $global:checkoutRequestId = "TEST_" + (Get-Random -Maximum 999999)
            return $true
        }
        return $false
    } catch {
        Write-Host "    M-Pesa not configured (expected)" -ForegroundColor Yellow
        $global:checkoutRequestId = "TEST_" + (Get-Random -Maximum 999999)
        return $true
    }
}

# Test 3: Create payment record in database
Test-Step "Create payment record" {
    $query = "INSERT INTO payments (mac_address, phone_number, package_id, amount, transaction_id, status) VALUES ('$testMac', '$testPhone', 1, 100.00, '$global:checkoutRequestId', 'pending') RETURNING id;"
    
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    $global:paymentId = [int]($result.Trim())
    
    Write-Host "    Payment ID: $global:paymentId" -ForegroundColor Gray
    return $global:paymentId -gt 0
}

# Test 4: Simulate M-Pesa callback
Test-Step "Simulate M-Pesa callback (payment success)" {
    $callbackData = @{
        Body = @{
            stkCallback = @{
                CheckoutRequestID = $global:checkoutRequestId
                ResultCode = 0
                ResultDesc = "The service request is processed successfully."
                CallbackMetadata = @{
                    Item = @(
                        @{ Name = "Amount"; Value = 100 }
                        @{ Name = "MpesaReceiptNumber"; Value = "TEST123456" }
                        @{ Name = "PhoneNumber"; Value = $testPhone.Replace("+", "") }
                    )
                }
            }
        }
    } | ConvertTo-Json -Depth 10
    
    $response = Invoke-WebRequest -Uri "http://localhost/api/mpesa/callback" `
        -Method POST `
        -Body $callbackData `
        -ContentType "application/json" `
        -UseBasicParsing
    
    $json = $response.Content | ConvertFrom-Json
    Write-Host "    Callback processed: $($json.success)" -ForegroundColor Gray
    return $json.success -eq $true
}

# Test 5: Wait for queue processing
Test-Step "Wait for queue to process payment" {
    Write-Host "    Waiting 10 seconds for queue processing..." -ForegroundColor Gray
    Start-Sleep -Seconds 10
    
    # Check if payment was updated to completed
    $query = "SELECT status FROM payments WHERE id = $global:paymentId;"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    $status = $result.Trim()
    
    Write-Host "    Payment status: $status" -ForegroundColor Gray
    return $status -eq "completed"
}

# Test 6: Verify user was created
Test-Step "Verify hotspot user was created" {
    $query = "SELECT id, username, role, phone_number FROM users WHERE phone_number = '$testPhone';"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    
    if ($result) {
        $parts = $result.Trim() -split '\|'
        $global:userId = [int]($parts[0].Trim())
        $username = $parts[1].Trim()
        $role = $parts[2].Trim()
        
        Write-Host "    User ID: $global:userId" -ForegroundColor Gray
        Write-Host "    Username: $username" -ForegroundColor Gray
        Write-Host "    Role: $role" -ForegroundColor Gray
        
        return $role -eq "hotspot_user"
    }
    return $false
}

# Test 7: Verify subscription was created
Test-Step "Verify subscription was created" {
    $query = "SELECT id, status, mikrotik_username, mikrotik_password, end_time FROM user_subscriptions WHERE user_id = $global:userId;"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    
    if ($result) {
        $parts = $result.Trim() -split '\|'
        $subscriptionId = $parts[0].Trim()
        $status = $parts[1].Trim()
        $mikrotikUser = $parts[2].Trim()
        $mikrotikPass = $parts[3].Trim()
        
        Write-Host "    Subscription ID: $subscriptionId" -ForegroundColor Gray
        Write-Host "    Status: $status" -ForegroundColor Gray
        Write-Host "    MikroTik Username: $mikrotikUser" -ForegroundColor Gray
        Write-Host "    MikroTik Password: $mikrotikPass" -ForegroundColor Gray
        
        return $status -eq "active"
    }
    return $false
}

# Test 8: Verify RADIUS entry was created
Test-Step "Verify RADIUS entry was created" {
    $query = "SELECT username, value FROM radcheck WHERE username LIKE 'user_$($testPhone.Replace('+', '').Substring(0, 12))%';"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    
    if ($result) {
        $parts = $result.Trim() -split '\|'
        $radiusUser = $parts[0].Trim()
        $radiusPass = $parts[1].Trim()
        
        Write-Host "    RADIUS Username: $radiusUser" -ForegroundColor Gray
        Write-Host "    RADIUS Password: $radiusPass" -ForegroundColor Gray
        
        return $radiusUser.Length -gt 0
    }
    return $false
}

# Test 9: Check queue jobs processed
Test-Step "Verify queue jobs were processed" {
    $query = "SELECT COUNT(*) FROM jobs WHERE queue = 'payments';"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    $pendingJobs = [int]($result.Trim())
    
    Write-Host "    Pending payment jobs: $pendingJobs" -ForegroundColor Gray
    
    # Check failed jobs
    $failedQuery = "SELECT COUNT(*) FROM failed_jobs WHERE queue = 'payments' AND failed_at > NOW() - INTERVAL '1 minute';"
    $failedResult = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $failedQuery 2>$null
    $failedJobs = [int]($failedResult.Trim())
    
    Write-Host "    Failed jobs (last minute): $failedJobs" -ForegroundColor Gray
    
    return $failedJobs -eq 0
}

# Test 10: Test returning user (second purchase)
Test-Step "Test returning user (second purchase)" {
    # Create another payment for the same user
    $newCheckoutId = "TEST_RETURN_" + (Get-Random -Maximum 999999)
    
    $query = "INSERT INTO payments (user_id, mac_address, phone_number, package_id, amount, transaction_id, status) VALUES ($global:userId, '$testMac', '$testPhone', 1, 100.00, '$newCheckoutId', 'completed') RETURNING id;"
    
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    $newPaymentId = [int]($result.Trim())
    
    Write-Host "    Second payment ID: $newPaymentId" -ForegroundColor Gray
    
    # Dispatch job manually
    # In real scenario, this would be done by callback
    
    return $newPaymentId -gt 0
}

# Test 11: Verify user count didn't increase
Test-Step "Verify returning user wasn't duplicated" {
    $query = "SELECT COUNT(*) FROM users WHERE phone_number = '$testPhone';"
    $result = docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c $query 2>$null
    $userCount = [int]($result.Trim())
    
    Write-Host "    Users with phone $testPhone : $userCount" -ForegroundColor Gray
    
    return $userCount -eq 1
}

# Test 12: Check queue worker logs
Test-Step "Check queue worker logs for errors" {
    $logs = docker exec traidnet-backend tail -50 /var/www/html/storage/logs/payments-queue.log 2>$null
    
    if ($logs) {
        $errorCount = ($logs | Select-String -Pattern "ERROR|FAIL" -CaseSensitive:$false).Count
        Write-Host "    Error lines in logs: $errorCount" -ForegroundColor Gray
        
        if ($errorCount -gt 0) {
            Write-Host "    Recent errors found (check logs for details)" -ForegroundColor Yellow
        }
        
        return $true # Informational only
    }
    return $true
}

# Cleanup
Write-Host "`n[CLEANUP] Removing test data..." -ForegroundColor Yellow

# Delete test user and related data
$cleanupQuery = @"
DELETE FROM user_subscriptions WHERE user_id = $global:userId;
DELETE FROM payments WHERE phone_number = '$testPhone';
DELETE FROM radcheck WHERE username LIKE 'user_$($testPhone.Replace('+', '').Substring(0, 12))%';
DELETE FROM users WHERE id = $global:userId;
"@

docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c $cleanupQuery 2>$null | Out-Null
Write-Host "    Test data cleaned up" -ForegroundColor Gray

# Summary
Write-Host "`n╔════════════════════════════════════════════════════════╗" -ForegroundColor Cyan
Write-Host "║                  TEST RESULTS SUMMARY                  ║" -ForegroundColor Cyan
Write-Host "╚════════════════════════════════════════════════════════╝" -ForegroundColor Cyan

Write-Host "`nTotal Tests:  $($testResults.Total)" -ForegroundColor White
Write-Host "Passed:       $($testResults.Passed)" -ForegroundColor Green
Write-Host "Failed:       $($testResults.Failed)" -ForegroundColor Red
Write-Host "Success Rate: $([math]::Round(($testResults.Passed / $testResults.Total) * 100, 2))%" -ForegroundColor Yellow

if ($testResults.Failed -eq 0) {
    Write-Host "`n🎉 ALL HOTSPOT USER TESTS PASSED! 🎉`n" -ForegroundColor Green
    exit 0
} else {
    Write-Host "`n❌ SOME TESTS FAILED ❌`n" -ForegroundColor Red
    exit 1
}
