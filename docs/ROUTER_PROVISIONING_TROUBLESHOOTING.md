# Router Provisioning Troubleshooting Guide

## Critical Issues and Solutions

### Issue 1: Password Decryption Failed

**Symptoms:**
```
[production.ERROR: Password decryption failed: {"error":""}
```
- Router shows as "offline" even though it's actually online
- `FetchRouterLiveData` job fails with empty error message
- All API operations to the router fail

**Root Cause:**
The `APP_KEY` in `.env.production` doesn't match the key that was used to encrypt router passwords when they were created.

**Solution:**

1. **Check current APP_KEY:**
   ```bash
   cd /opt/wificore
   grep APP_KEY .env.production
   ```

2. **If APP_KEY was changed recently:**
   - **Option A (Recommended):** Restore the original `APP_KEY` if you have it backed up
   - **Option B:** Re-encrypt all router passwords with the new key (requires database migration)

3. **To re-encrypt passwords (if original key is lost):**
   ```bash
   # This requires a custom artisan command to be created
   php artisan router:reencrypt-passwords --force
   ```

**Prevention:**
- Never change `APP_KEY` in production without a migration plan
- Always backup `.env.production` before making changes
- Keep `.env` and `.env.production` in sync as per user rules

---

### Issue 2: Stream Timeout / Concurrent API Access

**Symptoms:**
```
[production.WARNING: Connectivity verification failed: {"error":"Stream timed out"}
```
- Router flips between "online" and "offline" status
- Multiple timeout errors in logs
- Jobs taking 30+ seconds to complete

**Root Cause:**
Multiple jobs trying to access the same router simultaneously:
- `CheckRoutersJob` (runs every minute)
- `FetchRouterLiveData` (runs every 30 seconds)
- MikroTik API can't handle concurrent connections well

**Solution (Already Implemented):**
- Jobs now check if router is locked before attempting access
- Increased lock acquisition timeout from 10s to 15-20s
- Jobs skip routers that are busy (503 error)
- Better error handling for concurrent access

**Manual Fix (if still occurring):**
1. Check Redis is running properly:
   ```bash
   docker compose -f docker-compose.production.yml ps redis
   ```

2. Clear stuck locks:
   ```bash
   docker compose -f docker-compose.production.yml exec redis redis-cli
   KEYS router_api_lock_*
   DEL router_api_lock_<router-id>
   ```

---

### Issue 3: Router Stuck at 40% Progress

**Symptoms:**
- VPN connectivity verified ✅
- Interfaces discovered ✅
- Router stays in "pending" status
- No further progress after interface discovery

**Root Cause:**
The workflow was incomplete - after interface discovery, no automatic status update occurred.

**Solution (Already Implemented):**
- `DiscoverRouterInterfacesJob` now updates router status to "online" after successful interface discovery
- Router provisioning workflow now completes automatically
- Frontend receives proper status updates via WebSocket

**Expected Workflow:**
1. Router created → `pending` (0%)
2. VPN verified → `pending` (20%)
3. Interfaces discovered → `online` (100%) ✅ **COMPLETE**
4. User can now configure services via UI

---

### Issue 4: WebSocket Authentication Failures

**Symptoms:**
```
[production.WARNING: Broadcasting auth failed - no authenticated user
```
- Real-time updates don't work
- Frontend shows stale data
- Router status doesn't update in UI

**Root Cause:**
Frontend trying to connect to WebSocket channels before authentication completes or with invalid tokens.

**Solution:**
- Ensure user is fully authenticated before connecting to WebSocket
- Frontend should handle reconnection with fresh tokens
- Check Soketi is running properly:
  ```bash
  docker compose -f docker-compose.production.yml ps soketi
  ```

---

## Provisioning Workflow

### Complete Router Registration Flow

```
1. User creates router via UI
   ↓
2. Backend creates router record (status: pending)
   ↓
3. VPN configuration generated
   ↓
4. VerifyVpnConnectivityJob dispatched
   ↓
5. VPN connectivity verified (waits up to 120s)
   ↓
6. DiscoverRouterInterfacesJob dispatched
   ↓
7. Router interfaces discovered
   ↓
8. Router status updated to 'online' ✅
   ↓
9. User configures services (Hotspot/PPPoE) via UI
```

### Job Coordination

**Jobs that access router API:**
- `VerifyVpnConnectivityJob` - During initial provisioning
- `DiscoverRouterInterfacesJob` - After VPN verification
- `CheckRoutersJob` - Every minute (health checks)
- `FetchRouterLiveData` - Every 30 seconds (monitoring)

**Conflict Prevention:**
- All jobs use Redis locks: `router_api_lock_{router_id}`
- Jobs skip routers with status: `pending`, `deploying`, `provisioning`, `verifying`
- Jobs skip routers that are currently locked
- 503 errors are treated as "busy" and don't mark router as offline

---

## Monitoring and Debugging

### Check Router Status
```bash
# View recent logs
docker compose -f docker-compose.production.yml exec wificore-backend tail -f storage/logs/laravel.log

# Filter for specific router
docker compose -f docker-compose.production.yml exec wificore-backend cat storage/logs/laravel.log | grep "router_id\":\"<router-id>"

# Check job queue
docker compose -f docker-compose.production.yml exec wificore-backend php artisan queue:work --once
```

### Check Redis Locks
```bash
# Connect to Redis
docker compose -f docker-compose.production.yml exec redis redis-cli

# List all router locks
KEYS router_api_lock_*

# Check specific lock
GET router_api_lock_<router-id>
TTL router_api_lock_<router-id>

# Clear stuck lock
DEL router_api_lock_<router-id>
```

### Check Queue Workers
```bash
# Check supervisor status
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl status

# Restart queue workers
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl restart all
```

---

## Performance Optimization

### Reduce API Timeouts

1. **Ensure router has good network connectivity**
2. **Reduce concurrent job frequency:**
   - Edit `app/Console/Kernel.php`
   - Adjust schedule intervals for `CheckRoutersJob` and `FetchRouterLiveData`

3. **Increase MikroTik API timeout:**
   - Already set to 15-20 seconds in `MikrotikProvisioningService`
   - Can be increased further if needed

### Scale Queue Workers

If you have many routers, increase queue workers:

```bash
# Edit backend/supervisor/queue-worker.conf
[program:queue-worker]
numprocs=8  # Increase from 4 to 8
```

Then rebuild containers:
```bash
./deploy.sh
```

---

## Common Error Messages

| Error | Cause | Solution |
|-------|-------|----------|
| `Password decryption failed` | APP_KEY mismatch | Restore original APP_KEY or re-encrypt passwords |
| `Stream timed out` | Concurrent API access | Wait for lock to clear, or clear manually |
| `Router is busy` | Another job accessing router | Normal - job will retry automatically |
| `Failed to acquire lock` | Lock timeout | Increase lock timeout in code |
| `Broadcasting auth failed` | WebSocket auth issue | Check user session and Soketi status |

---

## Best Practices

1. **Never change APP_KEY in production** without a migration plan
2. **Keep .env and .env.production in sync** (user rule #17)
3. **Monitor logs regularly** for early detection of issues
4. **Test provisioning flow** after any code changes
5. **Backup configuration** before making changes
6. **Use git bash** for all operations (user rule #15)
7. **Always commit changes** to remote repo (user rule #16)

---

## Emergency Recovery

### If provisioning is completely broken:

1. **Stop all queue workers:**
   ```bash
   docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl stop all
   ```

2. **Clear all locks:**
   ```bash
   docker compose -f docker-compose.production.yml exec redis redis-cli FLUSHDB
   ```

3. **Restart services:**
   ```bash
   docker compose -f docker-compose.production.yml restart wificore-backend redis
   ```

4. **Start queue workers:**
   ```bash
   docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl start all
   ```

---

## Contact Information

For further assistance, check:
- Laravel logs: `backend/storage/logs/laravel.log`
- Docker logs: `docker compose -f docker-compose.production.yml logs -f`
- System logs: `/var/log/syslog`
