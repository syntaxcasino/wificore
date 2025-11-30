<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\HotspotUser;
use App\Models\Package;
use Illuminate\Support\Facades\Hash;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         CREATE TEST HOTSPOT USER                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Get a package
    $package = Package::first();
    
    if (!$package) {
        echo "❌ No packages found. Please create a package first.\n";
        exit(1);
    }
    
    echo "Using package: {$package->name}\n";
    echo "Package ID: {$package->id}\n\n";
    
    // Create test user
    $username = 'testuser_' . time();
    $password = 'Test@123';
    $phone = '+254700000000';
    
    $user = HotspotUser::create([
        'username' => $username,
        'password' => Hash::make($password),
        'phone_number' => $phone,
        'package_id' => $package->id,
        'has_active_subscription' => true,
        'subscription_starts_at' => now(),
        'subscription_expires_at' => now()->addDays(30),
        'is_active' => true,
        'data_limit' => $package->data_limit ?? null,
        'data_used' => 0,
    ]);
    
    echo "✅ Test user created successfully!\n\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "User Details:\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "Username: $username\n";
    echo "Password: $password\n";
    echo "Package: {$package->name}\n";
    echo "Speed: {$package->speed_profile}\n";
    echo "Validity: 30 days\n";
    echo "Status: Active\n";
    echo "\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "RADIUS Attributes:\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "User-Name: $username\n";
    echo "User-Password: $password\n";
    echo "Mikrotik-Rate-Limit: {$package->speed_profile}\n";
    echo "\n";
    echo "✅ You can now test authentication with these credentials!\n";
    
} catch (\Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
