<?php

/**
 * Script to fix all int type hints for IDs to string (for UUID support)
 * 
 * This script updates type hints for:
 * - $routerId
 * - $userId
 * - $packageId
 * - $paymentId
 * - $hotspotUserId
 */

$files = [
    'app/Services/MikroTik/SecurityHardeningService.php',
    'app/Services/MikroTik/PPPoEService.php',
    'app/Services/MikroTik/BaseMikroTikService.php',
    'app/Events/ProvisioningFailed.php',
    'app/Events/RouterLiveDataUpdated.php',
    'app/Events/RouterProvisioningProgress.php',
    'app/Jobs/ProvisionUserInMikroTikJob.php',
    'app/Jobs/RouterProbingJob.php',
    'app/Services/UserProvisioningService.php',
];

$basePath = __DIR__ . '/';
$changes = 0;

foreach ($files as $file) {
    $filePath = $basePath . $file;
    
    if (!file_exists($filePath)) {
        echo "⏭️  File not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Replace int type hints for ID parameters
    $patterns = [
        '/int \$routerId/' => 'string $routerId',
        '/int \$userId/' => 'string $userId',
        '/int \$packageId/' => 'string $packageId',
        '/int \$paymentId/' => 'string $paymentId',
        '/int \$hotspotUserId/' => 'string $hotspotUserId',
        '/int \$sessionId/' => 'string $sessionId',
        '/int \$subscriptionId/' => 'string $subscriptionId',
    ];
    
    $fileChanges = 0;
    foreach ($patterns as $pattern => $replacement) {
        $newContent = preg_replace($pattern, $replacement, $content, -1, $count);
        if ($count > 0) {
            $content = $newContent;
            $fileChanges += $count;
        }
    }
    
    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        echo "✅ $file: Updated $fileChanges type hint(s)\n";
        $changes += $fileChanges;
    } else {
        echo "⏭️  $file: No changes needed\n";
    }
}

echo "\n";
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Type Hint Fix Complete                                        ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
echo "\n";
echo "Total changes: $changes\n";
echo "\n";
echo "✅ All int type hints for IDs have been updated to string!\n";
echo "\n";
echo "Next steps:\n";
echo "1. Rebuild container: docker-compose build traidnet-backend\n";
echo "2. Restart: docker-compose up -d\n";
echo "3. Test router provisioning\n";
