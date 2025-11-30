<?php

/**
 * Script to update all services to be tenant-aware
 * Run: php update-services-tenant-aware.php
 */

$services = [
    // Phase 1: Critical
    'MikrotikSessionService.php',
    'UserProvisioningService.php',
    'SubscriptionManager.php',
    'MpesaService.php',
    'RadiusService.php',
    'MikroTik/BaseMikroTikService.php',
    'RADIUSServiceController.php',
    
    // Phase 2: High
    'RouterServiceManager.php',
    'AccessPointManager.php',
    'MikroTik/HotspotService.php',
    'MikroTik/PPPoEService.php',
    'MikroTik/ConfigurationService.php',
    'WireGuardService.php',
    'MikrotikProvisioningService.php',
    
    // Phase 3: Medium
    'MetricsService.php',
    'InterfaceManagementService.php',
    'MikroTik/SecurityHardeningService.php',
    'WhatsAppService.php',
];

$servicesPath = __DIR__ . '/app/Services/';

foreach ($services as $service) {
    $filePath = $servicesPath . $service;
    
    if (!file_exists($filePath)) {
        echo "⚠️  File not found: {$service}\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    
    // Check if already extends TenantAwareService
    if (strpos($content, 'extends TenantAwareService') !== false) {
        echo "✅ Already tenant-aware: {$service}\n";
        continue;
    }
    
    // Find the class declaration
    if (preg_match('/class\s+(\w+)(\s+extends\s+\w+)?/', $content, $matches)) {
        $className = $matches[1];
        $oldDeclaration = $matches[0];
        
        // Replace class declaration to extend TenantAwareService
        $newDeclaration = "class {$className} extends TenantAwareService";
        $content = str_replace($oldDeclaration, $newDeclaration, $content);
        
        // Save the file
        file_put_contents($filePath, $content);
        
        echo "✅ Updated: {$service}\n";
    } else {
        echo "❌ Could not find class declaration in: {$service}\n";
    }
}

echo "\n🎉 All services updated to extend TenantAwareService!\n";
echo "⚠️  IMPORTANT: Review each service and add tenant validation to methods!\n";
