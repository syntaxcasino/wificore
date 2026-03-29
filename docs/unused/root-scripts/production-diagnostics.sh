#!/bin/bash
# Production Diagnostics Script for WifiCore Registration Issues
# Run this on your production server

echo "=========================================="
echo "WIFICORE PRODUCTION DIAGNOSTICS"
echo "=========================================="
echo ""

echo "1. RECENT REGISTRATIONS (Last 5)"
echo "----------------------------------------"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$regs = DB::table('tenant_registrations')->orderBy('created_at', 'desc')->limit(5)->get();
foreach(\$regs as \$r) {
    echo 'ID: ' . \$r->id . PHP_EOL;
    echo 'Token: ' . \$r->token . PHP_EOL;
    echo 'Email: ' . \$r->tenant_email . PHP_EOL;
    echo 'Slug: ' . \$r->tenant_slug . PHP_EOL;
    echo 'Verified: ' . (\$r->email_verified ? 'Yes' : 'No') . PHP_EOL;
    echo 'Status: ' . \$r->status . PHP_EOL;
    echo 'Credentials Sent: ' . (\$r->credentials_sent ? 'Yes' : 'No') . PHP_EOL;
    echo 'Tenant ID: ' . (\$r->tenant_id ?? 'NULL') . PHP_EOL;
    echo 'Created: ' . \$r->created_at . PHP_EOL;
    echo '---' . PHP_EOL;
}
"

echo ""
echo "2. QUEUE WORKERS STATUS"
echo "----------------------------------------"
docker exec wificore-backend ps aux | grep "queue:work" | grep -v grep

echo ""
echo "3. PENDING & FAILED JOBS"
echo "----------------------------------------"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
echo 'Total pending jobs: ' . DB::table('jobs')->count() . PHP_EOL;
echo 'Emails queue: ' . DB::table('jobs')->where('queue', 'emails')->count() . PHP_EOL;
echo 'Tenant-management queue: ' . DB::table('jobs')->where('queue', 'tenant-management')->count() . PHP_EOL;
echo 'Failed jobs: ' . DB::table('failed_jobs')->count() . PHP_EOL;
echo PHP_EOL;
if (DB::table('failed_jobs')->count() > 0) {
    echo 'Recent failed jobs:' . PHP_EOL;
    \$failed = DB::table('failed_jobs')->orderBy('failed_at', 'desc')->limit(3)->get();
    foreach(\$failed as \$f) {
        \$payload = json_decode(\$f->payload);
        echo 'Job: ' . \$payload->displayName . PHP_EOL;
        echo 'Queue: ' . \$f->queue . PHP_EOL;
        echo 'Failed at: ' . \$f->failed_at . PHP_EOL;
        echo 'Exception: ' . substr(\$f->exception, 0, 300) . '...' . PHP_EOL;
        echo '---' . PHP_EOL;
    }
}
"

echo ""
echo "4. LARAVEL LOGS (Last 100 lines, filtered for registration/verification)"
echo "----------------------------------------"
docker exec wificore-backend tail -n 100 /var/www/html/storage/logs/laravel.log | grep -i "verif\|registration\|workspace\|credentials"

echo ""
echo "5. NGINX ACCESS LOGS (Last 50 lines, filtered for verify)"
echo "----------------------------------------"
docker exec wificore-nginx tail -n 50 /var/log/nginx/access.log | grep verify

echo ""
echo "6. NGINX ERROR LOGS (Last 30 lines)"
echo "----------------------------------------"
docker exec wificore-nginx tail -n 30 /var/log/nginx/error.log

echo ""
echo "7. SOKETI LOGS (Last 30 lines)"
echo "----------------------------------------"
docker logs wificore-soketi --tail 30

echo ""
echo "8. BACKEND CONTAINER STATUS"
echo "----------------------------------------"
docker ps | grep wificore

echo ""
echo "9. TEST VERIFICATION ENDPOINT"
echo "----------------------------------------"
echo "Testing if verification endpoint is accessible..."
LATEST_TOKEN=$(docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$reg = DB::table('tenant_registrations')->where('email_verified', false)->orderBy('created_at', 'desc')->first();
if (\$reg) echo \$reg->token;
")

if [ ! -z "$LATEST_TOKEN" ]; then
    echo "Testing with token: $LATEST_TOKEN"
    docker exec wificore-backend php -r "
    require '/var/www/html/vendor/autoload.php';
    \$app = require_once '/var/www/html/bootstrap/app.php';
    \$kernel = \$app->make('Illuminate\\Contracts\\Http\\Kernel');
    \$request = Illuminate\\Http\\Request::create('/api/register/verify/$LATEST_TOKEN', 'GET');
    \$response = \$kernel->handle(\$request);
    echo \$response->getContent();
    "
else
    echo "No unverified registrations found to test"
fi

echo ""
echo "=========================================="
echo "DIAGNOSTICS COMPLETE"
echo "=========================================="
