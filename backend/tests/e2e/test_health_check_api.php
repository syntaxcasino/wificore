<?php

/**
 * Health Check API Test
 * Tests all health check endpoints
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Services\HealthCheckService;
use App\Models\User;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         HEALTH CHECK API TEST                                  ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

$startTime = microtime(true);
$results = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0
];

// ============================================================
// TEST 1: HealthCheckService - System Health
// ============================================================
echo "TEST 1: HealthCheckService::getSystemHealth()\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $healthService = new HealthCheckService();
    $systemHealth = $healthService->getSystemHealth();
    
    if (isset($systemHealth['status']) && isset($systemHealth['checks'])) {
        echo "✅ PASS: System health returned valid structure\n";
        echo "   Status: {$systemHealth['status']}\n";
        echo "   Duration: {$systemHealth['duration']}s\n";
        echo "   Checks: " . count($systemHealth['checks']) . "\n";
        echo "   Health: {$systemHealth['summary']['healthy']}/{$systemHealth['summary']['total_checks']}\n";
        $results['passed']++;
    } else {
        echo "❌ FAIL: Invalid structure returned\n";
        $results['failed']++;
    }
} catch (\Exception $e) {
    echo "❌ FAIL: " . $e->getMessage() . "\n";
    $results['failed']++;
}
$results['total']++;
echo "\n";

// ============================================================
// TEST 2: HealthCheckService - Router Health
// ============================================================
echo "TEST 2: HealthCheckService::getRouterHealth()\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $healthService = new HealthCheckService();
    $routerHealth = $healthService->getRouterHealth();
    
    if (isset($routerHealth['status']) && isset($routerHealth['total'])) {
        echo "✅ PASS: Router health returned valid structure\n";
        echo "   Status: {$routerHealth['status']}\n";
        echo "   Total: {$routerHealth['total']}\n";
        echo "   Online: {$routerHealth['online']}\n";
        echo "   Offline: {$routerHealth['offline']}\n";
        echo "   Uptime: {$routerHealth['uptime_percentage']}%\n";
        $results['passed']++;
    } else {
        echo "❌ FAIL: Invalid structure returned\n";
        $results['failed']++;
    }
} catch (\Exception $e) {
    echo "❌ FAIL: " . $e->getMessage() . "\n";
    $results['failed']++;
}
$results['total']++;
echo "\n";

// ============================================================
// TEST 3: HealthCheckService - Database Health
// ============================================================
echo "TEST 3: HealthCheckService::getDatabaseHealth()\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $healthService = new HealthCheckService();
    $databaseHealth = $healthService->getDatabaseHealth();
    
    if (isset($databaseHealth['status']) && isset($databaseHealth['response_time'])) {
        echo "✅ PASS: Database health returned valid structure\n";
        echo "   Status: {$databaseHealth['status']}\n";
        echo "   Response Time: {$databaseHealth['response_time']}\n";
        echo "   Connection: {$databaseHealth['connection']}\n";
        if (isset($databaseHealth['stats'])) {
            echo "   Users: {$databaseHealth['stats']['users']}\n";
            echo "   Routers: {$databaseHealth['stats']['routers']}\n";
        }
        $results['passed']++;
    } else {
        echo "❌ FAIL: Invalid structure returned\n";
        $results['failed']++;
    }
} catch (\Exception $e) {
    echo "❌ FAIL: " . $e->getMessage() . "\n";
    $results['failed']++;
}
$results['total']++;
echo "\n";

// ============================================================
// TEST 4: HealthCheckService - Security Health
// ============================================================
echo "TEST 4: HealthCheckService::getSecurityHealth()\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $healthService = new HealthCheckService();
    $securityHealth = $healthService->getSecurityHealth();
    
    if (isset($securityHealth['status']) && isset($securityHealth['score'])) {
        echo "✅ PASS: Security health returned valid structure\n";
        echo "   Status: {$securityHealth['status']}\n";
        echo "   Score: {$securityHealth['score']}/{$securityHealth['max_score']}\n";
        echo "   Percentage: {$securityHealth['percentage']}%\n";
        echo "   Checks: " . count($securityHealth['checks']) . "\n";
        $results['passed']++;
    } else {
        echo "❌ FAIL: Invalid structure returned\n";
        $results['failed']++;
    }
} catch (\Exception $e) {
    echo "❌ FAIL: " . $e->getMessage() . "\n";
    $results['failed']++;
}
$results['total']++;
echo "\n";

// ============================================================
// TEST 5: Check Routes Exist
// ============================================================
echo "TEST 5: Verify Health Check Routes\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $routes = \Illuminate\Support\Facades\Route::getRoutes();
    $healthRoutes = [];
    
    foreach ($routes as $route) {
        $uri = $route->uri();
        if (strpos($uri, 'health') !== false) {
            $healthRoutes[] = $uri;
        }
    }
    
    if (count($healthRoutes) >= 5) {
        echo "✅ PASS: Health check routes registered\n";
        foreach ($healthRoutes as $route) {
            echo "   - $route\n";
        }
        $results['passed']++;
    } else {
        echo "❌ FAIL: Not all health routes found (found " . count($healthRoutes) . ")\n";
        $results['failed']++;
    }
} catch (\Exception $e) {
    echo "❌ FAIL: " . $e->getMessage() . "\n";
    $results['failed']++;
}
$results['total']++;
echo "\n";

// ============================================================
// FINAL RESULTS
// ============================================================
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         TEST RESULTS                                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total Tests: {$results['total']}\n";
echo "Passed: {$results['passed']} ✅\n";
echo "Failed: {$results['failed']} ❌\n";
echo "Success Rate: " . round(($results['passed'] / $results['total']) * 100) . "%\n";
echo "Duration: {$duration}s\n\n";

if ($results['failed'] === 0) {
    echo "🎉 ALL TESTS PASSED!\n";
    echo "\n";
    echo "Health Check System Status:\n";
    echo "  ✅ HealthCheckService working\n";
    echo "  ✅ All methods functional\n";
    echo "  ✅ Routes registered\n";
    echo "  ✅ Ready for frontend integration\n";
} else {
    echo "⚠️  SOME TESTS FAILED!\n";
    echo "Review the failures above.\n";
}

echo "\nTest completed at: " . date('Y-m-d H:i:s') . "\n";
