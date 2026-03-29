#!/bin/bash
# Mount entire backend source to /src; symlink vendor from the image into /src
docker run --rm \
  --network wificore-network \
  --entrypoint sh \
  -v //d/traidnet/wificore/backend:/src \
  -e APP_ENV=testing \
  -e APP_KEY="base64:dGVzdGtleWJhc2U2NHRlc3RrZXliYXNlNjR0ZXM=" \
  -e DB_CONNECTION=pgsql \
  -e DB_HOST=wificore-postgres \
  -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing \
  -e DB_USERNAME=admin \
  -e DB_PASSWORD=secret \
  -e CACHE_STORE=array \
  -e QUEUE_CONNECTION=sync \
  -e SESSION_DRIVER=array \
  -e RADIUS_SERVER_HOST=10.8.0.1 \
  -e RADIUS_SECRET=testing123 \
  -e APP_BASE_DOMAIN=example.com \
  -e VPN_SUBNET_BASE="10.0.0.0/8" \
  -e VPN_SERVER_IP=10.8.0.1 \
  -e LOG_CHANNEL=stderr \
  -e PUSHER_APP_ID=test \
  -e PUSHER_APP_KEY=test \
  -e PUSHER_APP_SECRET=test \
  wificore-wificore-backend \
  -c "cp -r /var/www/html/vendor /src/vendor && cd /src && php vendor/bin/pest tests/Feature/RouterProvisioningTest.php --no-coverage 2>&1"
