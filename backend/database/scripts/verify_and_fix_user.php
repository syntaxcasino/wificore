<?php

/**
 * Verify and Fix User Authentication Setup
 * 
 * This script checks if a user exists in all required tables and creates missing entries.
 * Run this on production to fix authentication issues.
 * 
 * Usage: php artisan tinker < verify_and_fix_user.php
 * Or: docker-compose exec wificore-backend php artisan tinker < verify_and_fix_user.php
 */

$username = 'traidnetsolution';
$password = '0dt?h2*Wk?4KoP*E'; // Current password from radcheck

echo "=== User Authentication Verification ===\n\n";

// 1. Check if user exists in users table
echo "1. Checking users table...\n";
$user = \App\Models\User::withoutGlobalScope(\App\Models\Scopes\TenantScope::class)
    ->where('username', $username)
    ->first();

if ($user) {
    echo "   ✓ User found in users table\n";
    echo "   - ID: {$user->id}\n";
    echo "   - Username: {$user->username}\n";
    echo "   - Email: {$user->email}\n";
    echo "   - Role: {$user->role}\n";
    echo "   - Tenant ID: {$user->tenant_id}\n";
    echo "   - Active: " . ($user->is_active ? 'Yes' : 'No') . "\n";
} else {
    echo "   ✗ User NOT found in users table\n";
    echo "   → Need to create user entry\n";
}

echo "\n";

// 2. Check schema mapping
echo "2. Checking radius_user_schema_mapping...\n";
$mapping = DB::table('radius_user_schema_mapping')
    ->where('username', $username)
    ->first();

if ($mapping) {
    echo "   ✓ Schema mapping found\n";
    echo "   - Schema: {$mapping->schema_name}\n";
    echo "   - Tenant ID: {$mapping->tenant_id}\n";
    echo "   - Active: " . ($mapping->is_active ? 'Yes' : 'No') . "\n";
    $schemaName = $mapping->schema_name;
    $tenantId = $mapping->tenant_id;
} else {
    echo "   ✗ Schema mapping NOT found\n";
    echo "   → Cannot proceed without schema mapping\n";
    exit(1);
}

echo "\n";

// 3. Check radcheck in tenant schema
echo "3. Checking radcheck in tenant schema ({$schemaName})...\n";
DB::statement("SET search_path TO {$schemaName}");
$radcheck = DB::table('radcheck')
    ->where('username', $username)
    ->where('attribute', 'Cleartext-Password')
    ->first();

if ($radcheck) {
    echo "   ✓ User found in radcheck\n";
    echo "   - Password: {$radcheck->value}\n";
} else {
    echo "   ✗ User NOT found in radcheck\n";
}

echo "\n";

// 4. Check radreply in tenant schema
echo "4. Checking radreply in tenant schema ({$schemaName})...\n";
$radreply = DB::table('radreply')
    ->where('username', $username)
    ->get();

if ($radreply->count() > 0) {
    echo "   ✓ User attributes found in radreply\n";
    foreach ($radreply as $attr) {
        echo "   - {$attr->attribute} {$attr->op} {$attr->value}\n";
    }
} else {
    echo "   ✗ No attributes in radreply\n";
}

// Reset search path
DB::statement("SET search_path TO public");

echo "\n";

// 5. Check tenant
echo "5. Checking tenant...\n";
$tenant = \App\Models\Tenant::find($tenantId);

if ($tenant) {
    echo "   ✓ Tenant found\n";
    echo "   - Name: {$tenant->name}\n";
    echo "   - Schema: {$tenant->schema_name}\n";
    echo "   - Active: " . ($tenant->is_active ? 'Yes' : 'No') . "\n";
} else {
    echo "   ✗ Tenant NOT found\n";
}

echo "\n";

// 6. Fix missing user entry if needed
if (!$user && $tenant) {
    echo "6. Creating missing user entry...\n";
    
    try {
        $user = \App\Models\User::create([
            'tenant_id' => $tenantId,
            'name' => ucfirst($username),
            'username' => $username,
            'email' => $username . '@traidsolutions.com',
            'password' => \Hash::make($password),
            'role' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        
        echo "   ✓ User created successfully\n";
        echo "   - ID: {$user->id}\n";
        echo "   - Username: {$user->username}\n";
        echo "   - Email: {$user->email}\n";
    } catch (\Exception $e) {
        echo "   ✗ Failed to create user: {$e->getMessage()}\n";
    }
}

echo "\n=== Verification Complete ===\n";

// 7. Test RADIUS authentication
echo "\n7. Testing RADIUS authentication...\n";
$radiusService = app(\App\Services\RadiusService::class);

try {
    $authenticated = $radiusService->authenticate($username, $password);
    
    if ($authenticated) {
        echo "   ✓ RADIUS authentication SUCCESSFUL\n";
    } else {
        echo "   ✗ RADIUS authentication FAILED\n";
        echo "   → Check RADIUS_SECRET in .env matches FreeRADIUS configuration\n";
        echo "   → Current RADIUS_SECRET: " . env('RADIUS_SECRET') . "\n";
    }
} catch (\Exception $e) {
    echo "   ✗ RADIUS authentication ERROR: {$e->getMessage()}\n";
}

echo "\n";
