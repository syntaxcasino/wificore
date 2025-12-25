# Production Deployment Instructions - Redis Fix

## Current Issue

The production server is still using the old Redis configuration from the base `redis:7-alpine` image, which doesn't have the conditional password logic. You need to build and deploy the custom Redis image.

## Error Explanation

```
WARN[0000] The "REDIS_PASSWORD" variable is not set. Defaulting to a blank string.
```

This warning appears because `.env.production` is not being loaded properly by Docker Compose. The file exists but the variables aren't being read.

## Solution

Follow these steps **on the production server** (`kja2aro@traidnet:/opt/wificore`):

### Step 1: Update .env.production File

```bash
cd /opt/wificore

# Set Redis password
nano .env.production
```

Find the line with `REDIS_PASSWORD=` and change it to:
```
REDIS_PASSWORD=Redis#$ts_2026
```

Or use sed:
```bash
sed -i 's/^REDIS_PASSWORD=.*/REDIS_PASSWORD=Redis#$ts_2026/' .env.production
```

Verify it's set:
```bash
grep REDIS_PASSWORD .env.production
# Should show: REDIS_PASSWORD=Redis#$ts_2026
```

### Step 2: Verify All Required Environment Variables

Check that these critical variables are set in `.env.production`:

```bash
# Check all critical variables
grep -E "^(DB_DATABASE|DB_USERNAME|DB_PASSWORD|PUSHER_APP_ID|PUSHER_APP_KEY|PUSHER_APP_SECRET|APP_URL|APP_KEY|SANCTUM_STATEFUL_DOMAINS|RADIUS_SECRET|API_BASE_URL)=" .env.production
```

If any are missing or empty, set them now.

### Step 3: Pull Latest Code

```bash
cd /opt/wificore
git pull origin main
```

### Step 4: Build Custom Redis Image

The custom Redis image includes the conditional password logic:

```bash
docker compose -f docker-compose.production.yml build wificore-redis
```

This will:
- Use the `docker/redis/Dockerfile`
- Copy `redis-entrypoint.sh` (handles conditional password)
- Copy `redis-healthcheck.sh` (handles conditional auth check)

### Step 5: Stop All Containers

```bash
docker compose -f docker-compose.production.yml down
```

### Step 6: Start All Services

```bash
docker compose -f docker-compose.production.yml up -d
```

### Step 7: Verify Redis is Running

```bash
# Test Redis with password
docker exec wificore-redis redis-cli -a "Redis#\$ts_2026" --no-auth-warning ping
# Should return: PONG

# Check Redis logs
docker compose -f docker-compose.production.yml logs wificore-redis --tail=50
# Should NOT show any "FATAL CONFIG FILE ERROR"
```

### Step 8: Check All Services

```bash
docker compose -f docker-compose.production.yml ps
```

All services should show `(healthy)` status.

### Step 9: Test Backend Redis Connection

```bash
docker exec wificore-backend php artisan tinker --execute="Redis::ping();"
# Should return: "PONG"

# Clear and rebuild cache
docker exec wificore-backend php artisan config:clear
docker exec wificore-backend php artisan cache:clear
docker exec wificore-backend php artisan config:cache
```

## Automated Deployment

Alternatively, use the deployment script:

```bash
cd /opt/wificore
chmod +x deploy-redis-fix.sh
./deploy-redis-fix.sh
```

## Troubleshooting

### Issue: Environment variables still not loading

**Check if .env.production is being read:**
```bash
docker compose -f docker-compose.production.yml config | grep REDIS_PASSWORD
```

If it shows empty, the file isn't being loaded. Verify:
```bash
ls -la .env.production
cat .env.production | grep REDIS_PASSWORD
```

### Issue: Redis still shows "wrong number of arguments"

This means the old image is still being used. Force rebuild:
```bash
docker compose -f docker-compose.production.yml build --no-cache wificore-redis
docker compose -f docker-compose.production.yml up -d --force-recreate wificore-redis
```

### Issue: Custom Redis image not found

Build it manually:
```bash
cd /opt/wificore
docker build -t kja2aro/wificore:wificore-redis ./docker/redis
docker compose -f docker-compose.production.yml up -d
```

### Issue: Permission denied on scripts

The entrypoint and healthcheck scripts need execute permissions:
```bash
chmod +x docker/redis/redis-entrypoint.sh
chmod +x docker/redis/redis-healthcheck.sh
docker compose -f docker-compose.production.yml build wificore-redis
```

## Verification Checklist

- [ ] `.env.production` has `REDIS_PASSWORD=Redis#$ts_2026`
- [ ] All required environment variables are set
- [ ] Latest code pulled from repository
- [ ] Custom Redis image built successfully
- [ ] All containers started without errors
- [ ] Redis responds to ping with password
- [ ] Backend can connect to Redis
- [ ] All services show healthy status
- [ ] Tenant registration works end-to-end

## What Changed

### Before (Broken)
```yaml
wificore-redis:
  image: redis:7-alpine
  command: redis-server --requirepass ${REDIS_PASSWORD} --maxmemory 512mb ...
```

When `REDIS_PASSWORD` is empty → `redis-server --requirepass --maxmemory 512mb` → Error!

### After (Fixed)
```yaml
wificore-redis:
  build: ./docker/redis
  # Uses custom entrypoint that conditionally adds --requirepass
```

The entrypoint script checks if password is set:
```bash
if [ -n "$REDIS_PASSWORD" ]; then
    redis-server --requirepass $REDIS_PASSWORD --maxmemory 512mb ...
else
    redis-server --maxmemory 512mb ...
fi
```

## Security Notes

- Redis password: `Redis#$ts_2026`
- Password is only used within Docker network
- Redis port (6379) is NOT exposed to host
- Backend connects using password authentication
- Healthcheck uses password authentication

## Next Steps After Deployment

1. **Test tenant registration:**
   - Go to https://wificore.traidsolutions.com/register
   - Register a new tenant
   - Verify email verification works
   - Check workspace creation completes

2. **Monitor logs:**
   ```bash
   docker compose -f docker-compose.production.yml logs -f
   ```

3. **Check Redis memory usage:**
   ```bash
   docker exec wificore-redis redis-cli -a "Redis#\$ts_2026" --no-auth-warning info memory
   ```

4. **Verify broadcasting works:**
   - Check Soketi logs for WebSocket connections
   - Test real-time updates in the UI

## Rollback Plan

If issues occur:

```bash
# Stop everything
docker compose -f docker-compose.production.yml down

# Remove custom Redis image
docker rmi kja2aro/wificore:wificore-redis

# Use standard Redis without password
# Edit docker-compose.production.yml temporarily:
# Change wificore-redis to use: image: redis:7-alpine
# Remove build section

# Set REDIS_PASSWORD to empty
sed -i 's/^REDIS_PASSWORD=.*/REDIS_PASSWORD=/' .env.production

# Start services
docker compose -f docker-compose.production.yml up -d
```

## Support

If deployment fails, collect these logs:

```bash
# All service logs
docker compose -f docker-compose.production.yml logs > deployment-logs.txt

# Environment check
docker compose -f docker-compose.production.yml config > resolved-config.yml

# Service status
docker compose -f docker-compose.production.yml ps > service-status.txt
```
