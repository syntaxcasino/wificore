<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

echo "=== TESTING ROUTER CREATION ===\n\n";

// Get first user
$user = App\Models\User::first();
if (!$user) {
    echo "❌ No users found in database\n";
    exit(1);
}

echo "✅ Found user: {$user->email}\n";
echo "✅ Tenant ID: {$user->tenant_id}\n\n";

// Get tenant
$tenant = $user->tenant;
if (!$tenant) {
    echo "❌ User has no tenant\n";
    exit(1);
}

echo "✅ Tenant: {$tenant->name}\n";
echo "✅ Schema: {$tenant->schema_name}\n\n";

// Set tenant context
DB::statement("SET search_path TO {$tenant->schema_name}, public");

echo "=== Creating Router ===\n";

try {
    // Generate unique IP
    $ipAddress = '192.168.56.' . rand(100, 200) . '/24';
    $username = 'traidnet_user';
    $password = Str::random(12);
    $port = 8728;
    $configToken = Str::uuid();
    $routerName = 'test-router-' . time();

    echo "Router Name: {$routerName}\n";
    echo "IP Address: {$ipAddress}\n\n";

    // Create router
    $router = App\Models\Router::create([
        'name' => $routerName,
        'ip_address' => $ipAddress,
        'username' => $username,
        'password' => Crypt::encrypt($password),
        'port' => $port,
        'config_token' => $configToken,
        'status' => 'pending',
        'vpn_enabled' => true,
        'vpn_status' => 'pending',
    ]);

    echo "✅ Router created: {$router->id}\n\n";

    // Create VPN configuration
    echo "=== Creating VPN Configuration ===\n";
    
    $vpnService = app(App\Services\VpnService::class);
    $vpnConfig = $vpnService->createVpnConfiguration($tenant, $router);

    echo "✅ VPN Config created\n";
    echo "   Client IP: {$vpnConfig->client_ip}\n";
    echo "   Server IP: {$vpnConfig->server_ip}\n";
    echo "   Listen Port: {$vpnConfig->listen_port}\n\n";

    // Generate scripts
    echo "=== Generating Scripts ===\n";
    
    $controller = new App\Http\Controllers\Api\RouterController();
    $reflection = new ReflectionClass($controller);
    $method = $reflection->getMethod('generateVpnScript');
    $method->setAccessible(true);
    
    $vpnScript = $method->invoke($controller, $vpnConfig, $router);
    
    echo "✅ VPN Script generated\n";
    echo "   Length: " . strlen($vpnScript) . " characters\n";
    echo "   Preview:\n";
    echo substr($vpnScript, 0, 200) . "...\n\n";

    echo "=== SUCCESS ===\n";
    echo "Router creation and VPN provisioning works!\n";

} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "   Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
