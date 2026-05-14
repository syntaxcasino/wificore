#!/bin/bash
# Run this from /opt/wificore or wherever your docker-compose is
# Usage: bash diagnose.sh 2>&1 | tee diagnose-output.txt

COMPOSE_FILE="${1:-docker-compose.production.yml}"

echo "============================================================"
echo "1. CONTAINER STATUS"
echo "============================================================"
docker compose -f $COMPOSE_FILE ps

echo ""
echo "============================================================"
echo "2. FREERADIUS - is it up and listening?"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-freeradius sh -c 'ps aux; echo "---ports---"; ss -ulnp | grep -E "1812|1813"'

echo ""
echo "============================================================"
echo "3. FREERADIUS - last 30 lines of radius.log (real auth entries)"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-freeradius sh -c 'tail -n 30 /opt/var/log/radius/radius.log'

echo ""
echo "============================================================"
echo "4. RADIUS radcheck entries for admin users"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-postgres psql -U admin -d wms_770_ts -c "
SELECT 'public' as schema, username, attribute, op, LEFT(value,30) as value
FROM public.radcheck
WHERE username IN (SELECT username FROM public.users WHERE role IN ('system_admin','admin'))
ORDER BY username, attribute;"

echo ""
echo "============================================================"
echo "5. LIVE RADIUS AUTH TEST from backend container"
echo "============================================================"
# Replace sysadmin / Admin@123! with your actual credentials
docker compose -f $COMPOSE_FILE exec wificore-backend php -r '
require "/var/www/html/vendor/autoload.php";
$app = require_once "/var/www/html/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$t0 = microtime(true);
$r = new Dapphp\Radius\Radius();
$r->setServer("wificore-freeradius");
$r->setSecret(config("radius.secret", "testing123"));
$r->setAuthenticationPort(1812);
$r->setTimeout(2);
$r->setNasIpAddress("127.0.0.1");

// Test with actual admin username (not email)
$users = Illuminate\Support\Facades\DB::select(
    "SELECT username, role FROM public.users WHERE role IN (?,?) LIMIT 3",
    ["system_admin","admin"]
);
foreach ($users as $u) {
    $t = microtime(true);
    echo "Testing RADIUS for [{$u->username}] role=[{$u->role}]...\n";
    $result = $r->accessRequest($u->username, "wrongpass_probe");
    $ms = round((microtime(true)-$t)*1000);
    echo "  Result: " . var_export($result,true) . " | Error: " . $r->getErrorMessage() . " | Time: {$ms}ms\n";
}
$total = round((microtime(true)-$t0)*1000);
echo "Total: {$total}ms\n";
'

echo ""
echo "============================================================"
echo "6. LOGIN ENDPOINT TIMING (5 requests)"
echo "============================================================"
for i in 1 2 3 4 5; do
  curl -s -w "req $i: HTTP %{http_code} | total=%{time_total}s | ttfb=%{time_starttransfer}s\n" \
    -o /dev/null \
    -X POST http://localhost:8070/api/login \
    -H "Content-Type: application/json" \
    -d '{"username":"sysadmin","password":"wrongpass"}'
done

echo ""
echo "============================================================"
echo "7. LARAVEL LOG - last 50 lines"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-backend sh -c 'tail -n 50 /var/www/html/storage/logs/laravel.log'

echo ""
echo "============================================================"
echo "8. PHP-FPM - current process count and OPcache status"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-backend sh -c '
echo "FPM workers:"; ps aux | grep "php-fpm: pool" | grep -v grep | wc -l
echo "OPcache enabled (CLI):"; php -r "echo opcache_get_status(false)[\"opcache_enabled\"] ? \"YES\" : \"NO (CLI disables it by default)\"; echo \"\n\";"
echo "Config cache:"; ls -la /var/www/html/bootstrap/cache/*.php 2>/dev/null || echo "NONE"
'

echo ""
echo "============================================================"
echo "9. NGINX ACCESS LOG - last 20 API requests"
echo "============================================================"
docker compose -f $COMPOSE_FILE exec wificore-nginx sh -c 'tail -n 20 /var/log/nginx/access.log' 2>/dev/null || echo "no nginx access log"

echo ""
echo "============================================================"
echo "DONE - paste the full output above"
echo "============================================================"
