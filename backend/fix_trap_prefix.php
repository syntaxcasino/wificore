<?php

require_once __DIR__ . '/vendor/autoload.php';

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "=== Fixing TRAP prefix for PPPoE portal login ===\n";

// Check if any tenant has TRAP prefix or similar
$tenantWithTrap = DB::table('tenants')
    ->where('account_prefix', 'TRAP')
    ->orWhere('account_prefix', 'TRA')
    ->orWhere('slug', 'like', '%trap%')
    ->orWhere('name', 'like', '%trap%')
    ->first();

if ($tenantWithTrap) {
    echo "Found tenant with TRAP-related prefix:\n";
    echo "ID: {$tenantWithTrap->id}\n";
    echo "Name: {$tenantWithTrap->name}\n";
    echo "Slug: {$tenantWithTrap->slug}\n";
    echo "Account Prefix: {$tenantWithTrap->account_prefix}\n";
    
    // If prefix is not exactly TRAP, update it
    if ($tenantWithTrap->account_prefix !== 'TRAP') {
        echo "Updating account_prefix to TRAP...\n";
        DB::table('tenants')
            ->where('id', $tenantWithTrap->id)
            ->update(['account_prefix' => 'TRAP']);
        echo "Updated successfully.\n";
    }
} else {
    echo "No tenant found with TRAP prefix. Creating one...\n";
    
    // Find the first active tenant without a prefix
    $tenantWithoutPrefix = DB::table('tenants')
        ->where('is_active', true)
        ->whereNull('account_prefix')
        ->first();
    
    if ($tenantWithoutPrefix) {
        echo "Found tenant without prefix: {$tenantWithoutPrefix->name}\n";
        echo "Setting account_prefix to TRAP...\n";
        
        DB::table('tenants')
            ->where('id', $tenantWithoutPrefix->id)
            ->update(['account_prefix' => 'TRAP']);
        
        echo "Updated successfully. Tenant ID {$tenantWithoutPrefix->id} now has TRAP prefix.\n";
    } else {
        echo "No active tenant without prefix found.\n";
        
        // Show all tenants and their prefixes
        $tenants = DB::table('tenants')
            ->select('id', 'name', 'slug', 'account_prefix', 'is_active')
            ->orderBy('name')
            ->get();
        
        echo "\nAll tenants:\n";
        foreach ($tenants as $tenant) {
            echo "ID: {$tenant->id}, Name: {$tenant->name}, Prefix: {$tenant->account_prefix}, Active: {$tenant->is_active}\n";
        }
    }
}

// Check if TRAP00001 exists in any tenant schema
echo "\nChecking for TRAP00001 user...\n";

// Get all tenant schemas
$schemas = DB::table('tenants')
    ->whereNotNull('schema_name')
    ->where('is_active', true)
    ->pluck('schema_name');

foreach ($schemas as $schema) {
    try {
        $user = DB::selectOne("SELECT * FROM {$schema}.pppoe_users WHERE account_number = 'TRAP00001' OR username = 'TRAP00001'");
        if ($user) {
            echo "Found TRAP00001 in schema: {$schema}\n";
            echo "User ID: {$user->id}, Username: {$user->username}, Account Number: {$user->account_number}\n";
        }
    } catch (Exception $e) {
        echo "Error checking schema {$schema}: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Done ===\n";
