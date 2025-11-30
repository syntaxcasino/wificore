#!/bin/bash
echo "=== Checking Supervisor Setup ==="

echo "1. Supervisor config files:"
docker exec traidnet-backend ls -la /etc/supervisor/conf.d/

echo "2. Supervisor processes:"
docker exec traidnet-backend ps aux | grep -E '(supervisor|php-fpm|queue)' || echo "No relevant processes found"

echo "3. Check if PHP-FPM is running:"
docker exec traidnet-backend netstat -tulpn | grep 9000 || echo "PHP-FPM not listening on 9000"

echo "4. Check supervisor logs:"
docker exec traidnet-backend tail -n 10 /var/log/supervisor/supervisord.log 2>/dev/null || echo "No supervisor log file"

echo "5. Check container entrypoint execution:"
docker exec traidnet-backend ls -la /entrypoint.sh
docker exec traidnet-backend cat /entrypoint.sh