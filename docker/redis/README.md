# Redis Custom Docker Image

This directory contains a custom Redis Docker image that supports conditional password authentication.

## Features

- **Conditional Password Authentication**: Only applies `requirepass` when `REDIS_PASSWORD` is set
- **Configurable Memory Limit**: Set via `REDIS_MAXMEMORY` environment variable
- **Smart Healthcheck**: Automatically detects if password is required
- **Production Ready**: Includes persistence (AOF + RDB snapshots)

## Files

- `Dockerfile` - Custom Redis image definition
- `redis-entrypoint.sh` - Entrypoint script that builds Redis command conditionally
- `redis-healthcheck.sh` - Healthcheck script that works with or without password

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `REDIS_PASSWORD` | _(empty)_ | Redis password. If empty, no authentication required |
| `REDIS_MAXMEMORY` | `512mb` | Maximum memory Redis can use |

## How It Works

### Entrypoint Script

The entrypoint script checks if `REDIS_PASSWORD` is set:

```bash
if [ -n "$REDIS_PASSWORD" ]; then
    # Password is set - add requirepass
    redis-server --requirepass $REDIS_PASSWORD --maxmemory 512mb ...
else
    # No password - skip requirepass
    redis-server --maxmemory 512mb ...
fi
```

This prevents the error where an empty password causes Redis to interpret the next argument as the password value.

### Healthcheck Script

The healthcheck script also conditionally uses authentication:

```bash
if [ -n "$REDIS_PASSWORD" ]; then
    redis-cli -a "$REDIS_PASSWORD" ping
else
    redis-cli ping
fi
```

## Usage

### Without Password (Default)

```yaml
wificore-redis:
  build:
    context: ./docker/redis
  environment:
    - REDIS_PASSWORD=
    - REDIS_MAXMEMORY=512mb
```

### With Password

```yaml
wificore-redis:
  build:
    context: ./docker/redis
  environment:
    - REDIS_PASSWORD=your_secure_password
    - REDIS_MAXMEMORY=512mb
```

## Building the Image

```bash
# Build locally
docker build -t kja2aro/wificore:wificore-redis ./docker/redis

# Build with docker-compose
docker-compose build wificore-redis
```

## Testing

### Test without password:
```bash
docker run --rm -e REDIS_PASSWORD= -e REDIS_MAXMEMORY=256mb kja2aro/wificore:wificore-redis &
sleep 2
docker exec $(docker ps -q -f ancestor=kja2aro/wificore:wificore-redis) redis-cli ping
# Should return: PONG
```

### Test with password:
```bash
docker run --rm -e REDIS_PASSWORD=testpass -e REDIS_MAXMEMORY=256mb kja2aro/wificore:wificore-redis &
sleep 2
docker exec $(docker ps -q -f ancestor=kja2aro/wificore:wificore-redis) redis-cli -a testpass ping
# Should return: PONG
```

## Configuration

The Redis instance is configured with:

- **Memory Policy**: `allkeys-lru` (evict least recently used keys when memory limit reached)
- **Persistence**: 
  - RDB snapshots every 60 seconds if 1000+ keys changed
  - AOF (Append Only File) enabled for durability
- **Data Directory**: `/data` (mount as volume for persistence)

## Security Recommendations

### Development
- No password is acceptable for local development
- Redis is only accessible within Docker network

### Production
- **Always set a strong password** using `REDIS_PASSWORD`
- Never expose Redis port (6379) to the host or internet
- Use Docker secrets for password management
- Enable Redis ACLs for fine-grained access control
- Monitor Redis logs for unauthorized access attempts

## Troubleshooting

### Issue: Redis fails to start with "wrong number of arguments"

**Cause**: `REDIS_PASSWORD` is set to the string `"null"` instead of being empty.

**Fix**: Set `REDIS_PASSWORD=` (empty) or a real password value.

### Issue: Healthcheck fails

**Check**:
```bash
docker exec wificore-redis /usr/local/bin/redis-healthcheck.sh
```

If it returns `PONG`, healthcheck is working.

### Issue: Can't connect from backend

**Check**:
1. Redis is running: `docker ps | grep redis`
2. Network connectivity: `docker exec wificore-backend ping wificore-redis`
3. Password matches: Check `REDIS_PASSWORD` in both services
4. Test connection: `docker exec wificore-backend redis-cli -h wificore-redis ping`

## Performance Tuning

For production with high load:

```yaml
environment:
  - REDIS_MAXMEMORY=2gb  # Increase memory limit
  - REDIS_MAXMEMORY_POLICY=allkeys-lru  # Or volatile-lru, allkeys-lfu, etc.
```

Consider Redis Cluster for horizontal scaling if needed.

## Monitoring

Check Redis stats:
```bash
docker exec wificore-redis redis-cli info stats
docker exec wificore-redis redis-cli info memory
```

## Backup

Redis data is persisted in the `wificore-redis-data` volume:

```bash
# Backup
docker run --rm -v wificore-redis-data:/data -v $(pwd):/backup alpine tar czf /backup/redis-backup.tar.gz /data

# Restore
docker run --rm -v wificore-redis-data:/data -v $(pwd):/backup alpine tar xzf /backup/redis-backup.tar.gz -C /
```

## Related Documentation

- [Redis Configuration](https://redis.io/docs/management/config/)
- [Redis Security](https://redis.io/docs/management/security/)
- [Redis Persistence](https://redis.io/docs/management/persistence/)
