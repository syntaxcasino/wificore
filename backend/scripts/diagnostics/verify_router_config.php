<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Query;

// Router ID from user
$routerId = 'b859ccfd-9b8a-4c7a-87cb-bf1fd49489f7';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         ROUTER HOTSPOT CONFIGURATION VERIFICATION              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

try {
    // Fetch router from database
    $router = Router::find($routerId);
    
    if (!$router) {
        echo "❌ Router not found with ID: $routerId\n";
        exit(1);
    }
    
    echo "Router Information:\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    echo "Name: {$router->name}\n";
    echo "IP: {$router->ip_address}\n";
    echo "Status: {$router->status}\n";
    echo "Model: {$router->model}\n";
    echo "OS Version: {$router->os_version}\n";
    echo "Last Seen: {$router->last_seen}\n\n";
    
    // Connect to router
    echo "Connecting to router...\n";
    $client = new Client([
        'host' => str_replace('/24', '', $router->ip_address),
        'user' => $router->username,
        'pass' => decrypt($router->password),
        'port' => (int) $router->port,
    ]);
    
    echo "✅ Connected successfully!\n\n";
    
    // ============================================================
    // 1. CHECK HOTSPOT SERVER
    // ============================================================
    echo "1. Hotspot Server Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/hotspot/print');
    $servers = $client->query($query)->read();
    
    if (empty($servers)) {
        echo "❌ No hotspot server configured\n\n";
    } else {
        foreach ($servers as $server) {
            echo "✅ Hotspot Server Found:\n";
            echo "   Name: " . ($server['name'] ?? 'N/A') . "\n";
            echo "   Interface: " . ($server['interface'] ?? 'N/A') . "\n";
            echo "   Address Pool: " . ($server['address-pool'] ?? 'N/A') . "\n";
            echo "   Profile: " . ($server['profile'] ?? 'N/A') . "\n";
            echo "   Disabled: " . ($server['disabled'] ?? 'false') . "\n\n";
        }
    }
    
    // ============================================================
    // 2. CHECK HOTSPOT PROFILE
    // ============================================================
    echo "2. Hotspot Profile Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/hotspot/profile/print');
    $profiles = $client->query($query)->read();
    
    $hasCustomProfile = false;
    foreach ($profiles as $profile) {
        if (isset($profile['name']) && strpos($profile['name'], 'hs-profile') !== false) {
            $hasCustomProfile = true;
            echo "✅ Custom Hotspot Profile Found:\n";
            echo "   Name: " . ($profile['name'] ?? 'N/A') . "\n";
            echo "   Login By: " . ($profile['login-by'] ?? 'N/A') . "\n";
            echo "   Use RADIUS: " . ($profile['use-radius'] ?? 'no') . "\n";
            echo "   Rate Limit: " . ($profile['rate-limit'] ?? 'N/A') . "\n";
            echo "   Session Timeout: " . ($profile['session-timeout'] ?? 'none') . "\n";
            echo "   Idle Timeout: " . ($profile['idle-timeout'] ?? 'none') . "\n\n";
        }
    }
    
    if (!$hasCustomProfile) {
        echo "⚠️  No custom hotspot profile found\n\n";
    }
    
    // ============================================================
    // 3. CHECK RADIUS CONFIGURATION
    // ============================================================
    echo "3. RADIUS Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/radius/print');
    $radius = $client->query($query)->read();
    
    if (empty($radius)) {
        echo "❌ No RADIUS server configured\n\n";
    } else {
        foreach ($radius as $rad) {
            echo "✅ RADIUS Server Found:\n";
            echo "   Address: " . ($rad['address'] ?? 'N/A') . "\n";
            echo "   Service: " . ($rad['service'] ?? 'N/A') . "\n";
            echo "   Disabled: " . ($rad['disabled'] ?? 'false') . "\n\n";
        }
    }
    
    // ============================================================
    // 4. CHECK IP POOL
    // ============================================================
    echo "4. IP Pool Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/pool/print');
    $pools = $client->query($query)->read();
    
    $hasHotspotPool = false;
    foreach ($pools as $pool) {
        if (isset($pool['name']) && strpos($pool['name'], 'hotspot') !== false) {
            $hasHotspotPool = true;
            echo "✅ Hotspot IP Pool Found:\n";
            echo "   Name: " . ($pool['name'] ?? 'N/A') . "\n";
            echo "   Ranges: " . ($pool['ranges'] ?? 'N/A') . "\n\n";
        }
    }
    
    if (!$hasHotspotPool) {
        echo "⚠️  No hotspot IP pool found\n\n";
    }
    
    // ============================================================
    // 5. CHECK DHCP SERVER
    // ============================================================
    echo "5. DHCP Server Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/dhcp-server/print');
    $dhcpServers = $client->query($query)->read();
    
    $hasHotspotDHCP = false;
    foreach ($dhcpServers as $dhcp) {
        if (isset($dhcp['name']) && strpos($dhcp['name'], 'hotspot') !== false) {
            $hasHotspotDHCP = true;
            echo "✅ Hotspot DHCP Server Found:\n";
            echo "   Name: " . ($dhcp['name'] ?? 'N/A') . "\n";
            echo "   Interface: " . ($dhcp['interface'] ?? 'N/A') . "\n";
            echo "   Address Pool: " . ($dhcp['address-pool'] ?? 'N/A') . "\n";
            echo "   Disabled: " . ($dhcp['disabled'] ?? 'false') . "\n\n";
        }
    }
    
    if (!$hasHotspotDHCP) {
        echo "⚠️  No hotspot DHCP server found\n\n";
    }
    
    // ============================================================
    // 6. CHECK BRIDGE
    // ============================================================
    echo "6. Bridge Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/interface/bridge/print');
    $bridges = $client->query($query)->read();
    
    $hasHotspotBridge = false;
    foreach ($bridges as $bridge) {
        if (isset($bridge['name']) && strpos($bridge['name'], 'hotspot') !== false) {
            $hasHotspotBridge = true;
            echo "✅ Hotspot Bridge Found:\n";
            echo "   Name: " . ($bridge['name'] ?? 'N/A') . "\n";
            echo "   Running: " . ($bridge['running'] ?? 'false') . "\n";
            echo "   Disabled: " . ($bridge['disabled'] ?? 'false') . "\n\n";
        }
    }
    
    if (!$hasHotspotBridge) {
        echo "⚠️  No hotspot bridge found\n\n";
    }
    
    // ============================================================
    // 7. CHECK NAT RULES
    // ============================================================
    echo "7. NAT Rules (Masquerade)\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/firewall/nat/print');
    $natRules = $client->query($query)->read();
    
    $hasMasquerade = false;
    foreach ($natRules as $rule) {
        if (isset($rule['action']) && $rule['action'] === 'masquerade') {
            $hasMasquerade = true;
            echo "✅ Masquerade Rule Found:\n";
            echo "   Chain: " . ($rule['chain'] ?? 'N/A') . "\n";
            echo "   Out Interface: " . ($rule['out-interface'] ?? 'N/A') . "\n";
            echo "   Disabled: " . ($rule['disabled'] ?? 'false') . "\n\n";
        }
    }
    
    if (!$hasMasquerade) {
        echo "⚠️  No masquerade rule found\n\n";
    }
    
    // ============================================================
    // 8. CHECK DNS CONFIGURATION
    // ============================================================
    echo "8. DNS Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/dns/print');
    $dns = $client->query($query)->read();
    
    if (!empty($dns)) {
        $dnsConfig = $dns[0];
        echo "✅ DNS Configuration:\n";
        echo "   Servers: " . ($dnsConfig['servers'] ?? 'N/A') . "\n";
        echo "   Allow Remote Requests: " . ($dnsConfig['allow-remote-requests'] ?? 'no') . "\n\n";
    }
    
    // ============================================================
    // 9. CHECK WALLED GARDEN
    // ============================================================
    echo "9. Walled Garden Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/hotspot/walled-garden/print');
    $walledGarden = $client->query($query)->read();
    
    if (empty($walledGarden)) {
        echo "⚠️  No walled garden entries configured\n\n";
    } else {
        echo "✅ Walled Garden Entries: " . count($walledGarden) . "\n";
        foreach ($walledGarden as $entry) {
            echo "   - " . ($entry['dst-host'] ?? $entry['dst-address'] ?? 'N/A') . "\n";
        }
        echo "\n";
    }
    
    // ============================================================
    // 10. CHECK SECURITY SERVICES
    // ============================================================
    echo "10. Security Services Status\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $query = new Query('/ip/service/print');
    $services = $client->query($query)->read();
    
    $securityScore = 0;
    $totalChecks = 0;
    
    foreach ($services as $service) {
        $name = $service['name'] ?? '';
        $disabled = ($service['disabled'] ?? 'false') === 'true';
        
        if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
            $totalChecks++;
            if ($disabled) {
                echo "✅ $name: Disabled (Secure)\n";
                $securityScore++;
            } else {
                echo "⚠️  $name: Enabled (Security Risk)\n";
            }
        } elseif (in_array($name, ['ssh', 'winbox', 'api'])) {
            $totalChecks++;
            $address = $service['address'] ?? '';
            if (!empty($address) && $address !== '0.0.0.0/0') {
                echo "✅ $name: Restricted to $address\n";
                $securityScore++;
            } elseif ($disabled) {
                echo "⚠️  $name: Disabled\n";
            } else {
                echo "⚠️  $name: Enabled for all (Security Risk)\n";
            }
        }
    }
    
    echo "\n";
    
    // ============================================================
    // FINAL SUMMARY
    // ============================================================
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║         CONFIGURATION SUMMARY                                  ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    $checks = [
        'Hotspot Server' => !empty($servers),
        'Hotspot Profile' => $hasCustomProfile,
        'RADIUS Server' => !empty($radius),
        'IP Pool' => $hasHotspotPool,
        'DHCP Server' => $hasHotspotDHCP,
        'Bridge' => $hasHotspotBridge,
        'NAT Masquerade' => $hasMasquerade,
        'DNS Configuration' => !empty($dns),
        'Walled Garden' => !empty($walledGarden),
    ];
    
    $passed = 0;
    $total = count($checks);
    
    foreach ($checks as $check => $status) {
        if ($status) {
            echo "✅ $check: Configured\n";
            $passed++;
        } else {
            echo "❌ $check: Missing\n";
        }
    }
    
    echo "\n";
    echo "Configuration Score: $passed/$total (" . round(($passed/$total)*100) . "%)\n";
    
    if ($totalChecks > 0) {
        echo "Security Score: $securityScore/$totalChecks (" . round(($securityScore/$totalChecks)*100) . "%)\n";
    }
    
    echo "\n";
    
    if ($passed === $total && $securityScore === $totalChecks) {
        echo "🎉 EXCELLENT! All recommended configurations are in place!\n";
    } elseif ($passed >= $total * 0.7) {
        echo "✅ GOOD! Most configurations are in place. Review missing items.\n";
    } else {
        echo "⚠️  NEEDS ATTENTION! Several configurations are missing.\n";
    }
    
    $client->disconnect();
    
} catch (\Exception $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
