<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Router;
use App\Services\MikroTik\SecurityHardeningService;
use RouterOS\Client;
use RouterOS\Query;

$routerId = 'b859ccfd-9b8a-4c7a-87cb-bf1fd49489f7';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         END-TO-END ROUTER CONFIGURATION TEST                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    $router = Router::find($routerId);
    
    if (!$router) {
        echo "❌ Router not found\n";
        exit(1);
    }
    
    echo "Router: {$router->name} ({$router->ip_address})\n";
    echo "Status: {$router->status}\n\n";
    
    $password = decrypt($router->password);
    $ipAddress = trim(explode('/', $router->ip_address)[0]);
    
    $client = new Client([
        'host' => $ipAddress,
        'user' => $router->username,
        'pass' => $password,
        'port' => $router->port ?? 8728,
    ]);
    
    echo "✅ Connected to router\n\n";
    
    // ============================================================
    // TEST 1: Apply Missing RADIUS Configuration
    // ============================================================
    echo "TEST 1: Applying RADIUS Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    try {
        // Remove existing RADIUS
        $existing = $client->query(new Query('/radius/print'))->read();
        foreach ($existing as $rad) {
            if (isset($rad['service']) && $rad['service'] === 'hotspot') {
                $client->query((new Query('/radius/remove'))
                    ->equal('.id', $rad['.id'])
                )->read();
                echo "  Removed existing RADIUS server\n";
            }
        }
        
        // Add RADIUS with correct syntax
        $radiusIP = env('RADIUS_SERVER_HOST', 'traidnet-freeradius');
        $radiusSecret = env('RADIUS_SECRET', 'testing123');
        
        $client->query((new Query('/radius/add'))
            ->equal('address', $radiusIP)
            ->equal('service', 'hotspot')
            ->equal('secret', $radiusSecret)
        )->read();
        
        echo "✅ RADIUS server added: $radiusIP\n";
        
        // Configure RADIUS settings
        $radiusServers = $client->query(new Query('/radius/print'))->read();
        foreach ($radiusServers as $rad) {
            if (isset($rad['service']) && $rad['service'] === 'hotspot') {
                $client->query((new Query('/radius/set'))
                    ->equal('.id', $rad['.id'])
                    ->equal('authentication-port', '1812')
                    ->equal('accounting-port', '1813')
                    ->equal('timeout', '3s')
                )->read();
                echo "✅ RADIUS settings configured\n";
            }
        }
        
    } catch (\Exception $e) {
        echo "❌ RADIUS configuration failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // ============================================================
    // TEST 2: Apply NAT Masquerade
    // ============================================================
    echo "TEST 2: Applying NAT Masquerade\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    try {
        // Check if masquerade exists
        $natRules = $client->query(new Query('/ip/firewall/nat/print'))->read();
        $hasMasquerade = false;
        
        foreach ($natRules as $rule) {
            if (isset($rule['action']) && $rule['action'] === 'masquerade') {
                $hasMasquerade = true;
                echo "  Found existing masquerade rule\n";
            }
        }
        
        if (!$hasMasquerade) {
            // Add masquerade rule
            $client->query((new Query('/ip/firewall/nat/add'))
                ->equal('chain', 'srcnat')
                ->equal('action', 'masquerade')
                ->equal('src-address', '192.168.88.0/24')
                ->equal('out-interface', 'ether1')
                ->equal('comment', 'Hotspot Internet Access')
            )->read();
            
            echo "✅ NAT masquerade rule added\n";
        } else {
            echo "✅ NAT masquerade already configured\n";
        }
        
    } catch (\Exception $e) {
        echo "❌ NAT configuration failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // ============================================================
    // TEST 3: Apply Security Hardening
    // ============================================================
    echo "TEST 3: Applying Security Hardening\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    try {
        $securityService = new SecurityHardeningService();
        $result = $securityService->applySecurityHardening($router);
        
        if ($result['success']) {
            echo "✅ Security hardening applied successfully\n";
            foreach ($result['applied'] as $item) {
                echo "  - $item\n";
            }
        } else {
            echo "⚠️  Security hardening completed with errors:\n";
            foreach ($result['errors'] as $error) {
                echo "  - $error\n";
            }
        }
        
    } catch (\Exception $e) {
        echo "❌ Security hardening failed: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
    
    // ============================================================
    // TEST 4: Verify All Configurations
    // ============================================================
    echo "TEST 4: Verifying All Configurations\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
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
    
    // Check Hotspot Server
    $servers = $client->query(new Query('/ip/hotspot/print'))->read();
    $checks['Hotspot Server'] = !empty($servers);
    
    // Check Hotspot Profile
    $profiles = $client->query(new Query('/ip/hotspot/profile/print'))->read();
    $hasProfile = false;
    foreach ($profiles as $profile) {
        if (isset($profile['name']) && strpos($profile['name'], 'hs-profile') !== false) {
            $hasProfile = true;
            break;
        }
    }
    $checks['Hotspot Profile'] = $hasProfile;
    
    // Check IP Pool
    $pools = $client->query(new Query('/ip/pool/print'))->read();
    $hasPool = false;
    foreach ($pools as $pool) {
        if (isset($pool['name']) && strpos($pool['name'], 'hotspot') !== false) {
            $hasPool = true;
            break;
        }
    }
    $checks['IP Pool'] = $hasPool;
    
    // Check DHCP
    $dhcpServers = $client->query(new Query('/ip/dhcp-server/print'))->read();
    $hasDHCP = false;
    foreach ($dhcpServers as $dhcp) {
        if (isset($dhcp['name']) && strpos($dhcp['name'], 'hotspot') !== false) {
            $hasDHCP = true;
            break;
        }
    }
    $checks['DHCP Server'] = $hasDHCP;
    
    // Check Walled Garden
    $walledGarden = $client->query(new Query('/ip/hotspot/walled-garden/print'))->read();
    $checks['Walled Garden'] = !empty($walledGarden);
    
    // Check Security Services
    $services = $client->query(new Query('/ip/service/print'))->read();
    $securityScore = 0;
    $totalSecurityChecks = 0;
    
    foreach ($services as $service) {
        $name = $service['name'] ?? '';
        $disabled = ($service['disabled'] ?? 'false') === 'true';
        
        if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
            $totalSecurityChecks++;
            if ($disabled) {
                $securityScore++;
            }
        }
    }
    
    $checks['Security Services'] = $securityScore === $totalSecurityChecks;
    
    echo "\n";
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
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║         TEST RESULTS                                           ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    $percentage = round(($passed / $total) * 100);
    echo "Configuration Score: $passed/$total ($percentage%)\n";
    
    if ($totalSecurityChecks > 0) {
        $securityPercentage = round(($securityScore / $totalSecurityChecks) * 100);
        echo "Security Score: $securityScore/$totalSecurityChecks ($securityPercentage%)\n";
    }
    
    echo "\n";
    
    if ($passed === $total) {
        echo "🎉 ALL TESTS PASSED! Router is fully configured!\n";
    } elseif ($passed >= $total * 0.8) {
        echo "✅ GOOD! Most tests passed. Minor issues remain.\n";
    } else {
        echo "⚠️  NEEDS ATTENTION! Several tests failed.\n";
    }
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
