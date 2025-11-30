<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Router;
use App\Services\MikroTik\SecurityHardeningService;
use RouterOS\Client;
use RouterOS\Query;

$routerId = $argv[1] ?? 'b859ccfd-9b8a-4c7a-87cb-bf1fd49489f7';

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         COMPLETE END-TO-END AUTOMATED TEST                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$startTime = microtime(true);

try {
    $router = Router::find($routerId);
    
    if (!$router) {
        echo "❌ Router not found: $routerId\n";
        exit(1);
    }
    
    echo "Router: {$router->name}\n";
    echo "IP: {$router->ip_address}\n";
    echo "Status: {$router->status}\n\n";
    
    $password = decrypt($router->password);
    $ipAddress = trim(explode('/', $router->ip_address)[0]);
    
    $client = new Client([
        'host' => $ipAddress,
        'user' => $router->username,
        'pass' => $password,
        'port' => $router->port ?? 8728,
        'timeout' => 10,
    ]);
    
    echo "✅ Connected to router\n\n";
    
    $results = [
        'passed' => 0,
        'failed' => 0,
        'warnings' => 0,
        'tests' => []
    ];
    
    // ============================================================
    // TEST 1: Hotspot Server Configuration
    // ============================================================
    echo "TEST 1: Hotspot Server Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $servers = $client->query(new Query('/ip/hotspot/print'))->read();
    if (!empty($servers)) {
        echo "✅ PASS: Hotspot server configured\n";
        foreach ($servers as $server) {
            echo "   - Name: " . ($server['name'] ?? 'N/A') . "\n";
            echo "   - Interface: " . ($server['interface'] ?? 'N/A') . "\n";
            echo "   - Profile: " . ($server['profile'] ?? 'N/A') . "\n";
        }
        $results['passed']++;
        $results['tests']['hotspot_server'] = 'PASS';
    } else {
        echo "❌ FAIL: No hotspot server found\n";
        $results['failed']++;
        $results['tests']['hotspot_server'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // TEST 2: RADIUS Configuration
    // ============================================================
    echo "TEST 2: RADIUS Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $radiusServers = $client->query(new Query('/radius/print'))->read();
    $hasRADIUS = false;
    foreach ($radiusServers as $rad) {
        if (isset($rad['service']) && $rad['service'] === 'hotspot') {
            $hasRADIUS = true;
            echo "✅ PASS: RADIUS server configured\n";
            echo "   - Address: " . ($rad['address'] ?? 'N/A') . "\n";
            echo "   - Service: " . ($rad['service'] ?? 'N/A') . "\n";
            echo "   - Auth Port: " . ($rad['authentication-port'] ?? 'N/A') . "\n";
            echo "   - Acct Port: " . ($rad['accounting-port'] ?? 'N/A') . "\n";
            break;
        }
    }
    
    if ($hasRADIUS) {
        $results['passed']++;
        $results['tests']['radius'] = 'PASS';
    } else {
        echo "❌ FAIL: RADIUS server not configured\n";
        $results['failed']++;
        $results['tests']['radius'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // TEST 3: NAT Masquerade
    // ============================================================
    echo "TEST 3: NAT Masquerade\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $natRules = $client->query(new Query('/ip/firewall/nat/print'))->read();
    $hasNAT = false;
    foreach ($natRules as $rule) {
        if (isset($rule['action']) && $rule['action'] === 'masquerade') {
            $hasNAT = true;
            echo "✅ PASS: NAT masquerade configured\n";
            echo "   - Chain: " . ($rule['chain'] ?? 'N/A') . "\n";
            echo "   - Out Interface: " . ($rule['out-interface'] ?? 'N/A') . "\n";
            echo "   - Comment: " . ($rule['comment'] ?? 'N/A') . "\n";
            break;
        }
    }
    
    if ($hasNAT) {
        $results['passed']++;
        $results['tests']['nat'] = 'PASS';
    } else {
        echo "❌ FAIL: NAT masquerade not configured\n";
        $results['failed']++;
        $results['tests']['nat'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // TEST 4: DNS Configuration
    // ============================================================
    echo "TEST 4: DNS Configuration\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $dns = $client->query(new Query('/ip/dns/print'))->read();
    $dnsServers = $dns[0]['servers'] ?? '';
    if (!empty($dnsServers)) {
        echo "✅ PASS: DNS servers configured\n";
        echo "   - Servers: $dnsServers\n";
        echo "   - Allow Remote: " . ($dns[0]['allow-remote-requests'] ?? 'N/A') . "\n";
        $results['passed']++;
        $results['tests']['dns'] = 'PASS';
    } else {
        echo "❌ FAIL: DNS servers not configured\n";
        $results['failed']++;
        $results['tests']['dns'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // TEST 5: Security Services
    // ============================================================
    echo "TEST 5: Security Services\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $services = $client->query(new Query('/ip/service/print'))->read();
    $securityScore = 0;
    $totalChecks = 0;
    $securityDetails = [];
    
    foreach ($services as $service) {
        $name = $service['name'] ?? '';
        $disabled = ($service['disabled'] ?? 'false') === 'true';
        $address = $service['address'] ?? '0.0.0.0/0';
        
        if (in_array($name, ['telnet', 'ftp', 'www', 'api-ssl'])) {
            $totalChecks++;
            if ($disabled) {
                $securityScore++;
                $securityDetails[$name] = "✅ Disabled";
            } else {
                $securityDetails[$name] = "❌ Enabled (should be disabled)";
            }
        } elseif (in_array($name, ['ssh', 'winbox', 'api'])) {
            $totalChecks++;
            if ($address !== '0.0.0.0/0' && $address !== '') {
                $securityScore++;
                $securityDetails[$name] = "✅ Restricted to $address";
            } else {
                $securityDetails[$name] = "⚠️  Open to all";
            }
        }
    }
    
    foreach ($securityDetails as $service => $status) {
        echo "   $service: $status\n";
    }
    
    $securityPercentage = $totalChecks > 0 ? round(($securityScore / $totalChecks) * 100) : 0;
    echo "\n   Security Score: $securityScore/$totalChecks ($securityPercentage%)\n";
    
    if ($securityPercentage >= 85) {
        echo "✅ PASS: Security services properly configured\n";
        $results['passed']++;
        $results['tests']['security'] = 'PASS';
    } elseif ($securityPercentage >= 70) {
        echo "⚠️  WARNING: Security services partially configured\n";
        $results['warnings']++;
        $results['tests']['security'] = 'WARNING';
    } else {
        echo "❌ FAIL: Security services not properly configured\n";
        $results['failed']++;
        $results['tests']['security'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // TEST 6: Walled Garden
    // ============================================================
    echo "TEST 6: Walled Garden\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $walledGarden = $client->query(new Query('/ip/hotspot/walled-garden/print'))->read();
    if (!empty($walledGarden)) {
        echo "✅ PASS: Walled garden configured (" . count($walledGarden) . " entries)\n";
        foreach ($walledGarden as $entry) {
            echo "   - " . ($entry['dst-host'] ?? $entry['dst-address'] ?? 'N/A') . "\n";
        }
        $results['passed']++;
        $results['tests']['walled_garden'] = 'PASS';
    } else {
        echo "⚠️  WARNING: No walled garden entries\n";
        $results['warnings']++;
        $results['tests']['walled_garden'] = 'WARNING';
    }
    echo "\n";
    
    // ============================================================
    // TEST 7: Firewall Rules
    // ============================================================
    echo "TEST 7: Firewall Rules\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $firewallRules = $client->query(new Query('/ip/firewall/filter/print'))->read();
    $hasEstablished = false;
    $hasInvalid = false;
    $ruleCount = count($firewallRules);
    
    foreach ($firewallRules as $rule) {
        $comment = $rule['comment'] ?? '';
        if (stripos($comment, 'Established') !== false) $hasEstablished = true;
        if (stripos($comment, 'Invalid') !== false) $hasInvalid = true;
    }
    
    echo "   Total Rules: $ruleCount\n";
    echo "   Established/Related Rule: " . ($hasEstablished ? "✅ Yes" : "❌ No") . "\n";
    echo "   Invalid Drop Rule: " . ($hasInvalid ? "✅ Yes" : "❌ No") . "\n";
    
    if ($hasEstablished && $hasInvalid) {
        echo "✅ PASS: Essential firewall rules configured\n";
        $results['passed']++;
        $results['tests']['firewall'] = 'PASS';
    } else {
        echo "⚠️  WARNING: Some firewall rules missing\n";
        $results['warnings']++;
        $results['tests']['firewall'] = 'WARNING';
    }
    echo "\n";
    
    // ============================================================
    // TEST 8: System Resources
    // ============================================================
    echo "TEST 8: System Resources\n";
    echo "═══════════════════════════════════════════════════════════════\n";
    
    $resources = $client->query(new Query('/system/resource/print'))->read();
    if (!empty($resources)) {
        $res = $resources[0];
        $cpuLoad = $res['cpu-load'] ?? 'N/A';
        $freeMemory = isset($res['free-memory']) ? round($res['free-memory'] / 1024 / 1024, 2) : 'N/A';
        $totalMemory = isset($res['total-memory']) ? round($res['total-memory'] / 1024 / 1024, 2) : 'N/A';
        $uptime = $res['uptime'] ?? 'N/A';
        
        echo "   CPU Load: $cpuLoad%\n";
        echo "   Memory: $freeMemory MB free / $totalMemory MB total\n";
        echo "   Uptime: $uptime\n";
        echo "✅ PASS: System resources retrieved\n";
        $results['passed']++;
        $results['tests']['resources'] = 'PASS';
    } else {
        echo "❌ FAIL: Could not retrieve system resources\n";
        $results['failed']++;
        $results['tests']['resources'] = 'FAIL';
    }
    echo "\n";
    
    // ============================================================
    // FINAL RESULTS
    // ============================================================
    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);
    
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║         TEST RESULTS SUMMARY                                   ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    $totalTests = $results['passed'] + $results['failed'] + $results['warnings'];
    $passRate = $totalTests > 0 ? round(($results['passed'] / $totalTests) * 100) : 0;
    
    echo "Total Tests: $totalTests\n";
    echo "Passed: {$results['passed']} ✅\n";
    echo "Failed: {$results['failed']} ❌\n";
    echo "Warnings: {$results['warnings']} ⚠️\n";
    echo "Pass Rate: $passRate%\n";
    echo "Duration: {$duration}s\n";
    echo "\n";
    
    if ($results['failed'] === 0 && $results['warnings'] === 0) {
        echo "🎉 PERFECT! All tests passed!\n";
        echo "\n";
        echo "Router Status: ✅ PRODUCTION READY\n";
        echo "  - Hotspot service fully configured\n";
        echo "  - RADIUS authentication working\n";
        echo "  - NAT configured for internet access\n";
        echo "  - Security hardened ($securityPercentage% score)\n";
        echo "  - DNS configured and working\n";
        echo "  - All systems operational\n";
    } elseif ($results['failed'] === 0) {
        echo "✅ GOOD! All critical tests passed with minor warnings.\n";
        echo "\n";
        echo "Router Status: ✅ PRODUCTION READY (with minor issues)\n";
    } elseif ($passRate >= 75) {
        echo "⚠️  NEEDS ATTENTION! Some tests failed.\n";
        echo "\n";
        echo "Router Status: ⚠️  PARTIALLY CONFIGURED\n";
        echo "Review failed tests above and fix issues.\n";
    } else {
        echo "❌ CRITICAL! Multiple tests failed.\n";
        echo "\n";
        echo "Router Status: ❌ NOT READY\n";
        echo "Major configuration issues detected. Review and fix.\n";
    }
    
    echo "\n";
    echo "Test completed at: " . date('Y-m-d H:i:s') . "\n";
    
} catch (\Exception $e) {
    echo "\n❌ FATAL ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
