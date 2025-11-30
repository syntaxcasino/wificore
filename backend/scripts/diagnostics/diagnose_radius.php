<?php

require '/var/www/html/vendor/autoload.php';

$app = require_once '/var/www/html/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Router;
use RouterOS\Client;
use RouterOS\Query;

$routerId = 'b859ccfd-9b8a-4c7a-87cb-bf1fd49489f7';

$router = Router::find($routerId);
$password = decrypt($router->password);
$ipAddress = trim(explode('/', $router->ip_address)[0]);

$client = new Client([
    'host' => $ipAddress,
    'user' => $router->username,
    'pass' => $password,
    'port' => $router->port ?? 8728,
]);

echo "RADIUS Servers:\n";
echo "═══════════════\n";
$radius = $client->query(new Query('/radius/print'))->read();

if (empty($radius)) {
    echo "No RADIUS servers found\n";
} else {
    foreach ($radius as $rad) {
        echo "RADIUS Server:\n";
        foreach ($rad as $key => $value) {
            echo "  $key: $value\n";
        }
        echo "\n";
    }
}

echo "\nHotspot Profile:\n";
echo "═══════════════\n";
$profiles = $client->query(new Query('/ip/hotspot/profile/print'))->read();
foreach ($profiles as $profile) {
    if (isset($profile['name']) && strpos($profile['name'], 'hs-profile') !== false) {
        echo "Profile: {$profile['name']}\n";
        echo "  use-radius: " . ($profile['use-radius'] ?? 'N/A') . "\n";
        echo "  login-by: " . ($profile['login-by'] ?? 'N/A') . "\n";
    }
}
