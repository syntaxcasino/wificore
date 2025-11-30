<?php

/**
 * Script to update all Laravel models with UUID trait
 * 
 * This script adds the HasUuid trait and 'id' => 'string' cast to all models
 */

$models = [
    'Payment',
    'UserSubscription',
    'Voucher',
    'UserSession',
    'SystemLog',
    'RouterConfig',
    'RouterVpnConfig',
    'WireguardPeer',
    'HotspotUser',
    'HotspotSession',
    'RadiusSession',
    'HotspotCredential',
    'SessionDisconnection',
    'DataUsageLog',
];

$modelsPath = __DIR__ . '/app/Models/';

foreach ($models as $modelName) {
    $filePath = $modelsPath . $modelName . '.php';
    
    if (!file_exists($filePath)) {
        echo "❌ File not found: $filePath\n";
        continue;
    }
    
    $content = file_get_contents($filePath);
    $originalContent = $content;
    
    // Check if already has HasUuid
    if (strpos($content, 'use App\\Traits\\HasUuid;') !== false) {
        echo "⏭️  $modelName: Already has UUID trait\n";
        continue;
    }
    
    // Add use statement after other use statements
    $content = preg_replace(
        '/(use Illuminate\\\\Database\\\\Eloquent\\\\Model;)/i',
        "$1\nuse App\\Traits\\HasUuid;",
        $content
    );
    
    // Add HasUuid to use statement in class
    $content = preg_replace(
        '/(use\s+HasFactory)(;)/i',
        "$1, HasUuid$2",
        $content
    );
    
    // Add 'id' => 'string' to casts array
    if (preg_match('/protected\s+\$casts\s*=\s*\[/', $content)) {
        // Has $casts array, add id cast
        $content = preg_replace(
            '/(protected\s+\$casts\s*=\s*\[)/',
            "$1\n        'id' => 'string',",
            $content
        );
    } elseif (preg_match('/protected\s+function\s+casts\(\)/', $content)) {
        // Has casts() method, add id cast
        $content = preg_replace(
            '/(return\s*\[)/',
            "$1\n            'id' => 'string',",
            $content
        );
    } else {
        // No casts, add $casts property after $fillable
        $content = preg_replace(
            '/(protected\s+\$fillable\s*=\s*\[[^\]]+\];)/s',
            "$1\n\n    protected \$casts = [\n        'id' => 'string',\n    ];",
            $content
        );
    }
    
    if ($content !== $originalContent) {
        file_put_contents($filePath, $content);
        echo "✅ $modelName: Updated with UUID trait\n";
    } else {
        echo "⚠️  $modelName: No changes made\n";
    }
}

echo "\n✅ Model update complete!\n";
