# Fix FreeRADIUS Dictionary Permissions
# FreeRADIUS refuses to start if dictionary is globally writable

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Fix FreeRADIUS Dictionary Permissions" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Issue: Dictionary file is globally writable" -ForegroundColor Yellow
Write-Host "FreeRADIUS Error: 'Refusing to start due to insecure configuration'" -ForegroundColor Red
Write-Host ""

# Fix permissions on dictionary file
Write-Host "[1/3] Fixing dictionary file permissions..." -ForegroundColor Yellow

$dictionaryPath = ".\freeradius\dictionary"

if (Test-Path $dictionaryPath) {
    # Remove inheritance and set proper permissions
    $acl = Get-Acl $dictionaryPath
    $acl.SetAccessRuleProtection($true, $false)
    
    # Remove all existing rules
    $acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
    
    # Add read/write for current user only
    $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
    $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        $currentUser,
        "Read,Write",
        "Allow"
    )
    $acl.AddAccessRule($rule)
    
    # Add read for SYSTEM
    $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule(
        "SYSTEM",
        "Read",
        "Allow"
    )
    $acl.AddAccessRule($systemRule)
    
    Set-Acl $dictionaryPath $acl
    
    Write-Host "✅ Dictionary permissions fixed" -ForegroundColor Green
} else {
    Write-Host "❌ Dictionary file not found at: $dictionaryPath" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Fix permissions on other FreeRADIUS config files
Write-Host "[2/3] Fixing other FreeRADIUS config permissions..." -ForegroundColor Yellow

$configFiles = @(
    ".\freeradius\clients.conf"
)

foreach ($file in $configFiles) {
    if (Test-Path $file) {
        $acl = Get-Acl $file
        $acl.SetAccessRuleProtection($true, $false)
        $acl.Access | ForEach-Object { $acl.RemoveAccessRule($_) | Out-Null }
        
        $currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
        $rule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            $currentUser,
            "Read,Write",
            "Allow"
        )
        $acl.AddAccessRule($rule)
        
        $systemRule = New-Object System.Security.AccessControl.FileSystemAccessRule(
            "SYSTEM",
            "Read",
            "Allow"
        )
        $acl.AddAccessRule($systemRule)
        
        Set-Acl $file $acl
        Write-Host "  ✅ Fixed: $file" -ForegroundColor Green
    }
}

Write-Host ""

# Restart FreeRADIUS
Write-Host "[3/3] Restarting FreeRADIUS container..." -ForegroundColor Yellow
docker-compose restart traidnet-freeradius

Write-Host "⏳ Waiting for FreeRADIUS to start..." -ForegroundColor Yellow
Start-Sleep -Seconds 10

# Check if FreeRADIUS started successfully
$logs = docker-compose logs --tail 20 traidnet-freeradius 2>&1 | Out-String

if ($logs -match "globally writable") {
    Write-Host "❌ FreeRADIUS still has permission issues" -ForegroundColor Red
    Write-Host ""
    Write-Host "Logs:" -ForegroundColor Yellow
    docker-compose logs --tail 30 traidnet-freeradius
    Write-Host ""
    Write-Host "Alternative solution: Copy configs into container instead of mounting" -ForegroundColor Yellow
    exit 1
} elseif ($logs -match "Ready to process requests") {
    Write-Host "✅ FreeRADIUS started successfully!" -ForegroundColor Green
} else {
    Write-Host "⚠️  FreeRADIUS status unclear, check logs:" -ForegroundColor Yellow
    docker-compose logs --tail 30 traidnet-freeradius
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "  Fix Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

Write-Host "Test RADIUS authentication:" -ForegroundColor Yellow
Write-Host '  docker exec traidnet-freeradius radtest sysadmin Admin@123 localhost 0 testing123' -ForegroundColor White
Write-Host ""

Write-Host "Test login:" -ForegroundColor Yellow
Write-Host '  curl -X POST http://localhost/api/login \' -ForegroundColor White
Write-Host '    -H "Content-Type: application/json" \' -ForegroundColor White
Write-Host '    -d ''{"username":"sysadmin","password":"Admin@123"}''' -ForegroundColor White
Write-Host ""
