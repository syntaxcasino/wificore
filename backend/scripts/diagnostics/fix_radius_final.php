<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Query;

$routerId = 'b859ccfd-9b8a-4c7a-87cb-bf1fd49489f7';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         FINAL RADIUS & SECURITY FIX                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    $router = Router::find($routerId);
    $password = decrypt($router->password);
    $ipAddress = trim(explode('/', $router->ip_address)[0]);
    
    $client = new Client([
        'host' => $ipAddress,
        'user' => $router->username,
        'pass' => $password,
        'port' => $router->port ?? 8728,
    ]);
    
    echo "✅ Connected to router: {$router->name}\n\n";
    
    // ============================================================
    // FIX 1: Add RADIUS with IP address (not hostname)
    // ============================================================
    echo "FIX 1: Configuring RADIUS Server\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    // Get FreeRADIUS container IP
    $radiusIP = gethostbyname('traidnet-freeradius');
    if ($radiusIP === 'traidnet-freeradius') {
        // Fallback to Docker network IP
        $radiusIP = '172.18.0.5'; // Typical Docker network IP
        echo "⚠️  Using fallback IP: $radiusIP\n";
    } else {
        echo "✅ Resolved FreeRADIUS IP: $radiusIP\n";
    }
    
    $radiusSecret = env('RADIUS_SECRET', 'testing123');
    
    // Remove all existing RADIUS servers
    $existing = $client->query(new Query('/radius/print'))->read();
    foreach ($existing as $rad) {
        $client->query((new Query('/radius/remove'))
            ->equal('.id', $rad['.id'])
        )->read();
        echo "  Removed existing RADIUS server\n";
    }
    
    // Add RADIUS with IP address
    $client->query((new Query('/radius/add'))
        ->equal('address', $radiusIP)
        ->equal('service', 'hotspot')
        ->equal('secret', $radiusSecret)
        ->equal('timeout', '3s')
    )->read();
    
    echo "✅ RADIUS server added: $radiusIP\n";
    
    // Verify it was added
    $radiusServers = $client->query(new Query('/radius/print'))->read();
    if (!empty($radiusServers)) {
        echo "✅ RADIUS server verified:\n";
        foreach ($radiusServers as $rad) {
            echo "  - Address: " . ($rad['address'] ?? 'N/A') . "\n";
            echo "  - Service: " . ($rad['service'] ?? 'N/A') . "\n";
            echo "  - Secret: " . (isset($rad['secret']) ? '***' : 'N/A') . "\n";
        }
    } else {
        echo "❌ RADIUS server not found after adding!\n";
    }
    
    echo "\n";
    
    // ============================================================
    // FIX 2: Harden Security Services
    // ============================================================
    echo "FIX 2: Hardening Security Services\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $services = $client->query(new Query('/ip/service/print'))->read();
    
    foreach ($services as $service) {
        $name = $service['name'] ?? '';
        $id = $service['.id'] ?? '';
        
        // Disable insecure services
        if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
            $client->query((new Query('/ip/service/set'))
                ->equal('.id', $id)
                ->equal('disabled', 'yes')
            )->read();
            echo "✅ Disabled: $name\n";
        }
        
        // Restrict management services to management network
        if (in_array($name, ['ssh', 'winbox', 'api'])) {
            $client->query((new Query('/ip/service/set'))
                ->equal('.id', $id)
                ->equal('address', '192.168.56.0/24')
            )->read();
            echo "✅ Restricted to 192.168.56.0/24: $name\n";
        }
    }
    
    echo "\n";
    
    // ============================================================
    // FIX 3: Set DNS Servers
    // ============================================================
    echo "FIX 3: Configuring DNS Servers\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $client->query((new Query('/ip/dns/set'))
        ->equal('servers', '8.8.8.8,1.1.1.1')
        ->equal('allow-remote-requests', 'yes')
    )->read();
    
    echo "✅ DNS servers set: 8.8.8.8, 1.1.1.1\n";
    echo "✅ Remote requests allowed\n\n";
    
    // ============================================================
    // FINAL VERIFICATION
    // ============================================================
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║         FINAL VERIFICATION                                     ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    $checks = [];
    
    // Check RADIUS
    $radiusServers = $client->query(new Query('/radius/print'))->read();
    $hasRADIUS = false;
    foreach ($radiusServers as $rad) {
        if (isset($rad['service']) && $rad['service'] === 'hotspot') {
            $hasRADIUS = true;
            break;
        }
    }
    $checks['RADIUS Server'] = $hasRADIUS;
    
    // Check NAT
    $natRules = $client->query(new Query('/ip/firewall/nat/print'))->read();
    $hasNAT = false;
    foreach ($natRules as $rule) {
        if (isset($rule['action']) && $rule['action'] === 'masquerade') {
            $hasNAT = true;
            break;
        }
    }
    $checks['NAT Masquerade'] = $hasNAT;
    
    // Check Hotspot
    $servers = $client->query(new Query('/ip/hotspot/print'))->read();
    $checks['Hotspot Server'] = !empty($servers);
    
    // Check DNS
    $dns = $client->query(new Query('/ip/dns/print'))->read();
    $hasDNS = false;
    if (!empty($dns)) {
        $servers = $dns[0]['servers'] ?? '';
        $hasDNS = !empty($servers);
    }
    $checks['DNS Servers'] = $hasDNS;
    
    // Check Security
    $services = $client->query(new Query('/ip/service/print'))->read();
    $securityScore = 0;
    $totalChecks = 0;
    
    foreach ($services as $service) {
        $name = $service['name'] ?? '';
        $disabled = ($service['disabled'] ?? 'false') === 'true';
        
        if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
            $totalChecks++;
            if ($disabled) {
                $securityScore++;
            }
        }
    }
    
    $checks['Security Services'] = $securityScore === $totalChecks;
    
    // Display results
    $passed = 0;
    $total = count($checks);
    
    foreach ($checks as $check => $status) {
        if ($status) {
            echo "✅ $check: OK\n";
            $passed++;
        } else {
            echo "❌ $check: FAILED\n";
        }
    }
    
    echo "\n";
    $percentage = round(($passed / $total) * 100);
    echo "Configuration Score: $passed/$total ($percentage%)\n";
    
    if ($totalChecks > 0) {
        $securityPercentage = round(($securityScore / $totalChecks) * 100);
        echo "Security Score: $securityScore/$totalChecks ($securityPercentage%)\n";
    }
    
    echo "\n";
    
    if ($passed === $total && $securityScore === $totalChecks) {
        echo "🎉 PERFECT! All configurations applied successfully!\n";
        echo "\n";
        echo "Router is now:\n";
        echo "  ✅ Fully configured for hotspot service\n";
        echo "  ✅ RADIUS authentication enabled\n";
        echo "  ✅ NAT configured for internet access\n";
        echo "  ✅ Security hardened\n";
        echo "  ✅ DNS configured\n";
        echo "  ✅ Ready for production use\n";
    } elseif ($passed >= $total * 0.8) {
        echo "✅ GOOD! Most configurations applied successfully.\n";
    } else {
        echo "⚠️  Some configurations failed. Review above.\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
