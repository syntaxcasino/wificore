#!/bin/bash

echo "=== VERIFICATION DIAGNOSIS ==="
echo ""

echo "1. Recent Registrations:"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

\$regs = DB::table('tenant_registrations')->orderBy('created_at', 'desc')->limit(3)->get();
foreach(\$regs as \$r) {
    echo 'Token: ' . \$r->token . PHP_EOL;
    echo 'Email: ' . \$r->tenant_email . PHP_EOL;
    echo 'Verified: ' . (\$r->email_verified ? 'Yes' : 'No') . PHP_EOL;
    echo 'Status: ' . \$r->status . PHP_EOL;
    echo '---' . PHP_EOL;
}
"

echo ""
echo "2. Queue Workers Running:"
docker exec wificore-backend sh -c "ps aux | grep 'queue:work' | grep -v grep | wc -l"

echo ""
echo "3. Pending Jobs:"
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();
echo 'Total: ' . DB::table('jobs')->count() . PHP_EOL;
echo 'Emails queue: ' . DB::table('jobs')->where('queue', 'emails')->count() . PHP_EOL;
"

echo ""
echo "4. Recent Laravel Logs (verification related):"
docker exec wificore-backend sh -c "tail -n 100 /var/www/html/storage/logs/laravel.log | grep -i 'verif\|registration\|workspace'"

echo ""
echo "5. Nginx Access Logs (verification endpoint):"
docker exec wificore-nginx sh -c "tail -n 50 /var/log/nginx/access.log | grep verify"
