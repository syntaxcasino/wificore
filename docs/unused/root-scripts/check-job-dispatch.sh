#!/bin/bash
# Check if CreateTenantWorkspaceJob is being dispatched and processed

echo "Checking job dispatch for registration..."
echo ""

# Get latest registration token
TOKEN=$(docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$reg = DB::table('tenant_registrations')->latest()->first();
if (\$reg) echo \$reg->token;
")

if [ -z "$TOKEN" ]; then
    echo "No registrations found"
    exit 1
fi

echo "Latest registration token: $TOKEN"
echo ""

# Check registration status
echo "Registration Status:"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$reg = DB::table('tenant_registrations')->where('token', '$TOKEN')->first();
echo 'Email: ' . \$reg->tenant_email . PHP_EOL;
echo 'Status: ' . \$reg->status . PHP_EOL;
echo 'Email Verified: ' . (\$reg->email_verified ? 'Yes' : 'No') . PHP_EOL;
echo 'Tenant ID: ' . (\$reg->tenant_id ?? 'NULL') . PHP_EOL;
echo 'User ID: ' . (\$reg->user_id ?? 'NULL') . PHP_EOL;
"

echo ""
echo "Pending Jobs in Database:"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$jobs = DB::table('jobs')->get();
echo 'Total: ' . \$jobs->count() . PHP_EOL;
foreach(\$jobs as \$job) {
    \$payload = json_decode(\$job->payload);
    echo 'Queue: ' . \$job->queue . ' | Job: ' . \$payload->displayName . ' | Attempts: ' . \$job->attempts . PHP_EOL;
}
"

echo ""
echo "Failed Jobs:"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$failed = DB::table('failed_jobs')->get();
echo 'Total: ' . \$failed->count() . PHP_EOL;
foreach(\$failed as \$f) {
    \$payload = json_decode(\$f->payload);
    echo 'Queue: ' . \$f->queue . ' | Job: ' . \$payload->displayName . PHP_EOL;
    echo 'Failed at: ' . \$f->failed_at . PHP_EOL;
    echo 'Exception: ' . substr(\$f->exception, 0, 200) . '...' . PHP_EOL;
    echo '---' . PHP_EOL;
}
"

echo ""
echo "Laravel Log (last 20 lines):"
docker exec wificore-backend tail -n 20 /var/www/html/storage/logs/laravel.log

echo ""
echo "Tenant Management Queue Log:"
docker exec wificore-backend tail -n 20 /var/www/html/storage/logs/tenant-management-queue.log
