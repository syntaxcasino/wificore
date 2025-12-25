# Redis Configuration Fix - Production Deployment

## Issue
Redis container fails to start with error:
```
*** FATAL CONFIG FILE ERROR (Redis 7.4.7) ***
Reading the configuration file, at line 2
>>> 'requirepass "--maxmemory" "512mb"'
wrong number of arguments
```

## Root Cause
When `REDIS_PASSWORD` is empty in `.env.production`, the Redis command becomes:
```bash
redis-server --requirepass --maxmemory 512mb
```

This causes Redis to interpret `--maxmemory` as the password value, resulting in a configuration error.

## Solution
Remove the `--requirepass ${REDIS_PASSWORD}` argument from the Redis command when no password is configured.

## Deployment Steps

### Step 1: Stop All Containers
```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml down
```

### Step 2: Update docker-compose.production.yml

Edit the Redis service configuration in `/opt/wificore/docker-compose.production.yml`:

**Find this section (around line 221-237):**
```yaml
  wificore-redis:
    image: redis:7-alpine
    container_name: wificore-redis
    command: redis-server --requirepass ${REDIS_PASSWORD} --maxmemory ${REDIS_MAXMEMORY:-512mb} --maxmemory-policy allkeys-lru --save 60 1000 --appendonly yes
    restart: always
    env_file:
      - .env.production
    environment:
      - TZ=${TZ:-Africa/Nairobi}
    networks:
      - wificore-network
    healthcheck:
      test: ["CMD", "redis-cli", "--no-auth-warning", "-a", "${REDIS_PASSWORD}", "ping"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s
```

**Replace with:**
```yaml
  wificore-redis:
    image: redis:7-alpine
    container_name: wificore-redis
    command: redis-server --maxmemory ${REDIS_MAXMEMORY:-512mb} --maxmemory-policy allkeys-lru --save 60 1000 --appendonly yes
    restart: always
    env_file:
      - .env.production
    environment:
      - TZ=${TZ:-Africa/Nairobi}
    networks:
      - wificore-network
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 30s
      timeout: 10s
      retries: 3
      start_period: 30s
```

**Changes made:**
1. **Line 224**: Removed `--requirepass ${REDIS_PASSWORD}` from command
2. **Line 233**: Changed healthcheck from `["CMD", "redis-cli", "--no-auth-warning", "-a", "${REDIS_PASSWORD}", "ping"]` to `["CMD", "redis-cli", "ping"]`

### Step 3: Verify .env.production

Ensure Redis password is set correctly in `/opt/wificore/.env.production`:

```bash
grep REDIS_PASSWORD /opt/wificore/.env.production
```

Should show:
```
REDIS_PASSWORD=
```

**NOT:**
```
REDIS_PASSWORD=null
```

If it shows `null`, update it:
```bash
sed -i 's/REDIS_PASSWORD=null/REDIS_PASSWORD=/' /opt/wificore/.env.production
```

### Step 4: Start Containers
```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml up -d
```

### Step 5: Verify Redis is Running
```bash
# Check container status
docker compose -f docker-compose.production.yml ps wificore-redis

# Check Redis logs
docker compose -f docker-compose.production.yml logs wificore-redis --tail=50

# Test Redis connection
docker exec wificore-redis redis-cli ping
# Should return: PONG
```

### Step 6: Verify Backend Connection
```bash
# Check backend can connect to Redis
docker compose -f docker-compose.production.yml logs wificore-backend --tail=100 | grep -i redis

# Test from backend container
docker exec wificore-backend php artisan tinker --execute="Redis::ping();"
# Should return: "PONG"
```

## Quick Fix Script

You can use this script to apply all fixes automatically:

```bash
#!/bin/bash
cd /opt/wificore

echo "Stopping containers..."
docker compose -f docker-compose.production.yml down

echo "Backing up docker-compose.production.yml..."
cp docker-compose.production.yml docker-compose.production.yml.backup

echo "Fixing Redis configuration..."
sed -i 's/command: redis-server --requirepass ${REDIS_PASSWORD} --maxmemory/command: redis-server --maxmemory/' docker-compose.production.yml
sed -i 's/test: \["CMD", "redis-cli", "--no-auth-warning", "-a", "${REDIS_PASSWORD}", "ping"\]/test: ["CMD", "redis-cli", "ping"]/' docker-compose.production.yml

echo "Fixing .env.production Redis password..."
sed -i 's/REDIS_PASSWORD=null/REDIS_PASSWORD=/' .env.production

echo "Starting containers..."
docker compose -f docker-compose.production.yml up -d

echo "Waiting for services to be healthy..."
sleep 30

echo "Checking Redis status..."
docker exec wificore-redis redis-cli ping

echo "Done! Check logs with: docker compose -f docker-compose.production.yml logs -f"
```

Save this as `/opt/wificore/fix-redis.sh` and run:
```bash
chmod +x /opt/wificore/fix-redis.sh
./fix-redis.sh
```

## Verification

### 1. All containers should be healthy:
```bash
docker compose -f docker-compose.production.yml ps
```

Expected output - all services should show `(healthy)` status.

### 2. No Redis errors in logs:
```bash
docker compose -f docker-compose.production.yml logs wificore-redis | grep -i error
```

Should return nothing.

### 3. Backend can use Redis:
```bash
docker exec wificore-backend php artisan cache:clear
docker exec wificore-backend php artisan config:cache
```

Should complete without errors.

### 4. Test tenant registration:
Navigate to `https://wificore.traidsolutions.com/register` and test the registration flow.

## Rollback

If issues occur, restore the backup:
```bash
cd /opt/wificore
docker compose -f docker-compose.production.yml down
cp docker-compose.production.yml.backup docker-compose.production.yml
docker compose -f docker-compose.production.yml up -d
```

## Alternative: Use Redis with Password

If you prefer to use Redis with password authentication:

1. Set a strong password in `.env.production`:
```bash
REDIS_PASSWORD=your_secure_redis_password_here
```

2. Keep the original Redis configuration in `docker-compose.production.yml` (with `--requirepass`)

3. Restart containers:
```bash
docker compose -f docker-compose.production.yml restart wificore-redis wificore-backend
```

## Notes

- **No password** is acceptable for Redis in a Docker internal network since it's not exposed externally
- Redis is only accessible from within the `wificore-network` Docker network
- If Redis needs to be exposed externally, **always use a password**
- The current setup is secure for internal container-to-container communication

## Troubleshooting

### Issue: Redis still fails to start
**Check:**
```bash
docker compose -f docker-compose.production.yml logs wificore-redis
```

Look for specific error messages.

### Issue: Backend can't connect to Redis
**Check:**
1. Redis is running: `docker ps | grep redis`
2. Network connectivity: `docker exec wificore-backend ping wificore-redis`
3. Backend configuration: `docker exec wificore-backend env | grep REDIS`

### Issue: Environment variables not loading
**Check:**
```bash
docker compose -f docker-compose.production.yml config | grep -A 5 wificore-redis
```

This shows the resolved configuration with environment variables substituted.

## Security Recommendations

1. **Keep Redis internal**: Never expose Redis port (6379) to the host or internet
2. **Use password in production**: Consider setting `REDIS_PASSWORD` for defense in depth
3. **Monitor access**: Enable Redis logging and monitor for unusual activity
4. **Regular updates**: Keep Redis image updated (`redis:7-alpine`)
5. **Backup data**: Redis data is persisted in `wificore-redis-data` volume

## Related Files

- `/opt/wificore/docker-compose.production.yml` - Docker Compose configuration
- `/opt/wificore/.env.production` - Environment variables
- Backend Redis config: `/var/www/html/config/database.php` (in container)

## Support

If issues persist:
1. Check all logs: `docker compose -f docker-compose.production.yml logs`
2. Verify environment variables are loaded correctly
3. Test Redis independently: `docker run --rm redis:7-alpine redis-cli ping`
4. Contact support with full error logs
