# PowerShell script to update all services to extend TenantAwareService

$services = @(
    # Phase 1: Critical
    "MikrotikSessionService.php",
    "UserProvisioningService.php",
    "SubscriptionManager.php",
    "MpesaService.php",
    "RadiusService.php",
    "MikroTik\BaseMikroTikService.php",
    "RADIUSServiceController.php",
    
    # Phase 2: High
    "RouterServiceManager.php",
    "AccessPointManager.php",
    "MikroTik\HotspotService.php",
    "MikroTik\PPPoEService.php",
    "MikroTik\ConfigurationService.php",
    "WireGuardService.php",
    "MikrotikProvisioningService.php",
    
    # Phase 3: Medium
    "MetricsService.php",
    "InterfaceManagementService.php",
    "MikroTik\SecurityHardeningService.php",
    "WhatsAppService.php"
)

$servicesPath = "app\Services\"
$updated = 0
$alreadyUpdated = 0
$notFound = 0

Write-Host "🚀 Updating all services to be tenant-aware..." -ForegroundColor Green
Write-Host ""

foreach ($service in $services) {
    $filePath = Join-Path $servicesPath $service
    
    if (-not (Test-Path $filePath)) {
        Write-Host "⚠️  File not found: $service" -ForegroundColor Yellow
        $notFound++
        continue
    }
    
    $content = Get-Content $filePath -Raw
    
    # Check if already extends TenantAwareService
    if ($content -match 'extends\s+TenantAwareService') {
        Write-Host "✅ Already tenant-aware: $service" -ForegroundColor Cyan
        $alreadyUpdated++
        continue
    }
    
    # Find class declaration and update it
    if ($content -match 'class\s+(\w+)(\s+extends\s+\w+)?') {
        $className = $matches[1]
        $oldDeclaration = $matches[0]
        
        # Check if it extends another class
        if ($matches[2]) {
            # Already extends something - need manual review
            Write-Host "⚠️  Already extends another class: $service - MANUAL REVIEW REQUIRED" -ForegroundColor Yellow
            continue
        }
        
        # Replace class declaration
        $newDeclaration = "class $className extends TenantAwareService"
        $content = $content -replace [regex]::Escape($oldDeclaration), $newDeclaration
        
        # Save the file
        Set-Content -Path $filePath -Value $content -NoNewline
        
        Write-Host "✅ Updated: $service" -ForegroundColor Green
        $updated++
    } else {
        Write-Host "❌ Could not find class declaration in: $service" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "📊 Summary:" -ForegroundColor Cyan
Write-Host "  ✅ Updated: $updated" -ForegroundColor Green
Write-Host "  ℹ️  Already updated: $alreadyUpdated" -ForegroundColor Cyan
Write-Host "  ⚠️  Not found: $notFound" -ForegroundColor Yellow
Write-Host ""
Write-Host "⚠️  IMPORTANT NEXT STEPS:" -ForegroundColor Yellow
Write-Host "  1. Review each updated service" -ForegroundColor White
Write-Host "  2. Add tenant validation to public methods" -ForegroundColor White
Write-Host "  3. Run tests to ensure no breaking changes" -ForegroundColor White
Write-Host "  4. Check services that extend other classes manually" -ForegroundColor White
Write-Host ""
Write-Host "🎉 Batch update complete!" -ForegroundColor Green
