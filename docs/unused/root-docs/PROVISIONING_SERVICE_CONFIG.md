# Provisioning Service Configuration Guide

## Environment Variables

Add these to your `.env.production` file:

```env
# ============================================================================
# PROVISIONING SERVICE (Network Segmentation)
# ============================================================================

# Provisioning service URL (internal Docker network)
PROVISIONING_SERVICE_URL=http://wificore-provisioning:8080

# Provisioning service timeout (seconds)
PROVISIONING_SERVICE_TIMEOUT=30

# Enable provisioning service (default: false for safety)
# Set to true to enable network segmentation
USE_PROVISIONING_SERVICE=false

# Gradual rollout configuration
# Options:
#   - Leave empty: Disabled (use direct SSH)
#   - Specific UUIDs: router-uuid-1,router-uuid-2,router-uuid-3
#   - "all": Enable for all routers
PROVISIONING_SERVICE_ROUTERS=

# Debug logging for provisioning service
PROVISIONING_DEBUG=false
```

## Gradual Rollout Steps

### Step 1: Test with Single Router (Recommended)

1. Get a router UUID from database:
```bash
docker compose -f docker-compose.production.yml exec wificore-backend \
  php artisan tinker --execute="echo App\Models\Router::first()->id;"
```

2. Update `.env.production`:
```env
USE_PROVISIONING_SERVICE=true
PROVISIONING_SERVICE_ROUTERS=<router-uuid-from-step-1>
```

3. Restart backend:
```bash
docker compose -f docker-compose.production.yml restart wificore-backend
```

4. Test router operations:
   - View router details in UI
   - Check connectivity
   - Fetch live data
   - Monitor logs for "via provisioning service" messages

5. Check logs:
```bash
# Backend logs
docker logs -f wificore-backend | grep -i "provisioning service"

# Provisioning service logs
docker logs -f wificore-provisioning
```

### Step 2: Enable for 10% of Routers

1. Get 10% of router UUIDs:
```bash
docker compose -f docker-compose.production.yml exec wificore-backend \
  php artisan tinker --execute="echo App\Models\Router::limit(ceil(App\Models\Router::count() * 0.1))->pluck('id')->implode(',');"
```

2. Update `.env.production`:
```env
PROVISIONING_SERVICE_ROUTERS=<comma-separated-uuids>
```

3. Restart and monitor for 24 hours

### Step 3: Enable for 50% of Routers

Repeat Step 2 with 50% of routers, monitor for 48 hours

### Step 4: Enable for All Routers

```env
PROVISIONING_SERVICE_ROUTERS=all
```

Monitor for 1 week before proceeding to network isolation

### Step 5: Apply Network Isolation (After Validation)

Only proceed after confirming all features work correctly.

## Monitoring

### Check if Provisioning Service is Being Used

```bash
# Backend logs - should show "via provisioning service"
docker logs wificore-backend --tail 100 | grep "provisioning service"

# Provisioning service logs - should show HTTP requests
docker logs wificore-provisioning --tail 100
```

### Health Check

```bash
# From backend container
docker exec wificore-backend curl http://wificore-provisioning:8080/health

# Expected response:
# {"status":"healthy","timestamp":"...","version":"1.0.0","uptime_seconds":...}
```

### Metrics

```bash
# Prometheus metrics
docker exec wificore-backend curl http://wificore-provisioning:8080/metrics
```

## Rollback

If issues occur, immediately disable:

```env
USE_PROVISIONING_SERVICE=false
```

Then restart backend:
```bash
docker compose -f docker-compose.production.yml restart wificore-backend
```

All operations will fall back to direct SSH automatically.

## Troubleshooting

### Provisioning Service Not Responding

```bash
# Check service status
docker compose -f docker-compose.production.yml ps wificore-provisioning

# Check logs
docker logs wificore-provisioning --tail 100

# Restart if needed
docker compose -f docker-compose.production.yml restart wificore-provisioning
```

### Backend Can't Reach Provisioning Service

```bash
# Test connectivity
docker exec wificore-backend ping -c 3 wificore-provisioning

# Test HTTP
docker exec wificore-backend curl -v http://wificore-provisioning:8080/health

# Check network
docker network inspect wificore-network | grep -A 5 wificore-provisioning
```

### Router Operations Failing

Check logs for fallback messages:
```bash
docker logs wificore-backend | grep "falling back to direct SSH"
```

If you see fallback messages, the provisioning service is having issues but operations continue via direct SSH.

## Success Criteria

Before proceeding to next phase:

- [ ] Single router test successful (24 hours)
- [ ] 10% rollout successful (24 hours)
- [ ] 50% rollout successful (48 hours)
- [ ] All routers successful (1 week)
- [ ] No fallback to direct SSH in logs
- [ ] All features working correctly
- [ ] Performance acceptable (<100ms API response)
- [ ] No errors in provisioning service logs

## Next Phase: Network Isolation

Only after all success criteria are met, proceed to Phase 4: Network Isolation (firewall rules).
