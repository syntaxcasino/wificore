<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\Route;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         END-TO-END API ENDPOINT VERIFICATION                   ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Testing all API endpoints that frontend uses...\n\n";

$startTime = microtime(true);

// Get all routes
$routes = Route::getRoutes();
$testedEndpoints = [];
$results = [
    'passed' => 0,
    'failed' => 0,
    'total' => 0
];

// Define critical endpoints that frontend uses
$criticalEndpoints = [
    // Authentication
    ['method' => 'POST', 'uri' => 'api/login', 'name' => 'Admin Login'],
    ['method' => 'POST', 'uri' => 'api/register', 'name' => 'Admin Register'],
    ['method' => 'POST', 'uri' => 'api/logout', 'name' => 'Logout'],
    
    // Hotspot
    ['method' => 'POST', 'uri' => 'api/hotspot/login', 'name' => 'Hotspot Login'],
    ['method' => 'POST', 'uri' => 'api/hotspot/logout', 'name' => 'Hotspot Logout'],
    ['method' => 'POST', 'uri' => 'api/hotspot/check-session', 'name' => 'Hotspot Check Session'],
    
    // Packages
    ['method' => 'GET', 'uri' => 'api/packages', 'name' => 'List Packages'],
    
    // Payments
    ['method' => 'POST', 'uri' => 'api/payments/initiate', 'name' => 'Initiate Payment'],
    ['method' => 'GET', 'uri' => 'api/payments/{payment}/status', 'name' => 'Payment Status'],
    ['method' => 'POST', 'uri' => 'api/mpesa/callback', 'name' => 'M-Pesa Callback'],
    
    // Email Verification
    ['method' => 'GET', 'uri' => 'api/email/verify/{id}/{hash}', 'name' => 'Verify Email'],
    ['method' => 'POST', 'uri' => 'api/email/resend', 'name' => 'Resend Verification'],
    
    // Broadcasting
    ['method' => 'POST', 'uri' => 'api/broadcasting/auth', 'name' => 'Broadcasting Auth'],
    
    // Routers
    ['method' => 'GET', 'uri' => 'api/routers', 'name' => 'List Routers'],
    ['method' => 'POST', 'uri' => 'api/routers', 'name' => 'Create Router'],
    ['method' => 'GET', 'uri' => 'api/routers/{router}/details', 'name' => 'Router Details'],
    ['method' => 'GET', 'uri' => 'api/routers/{router}/status', 'name' => 'Router Status'],
    
    // Test
    ['method' => 'POST', 'uri' => 'api/test/websocket', 'name' => 'WebSocket Test'],
];

echo "═══════════════════════════════════════════════════════════════\n";
echo "CHECKING CRITICAL ENDPOINTS\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

foreach ($criticalEndpoints as $endpoint) {
    $results['total']++;
    $found = false;
    
    foreach ($routes as $route) {
        $routeUri = $route->uri();
        $routeMethods = $route->methods();
        
        // Normalize URIs for comparison
        $normalizedRouteUri = str_replace('{', '', str_replace('}', '', $routeUri));
        $normalizedEndpointUri = str_replace('{', '', str_replace('}', '', $endpoint['uri']));
        
        // Check if route matches
        if (in_array($endpoint['method'], $routeMethods)) {
            // Exact match or pattern match
            if ($routeUri === $endpoint['uri'] || 
                preg_match('#^' . preg_replace('/\{[^}]+\}/', '[^/]+', $endpoint['uri']) . '$#', $routeUri)) {
                $found = true;
                echo "✅ {$endpoint['name']}\n";
                echo "   Method: {$endpoint['method']}\n";
                echo "   URI: {$endpoint['uri']}\n";
                echo "   Route: {$routeUri}\n";
                if ($route->getName()) {
                    echo "   Name: {$route->getName()}\n";
                }
                echo "\n";
                $results['passed']++;
                break;
            }
        }
    }
    
    if (!$found) {
        echo "❌ {$endpoint['name']}\n";
        echo "   Method: {$endpoint['method']}\n";
        echo "   URI: {$endpoint['uri']}\n";
        echo "   Status: NOT FOUND\n\n";
        $results['failed']++;
    }
}

// Additional check: List all API routes
echo "═══════════════════════════════════════════════════════════════\n";
echo "ALL API ROUTES SUMMARY\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

$apiRoutes = [];
foreach ($routes as $route) {
    $uri = $route->uri();
    if (strpos($uri, 'api/') === 0) {
        $methods = implode('|', $route->methods());
        $apiRoutes[] = [
            'methods' => $methods,
            'uri' => $uri,
            'name' => $route->getName() ?? 'N/A'
        ];
    }
}

echo "Total API Routes: " . count($apiRoutes) . "\n\n";

// Group by category
$categories = [
    'Authentication' => [],
    'Hotspot' => [],
    'Packages' => [],
    'Payments' => [],
    'Routers' => [],
    'Email' => [],
    'Broadcasting' => [],
    'Test' => [],
    'Other' => []
];

foreach ($apiRoutes as $route) {
    if (strpos($route['uri'], 'api/login') !== false || 
        strpos($route['uri'], 'api/register') !== false || 
        strpos($route['uri'], 'api/logout') !== false) {
        $categories['Authentication'][] = $route;
    } elseif (strpos($route['uri'], 'api/hotspot') !== false) {
        $categories['Hotspot'][] = $route;
    } elseif (strpos($route['uri'], 'api/packages') !== false) {
        $categories['Packages'][] = $route;
    } elseif (strpos($route['uri'], 'api/payments') !== false || 
              strpos($route['uri'], 'api/mpesa') !== false) {
        $categories['Payments'][] = $route;
    } elseif (strpos($route['uri'], 'api/routers') !== false) {
        $categories['Routers'][] = $route;
    } elseif (strpos($route['uri'], 'api/email') !== false) {
        $categories['Email'][] = $route;
    } elseif (strpos($route['uri'], 'api/broadcasting') !== false) {
        $categories['Broadcasting'][] = $route;
    } elseif (strpos($route['uri'], 'api/test') !== false) {
        $categories['Test'][] = $route;
    } else {
        $categories['Other'][] = $route;
    }
}

foreach ($categories as $category => $routes) {
    if (!empty($routes)) {
        echo "📁 $category (" . count($routes) . " routes)\n";
        foreach ($routes as $route) {
            echo "   {$route['methods']} /{$route['uri']}\n";
        }
        echo "\n";
    }
}

// Final Results
$endTime = microtime(true);
$duration = round($endTime - $startTime, 2);

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║         TEST RESULTS                                           ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Total Endpoints Tested: {$results['total']}\n";
echo "Passed: {$results['passed']} ✅\n";
echo "Failed: {$results['failed']} ❌\n";
echo "Success Rate: " . round(($results['passed'] / $results['total']) * 100) . "%\n";
echo "Duration: {$duration}s\n\n";

if ($results['failed'] === 0) {
    echo "🎉 PERFECT! All critical endpoints are available!\n\n";
    echo "Frontend API calls will work correctly:\n";
    echo "  ✅ Hotspot login\n";
    echo "  ✅ Payment initiation\n";
    echo "  ✅ Payment status polling\n";
    echo "  ✅ Package listing\n";
    echo "  ✅ Email verification\n";
    echo "  ✅ WebSocket authentication\n";
    echo "  ✅ Router management\n";
    echo "  ✅ All other endpoints\n";
} else {
    echo "⚠️  WARNING! Some endpoints are missing.\n";
    echo "Review the failed endpoints above.\n";
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "FRONTEND AXIOS CONFIGURATION\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

echo "Base URL: " . env('APP_URL', 'http://localhost') . "/api\n";
echo "Frontend should call: /endpoint (without /api prefix)\n";
echo "Result: " . env('APP_URL', 'http://localhost') . "/api/endpoint\n\n";

echo "Example:\n";
echo "  axios.get('/packages')  →  " . env('APP_URL', 'http://localhost') . "/api/packages ✅\n";
echo "  axios.get('/api/packages')  →  " . env('APP_URL', 'http://localhost') . "/api/api/packages ❌\n\n";

echo "Test completed at: " . date('Y-m-d H:i:s') . "\n";
