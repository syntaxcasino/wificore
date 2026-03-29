# Production Server Diagnostics Commands

## Quick Start

### Option 1: Run Complete Diagnostic Script
```bash
# On your production server
cd /path/to/wificore
chmod +x production-diagnostics.sh
./production-diagnostics.sh > diagnostics-$(date +%Y%m%d-%H%M%S).log
```

### Option 2: Run Individual Commands

## 1. Check Recent Registrations
```bash
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$regs = DB::table('tenant_registrations')->orderBy('created_at', 'desc')->limit(5)->get();
foreach(\$regs as \$r) {
    echo 'ID: ' . \$r->id . ' | Email: ' . \$r->tenant_email . ' | Verified: ' . (\$r->email_verified ? 'Yes' : 'No') . ' | Status: ' . \$r->status . ' | Created: ' . \$r->created_at . PHP_EOL;
}
"
```

## 2. Check Queue Workers
```bash
# Check if queue workers are running
docker exec wificore-backend ps aux | grep "queue:work" | grep -v grep

# Count running workers
docker exec wificore-backend ps aux | grep "queue:work" | grep -v grep | wc -l
```

## 3. Check Pending and Failed Jobs
```bash
docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
echo 'Pending jobs: ' . DB::table('jobs')->count() . PHP_EOL;
echo 'Failed jobs: ' . DB::table('failed_jobs')->count() . PHP_EOL;
"
```

## 4. View Laravel Logs (Registration Related)
```bash
# Last 100 lines filtered for registration/verification
docker exec wificore-backend tail -n 100 /var/www/html/storage/logs/laravel.log | grep -i "verif\|registration\|workspace"

# Full last 100 lines
docker exec wificore-backend tail -n 100 /var/www/html/storage/logs/laravel.log

# Follow logs in real-time
docker exec wificore-backend tail -f /var/www/html/storage/logs/laravel.log
```

## 5. View Nginx Logs
```bash
# Access logs (last 50 lines)
docker exec wificore-nginx tail -n 50 /var/log/nginx/access.log

# Filter for verification requests
docker exec wificore-nginx tail -n 100 /var/log/nginx/access.log | grep verify

# Error logs
docker exec wificore-nginx tail -n 50 /var/log/nginx/error.log

# Follow access logs in real-time
docker exec wificore-nginx tail -f /var/log/nginx/access.log
```

## 6. Check Soketi WebSocket Server
```bash
# View Soketi logs
docker logs wificore-soketi --tail 50

# Follow Soketi logs in real-time
docker logs wificore-soketi --follow

# Check if Soketi is running
docker ps | grep soketi
```

## 7. Check Container Status
```bash
# All WifiCore containers
docker ps | grep wificore

# Detailed container info
docker ps -a --filter "name=wificore" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"

# Container resource usage
docker stats --no-stream | grep wificore
```

## 8. Test Verification Endpoint Directly
```bash
# Replace TOKEN with actual registration token
TOKEN="your-registration-token-here"

docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$kernel = \$app->make('Illuminate\\Contracts\\Http\\Kernel');
\$request = Illuminate\\Http\\Request::create('/api/register/verify/$TOKEN', 'GET');
\$response = \$kernel->handle(\$request);
echo \$response->getContent();
"
```

## 9. Check Specific Registration Details
```bash
# Replace TOKEN with actual registration token
TOKEN="your-registration-token-here"

docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();
\$reg = DB::table('tenant_registrations')->where('token', '$TOKEN')->first();
if (\$reg) {
    echo 'Email: ' . \$reg->tenant_email . PHP_EOL;
    echo 'Verified: ' . (\$reg->email_verified ? 'Yes' : 'No') . PHP_EOL;
    echo 'Status: ' . \$reg->status . PHP_EOL;
    echo 'Credentials Sent: ' . (\$reg->credentials_sent ? 'Yes' : 'No') . PHP_EOL;
    echo 'Tenant ID: ' . (\$reg->tenant_id ?? 'NULL') . PHP_EOL;
    echo 'User ID: ' . (\$reg->user_id ?? 'NULL') . PHP_EOL;
    echo 'Username: ' . (\$reg->generated_username ?? 'NULL') . PHP_EOL;
} else {
    echo 'Registration not found' . PHP_EOL;
}
"
```

## 10. Check RADIUS Credentials for User
```bash
# Replace USERNAME with actual username
USERNAME="your-username-here"

docker exec wificore-backend php -r "
require '/var/www/html/vendor/autoload.php';
\$app = require_once '/var/www/html/bootstrap/app.php';
\$app->make('Illuminate\\Contracts\\Console\\Kernel')->bootstrap();

// Check schema mapping
\$mapping = DB::table('radius_user_schema_mapping')->where('username', '$USERNAME')->first();
if (\$mapping) {
    echo 'Schema mapping found:' . PHP_EOL;
    echo 'Schema: ' . \$mapping->schema_name . PHP_EOL;
    echo 'Tenant ID: ' . \$mapping->tenant_id . PHP_EOL;
    echo 'Active: ' . (\$mapping->is_active ? 'Yes' : 'No') . PHP_EOL;
    
    // Check radcheck in tenant schema
    DB::statement(\"SET search_path TO {\$mapping->schema_name}, public\");
    \$radcheck = DB::table('radcheck')->where('username', '$USERNAME')->first();
    if (\$radcheck) {
        echo 'RADIUS credentials found in tenant schema' . PHP_EOL;
        echo 'Attribute: ' . \$radcheck->attribute . PHP_EOL;
    } else {
        echo 'RADIUS credentials NOT found in tenant schema' . PHP_EOL;
    }
} else {
    echo 'Schema mapping NOT found for user' . PHP_EOL;
}
"
```

## 11. Restart Services (If Needed)
```bash
# Restart backend (queue workers will restart automatically)
docker-compose restart wificore-backend

# Restart frontend
docker-compose restart wificore-frontend

# Restart nginx
docker-compose restart wificore-nginx

# Restart all services
docker-compose restart

# View logs after restart
docker-compose logs -f wificore-backend
```

## 12. Clear Failed Jobs (If Needed)
```bash
docker exec wificore-backend php artisan queue:flush

# Or clear specific failed job
docker exec wificore-backend php artisan queue:forget JOB_ID
```

## Common Issues and Solutions

### Issue: No Queue Workers Running
```bash
# Check supervisor status
docker exec wificore-backend supervisorctl status

# Restart queue workers
docker exec wificore-backend supervisorctl restart all
```

### Issue: Verification Link Not Working
1. Check Nginx is routing `/api/*` to backend
2. Check Laravel logs for errors
3. Test endpoint directly (see command #8)
4. Check registration exists in database

### Issue: No Credentials Email Sent
1. Check if `credentials_sent` is true in registration
2. Check mail logs
3. Check failed jobs queue
4. Verify SMTP configuration

### Issue: Cannot Login After Registration
1. Check RADIUS credentials exist (see command #10)
2. Verify schema mapping is created
3. Check user exists in `users` table
4. Test RADIUS authentication

## Export Logs for Analysis
```bash
# Create a comprehensive log bundle
mkdir -p /tmp/wificore-logs-$(date +%Y%m%d)
cd /tmp/wificore-logs-$(date +%Y%m%d)

# Export all logs
docker exec wificore-backend cat /var/www/html/storage/logs/laravel.log > laravel.log
docker exec wificore-nginx cat /var/log/nginx/access.log > nginx-access.log
docker exec wificore-nginx cat /var/log/nginx/error.log > nginx-error.log
docker logs wificore-soketi > soketi.log
docker logs wificore-backend > backend-container.log

# Run diagnostics
cd /path/to/wificore
./production-diagnostics.sh > /tmp/wificore-logs-$(date +%Y%m%d)/diagnostics.log

# Create archive
cd /tmp
tar -czf wificore-logs-$(date +%Y%m%d).tar.gz wificore-logs-$(date +%Y%m%d)/

echo "Logs exported to: /tmp/wificore-logs-$(date +%Y%m%d).tar.gz"
```

## Real-Time Monitoring
```bash
# Monitor all services in separate terminals
docker-compose logs -f wificore-backend
docker-compose logs -f wificore-frontend
docker-compose logs -f wificore-nginx
docker-compose logs -f wificore-soketi

# Or monitor all at once
docker-compose logs -f
```
