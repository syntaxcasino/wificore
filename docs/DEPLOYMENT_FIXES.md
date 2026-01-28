# Critical Production Deployment Fixes

## Overview
This document contains the steps to fix two critical production issues:
1. PostgreSQL 18 boolean type compatibility (blocking all operations)
2. Enable provisioning service for router operations

## Prerequisites
- SSH access to production server
- Git repository access
- Docker and docker-compose installed

---

## Fix 1: PostgreSQL 18 Boolean Type Compatibility (CRITICAL)

### Problem
Backend is completely broken with error:
```
SQLSTATE[42883]: operator does not exist: boolean = integer
```

This affects ALL database queries with boolean columns.

### Solution
Run the automated fix script on production:

```bash
# SSH to production server
ssh kja2aro@traidnet

# Navigate to project directory
cd /opt/wificore

# Pull latest fixes
git pull origin main

# Run automated fix script
chmod +x scripts/fix-postgresql18-boolean-issue.sh
./scripts/fix-postgresql18-boolean-issue.sh
```

### What the Script Does
1. Stops backend container safely
2. Updates `database.php` (disables PDO emulated prepares)
3. Updates PgBouncer configs (switches to session mode)
4. Restarts PgBouncer services
5. Starts backend
6. Verifies boolean queries work

### Expected Output
```
✓ Database config updated (PDO::ATTR_EMULATE_PREPARES => false)
✓ PgBouncer config updated (pool_mode = session)
✓ PgBouncer services restarted
✓ Backend is healthy
✓ Boolean queries working correctly

Fix Applied Successfully!
```

### Verification
```bash
# Monitor logs - should see no more boolean errors
docker logs -f wificore-backend

# Test queries
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker
```

In tinker:
```php
App\Models\Tenant::where('is_active', true)->count();
App\Models\Router::where('is_enabled', true)->count();
```

Both should return results without errors.

---

## Fix 2: Enable Provisioning Service

### Problem
Router provisioning is still using the backend container instead of the dedicated Go-based provisioning microservice.

### Solution

#### Step 1: Update Environment Variables

Edit `/opt/wificore/.env.production` and add these lines at the end (after line 384):

```bash
# Enable the Go-based provisioning microservice for all router operations
USE_PROVISIONING_SERVICE=true
PROVISIONING_SERVICE_URL=http://wificore-provisioning:8080
PROVISIONING_SERVICE_API_KEY=49WJYUjm6ILD9cyiQu701Ko7XEbEUcsccGnqYJDk6UQ=
# Comma-separated list of router IDs to use provisioning service (empty = all routers)
PROVISIONING_SERVICE_ROUTERS=
```

#### Step 2: Restart Services

```bash
cd /opt/wificore

# Restart backend to pick up new environment variables
docker compose -f docker-compose.production.yml restart wificore-backend

# Verify provisioning service is running
docker compose -f docker-compose.production.yml ps wificore-provisioning

# Check provisioning service health
curl -s http://localhost:8080/health | jq
```

Expected health response:
```json
{
  "status": "healthy",
  "timestamp": "2026-01-28T15:30:00Z",
  "version": "1.0.0"
}
```

#### Step 3: Verify Provisioning Routes Through Service

```bash
# Check backend logs for provisioning service usage
docker compose -f docker-compose.production.yml logs -f wificore-backend | grep -i provisioning

# Check provisioning service logs
docker compose -f docker-compose.production.yml logs -f wificore-provisioning
```

When you provision a router, you should see:
- Backend logs: "Using provisioning service for router X"
- Provisioning service logs: "POST /api/provision" requests

### Rollback (if needed)

If provisioning service causes issues, disable it:

```bash
# Edit .env.production
USE_PROVISIONING_SERVICE=false

# Restart backend
docker compose -f docker-compose.production.yml restart wificore-backend
```

---

## Troubleshooting

### Boolean Errors Still Occurring

**Symptom**: Still seeing `boolean = integer` errors after running fix script

**Solution**:
```bash
# Verify database.php was updated in container
docker compose -f docker-compose.production.yml exec wificore-backend \
  grep -A 2 "ATTR_EMULATE_PREPARES" /var/www/html/config/database.php

# Should show: PDO::ATTR_EMULATE_PREPARES => false

# Verify PgBouncer is in session mode
docker compose -f docker-compose.production.yml exec wificore-pgbouncer \
  grep "pool_mode" /etc/pgbouncer/pgbouncer.ini

# Should show: pool_mode = session

# If not updated, manually copy files:
docker cp backend/config/database.php wificore-backend:/var/www/html/config/database.php
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer:/etc/pgbouncer/pgbouncer.ini
docker cp pgbouncer/pgbouncer.ini wificore-pgbouncer-read:/etc/pgbouncer/pgbouncer.ini

# Restart services
docker compose -f docker-compose.production.yml restart wificore-pgbouncer wificore-pgbouncer-read wificore-backend
```

### Provisioning Service Not Responding

**Symptom**: Router provisioning fails or times out

**Solution**:
```bash
# Check if container is running
docker compose -f docker-compose.production.yml ps wificore-provisioning

# Check logs for errors
docker compose -f docker-compose.production.yml logs --tail 100 wificore-provisioning

# Restart provisioning service
docker compose -f docker-compose.production.yml restart wificore-provisioning

# Verify network connectivity from backend
docker compose -f docker-compose.production.yml exec wificore-backend \
  curl -v http://wificore-provisioning:8080/health
```

### Backend Can't Connect to Provisioning Service

**Symptom**: Backend logs show "Connection refused" to provisioning service

**Solution**:
```bash
# Verify both containers are on same network
docker network inspect wificore_wificore-network

# Should show both wificore-backend and wificore-provisioning

# Check provisioning service IP
docker inspect wificore-provisioning | grep IPAddress

# Test connectivity
docker compose -f docker-compose.production.yml exec wificore-backend \
  ping -c 3 wificore-provisioning
```

---

## Post-Deployment Verification Checklist

- [ ] Backend starts without boolean errors
- [ ] All queue workers running without errors
- [ ] Provisioning service container is running
- [ ] Provisioning service health endpoint responds
- [ ] Backend can connect to provisioning service
- [ ] Router provisioning works end-to-end
- [ ] No errors in backend logs
- [ ] No errors in provisioning service logs

---

## Monitoring

### Key Log Files
```bash
# Backend
docker compose -f docker-compose.production.yml logs -f wificore-backend

# Provisioning Service
docker compose -f docker-compose.production.yml logs -f wificore-provisioning

# PgBouncer
docker compose -f docker-compose.production.yml logs -f wificore-pgbouncer

# PostgreSQL
docker compose -f docker-compose.production.yml logs -f wificore-postgres
```

### Key Metrics
- Backend startup time: < 30 seconds
- Boolean query success rate: 100%
- Provisioning service response time: < 5 seconds
- PgBouncer connection pool utilization: < 80%

---

## Support

If issues persist after following this guide:

1. Collect logs:
   ```bash
   docker compose -f docker-compose.production.yml logs --tail 500 > deployment-logs.txt
   ```

2. Check container status:
   ```bash
   docker compose -f docker-compose.production.yml ps > container-status.txt
   ```

3. Review with development team

---

## Change Log

- **2026-01-28**: Initial deployment guide created
  - PostgreSQL 18 boolean fix documented
  - Provisioning service enablement documented
