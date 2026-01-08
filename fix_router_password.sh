#!/bin/bash
# Script to fix router with password decryption issues
# Run this on the production server

echo "==================================="
echo "Router Password Fix Script"
echo "==================================="
echo ""
echo "This script will delete the router with decryption issues"
echo "and allow you to create a new one with the current APP_KEY"
echo ""

ROUTER_ID="98f6268e-fa3e-4227-be7b-767628ec0c3c"

read -p "Are you sure you want to delete router $ROUTER_ID? (yes/no): " confirm

if [ "$confirm" != "yes" ]; then
    echo "Operation cancelled."
    exit 0
fi

echo ""
echo "Deleting router from database..."
echo ""

# Run the deletion command
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker --execute="
\$router = \App\Models\Router::find('$ROUTER_ID');
if (\$router) {
    echo 'Found router: ' . \$router->name . PHP_EOL;
    echo 'Status: ' . \$router->status . PHP_EOL;
    echo 'IP: ' . \$router->ip_address . PHP_EOL;
    \$router->delete();
    echo PHP_EOL . '✓ Router deleted successfully' . PHP_EOL;
} else {
    echo '✗ Router not found' . PHP_EOL;
}
"

echo ""
echo "==================================="
echo "Next Steps:"
echo "==================================="
echo ""
echo "1. Go to the UI: https://wificore.traidsolutions.com"
echo "2. Navigate to Routers > Add Router"
echo "3. Create a new router with the same name: ggn-hsp-01"
echo "4. Apply the generated configuration script to your MikroTik"
echo "5. The router will provision successfully with the current APP_KEY"
echo ""
echo "The new router will use the current APP_KEY and will not have"
echo "password decryption issues."
echo ""
