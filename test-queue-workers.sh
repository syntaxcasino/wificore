#!/bin/bash

# Test Queue Workers Detection
# This script tests if the backend can properly detect queue workers

echo "========================================"
echo "Testing Queue Workers Detection"
echo "========================================"
echo ""

echo "[1/4] Checking Supervisor Status..."
echo ""
docker exec traidnet-backend supervisorctl status | grep "laravel-queue"
echo ""

echo "[2/4] Testing getWorkersByQueue() method..."
echo ""
docker exec traidnet-backend php artisan tinker --execute="
\$controller = new \App\Http\Controllers\Api\SystemMetricsController();
\$reflection = new ReflectionClass(\$controller);
\$method = \$reflection->getMethod('getWorkersByQueue');
\$method->setAccessible(true);
\$workers = \$method->invoke(\$controller);
echo 'Workers by queue: ' . json_encode(\$workers, JSON_PRETTY_PRINT) . PHP_EOL;
echo 'Total workers: ' . array_sum(\$workers) . PHP_EOL;
"
echo ""

echo "[3/4] Testing Queue Stats API Endpoint..."
echo ""
docker exec traidnet-backend php artisan tinker --execute="
\$response = (new \App\Http\Controllers\Api\SystemMetricsController())->getQueueStats();
\$data = json_decode(\$response->getContent(), true);
echo 'API Response:' . PHP_EOL;
echo json_encode(\$data, JSON_PRETTY_PRINT) . PHP_EOL;
"
echo ""

echo "[4/4] Checking Laravel Logs for Errors..."
echo ""
docker exec traidnet-backend tail -20 /var/www/html/storage/logs/laravel.log | grep -i "worker\|queue\|supervisor" || echo "No recent worker-related logs"
echo ""

echo "========================================"
echo "Test Complete"
echo "========================================"
echo ""
echo "If workers are not detected, check:"
echo "1. Supervisor is running: docker exec traidnet-backend supervisorctl status"
echo "2. PHP can execute commands: docker exec traidnet-backend php -r \"echo shell_exec('whoami');\""
echo "3. Logs: docker exec traidnet-backend tail -f /var/www/html/storage/logs/laravel.log"
echo ""
