# Multi-Tenancy Phase 1 - Deployment Checklist

## Pre-Deployment

### 1. Code Review ✅
- [x] All files created and reviewed
- [x] No syntax errors
- [x] Follows Laravel conventions
- [x] Follows PostgreSQL best practices
- [x] Security validated (SQL injection prevention)
- [x] Error handling comprehensive

### 2. Documentation ✅
- [x] Implementation plan created
- [x] Phase 1 completion document
- [x] Review summary document
- [x] Quick start guide
- [x] All reference documentation in /docs

### 3. Backup Preparation
- [ ] Backup production database
- [ ] Backup .env file
- [ ] Backup docker-compose.yml
- [ ] Document current state
- [ ] Test restore procedure

---

## Deployment Steps

### Step 1: Pull Latest Code
```bash
cd d:\traidnet\wifi-hotspot
git status
git pull origin main
```

**Verify**:
- [ ] All new files present
- [ ] No merge conflicts
- [ ] Git status clean

---

### Step 2: Review Changes
```bash
# Review migrations
ls backend/database/migrations/2025_11_30_*

# Review new services
ls backend/app/Services/Tenant*

# Review config
cat backend/config/multitenancy.php

# Review dictionary
cat freeradius/dictionary | grep Tenant-ID
```

**Verify**:
- [ ] 2 new migrations present
- [ ] TenantContext.php exists
- [ ] TenantSchemaManager.php exists
- [ ] multitenancy.php config exists
- [ ] Tenant-ID in dictionary

---

### Step 3: Run Migrations (Development First)
```bash
# Development environment
cd backend
php artisan migrate

# Or in Docker
docker exec traidnet-backend php artisan migrate --force
```

**Expected Output**:
```
Migrating: 2025_11_30_000001_add_schema_fields_to_tenants_table
Migrated:  2025_11_30_000001_add_schema_fields_to_tenants_table (XX.XXms)
Migrating: 2025_11_30_000002_create_radius_user_schema_mapping_table
Migrated:  2025_11_30_000002_create_radius_user_schema_mapping_table (XX.XXms)
```

**Verify**:
- [ ] Migrations ran successfully
- [ ] No errors in output
- [ ] Check database schema

---

### Step 4: Verify Database Schema
```sql
-- Connect to database
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

-- Check tenants table
\d tenants

-- Should show new columns:
-- schema_name, schema_created, schema_created_at

-- Check radius_user_schema_mapping table
\d radius_user_schema_mapping

-- Should exist with all columns

-- Check existing data
SELECT id, name, slug, schema_name, schema_created FROM tenants;

-- All existing tenants should have:
-- schema_name = 'tenant_{slug}'
-- schema_created = false

-- Exit
\q
```

**Verify**:
- [ ] Tenants table has new columns
- [ ] radius_user_schema_mapping table exists
- [ ] Existing tenants have schema_name populated
- [ ] schema_created = false for all existing tenants

---

### Step 5: Rebuild Containers
```bash
# Stop containers
docker-compose down

# Rebuild with new dictionary mount
docker-compose up -d --build

# Wait for services to start
sleep 30

# Check status
docker-compose ps
```

**Verify**:
- [ ] All containers running
- [ ] No errors in logs
- [ ] Health checks passing

---

### Step 6: Verify FreeRADIUS Dictionary
```bash
# Check dictionary file is mounted
docker exec traidnet-freeradius cat /opt/etc/raddb/dictionary | grep Tenant-ID

# Should output:
# ATTRIBUTE	Tenant-ID		3100	string

# Check FreeRADIUS logs
docker logs traidnet-freeradius --tail 50
```

**Verify**:
- [ ] Tenant-ID attribute present
- [ ] No errors in FreeRADIUS logs
- [ ] FreeRADIUS started successfully

---

### Step 7: Clear Application Caches
```bash
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan route:clear
docker exec traidnet-backend php artisan view:clear
```

**Verify**:
- [ ] All caches cleared
- [ ] No errors

---

### Step 8: Check Application Logs
```bash
# Backend logs
docker logs traidnet-backend --tail 100

# Look for:
# - No errors
# - Application started successfully
# - No migration errors

# Check Laravel logs
docker exec traidnet-backend tail -f storage/logs/laravel.log
```

**Verify**:
- [ ] No errors in logs
- [ ] Application running normally

---

## Post-Deployment Testing

### Test 1: System Admin Login
```bash
# Test login endpoint
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "admin",
    "password": "your_password"
  }'
```

**Verify**:
- [ ] Login successful
- [ ] Token returned
- [ ] No errors

---

### Test 2: Tenant Admin Login
```bash
# Test tenant admin login
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "tenant_admin",
    "password": "your_password"
  }'
```

**Verify**:
- [ ] Login successful
- [ ] Token returned
- [ ] Tenant context set
- [ ] No errors

---

### Test 3: Router Management
```bash
# Get routers (with auth token)
curl -X GET http://localhost/api/admin/routers \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Verify**:
- [ ] Routers returned
- [ ] Only tenant's routers
- [ ] No errors

---

### Test 4: Package Management
```bash
# Get packages
curl -X GET http://localhost/api/admin/packages \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Verify**:
- [ ] Packages returned
- [ ] Only tenant's packages
- [ ] No errors

---

### Test 5: Hotspot Users
```bash
# Get hotspot users
curl -X GET http://localhost/api/admin/hotspot-users \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Verify**:
- [ ] Users returned
- [ ] Only tenant's users
- [ ] No errors

---

### Test 6: RADIUS Authentication
```bash
# Test RADIUS auth
docker exec traidnet-freeradius radtest testuser testpass localhost 0 testing123
```

**Verify**:
- [ ] Authentication works
- [ ] Access-Accept or Access-Reject returned
- [ ] No errors in RADIUS logs

---

### Test 7: Database Queries
```sql
-- Connect to database
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

-- Check search_path (should be public by default)
SHOW search_path;

-- Check tenant data still accessible
SELECT COUNT(*) FROM routers;
SELECT COUNT(*) FROM packages;
SELECT COUNT(*) FROM hotspot_users;

-- All counts should match pre-deployment

-- Check new table
SELECT COUNT(*) FROM radius_user_schema_mapping;

-- Exit
\q
```

**Verify**:
- [ ] All data intact
- [ ] Counts match pre-deployment
- [ ] New table accessible

---

### Test 8: Real-Time Notifications
```bash
# Check Soketi
curl http://localhost:9601/

# Should return Soketi metrics
```

**Verify**:
- [ ] Soketi running
- [ ] Metrics accessible
- [ ] No errors

---

### Test 9: Queue Workers
```bash
# Check queue workers
docker exec traidnet-backend php artisan queue:work --once

# Should process any queued jobs
```

**Verify**:
- [ ] Queue workers running
- [ ] Jobs processing
- [ ] No errors

---

### Test 10: Frontend Access
```bash
# Open browser
# Navigate to: http://localhost

# Test:
# - Login page loads
# - Can login
# - Dashboard loads
# - Router management works
# - Package management works
```

**Verify**:
- [ ] Frontend loads
- [ ] Login works
- [ ] Dashboard accessible
- [ ] All features functional

---

## Performance Testing

### Test 1: Response Times
```bash
# Test API response times
time curl -X GET http://localhost/api/admin/routers \
  -H "Authorization: Bearer YOUR_TOKEN"
```

**Verify**:
- [ ] Response time < 500ms
- [ ] No degradation vs pre-deployment

---

### Test 2: Database Performance
```sql
-- Connect to database
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

-- Enable timing
\timing

-- Test queries
SELECT * FROM routers LIMIT 100;
SELECT * FROM packages LIMIT 100;
SELECT * FROM hotspot_users LIMIT 100;

-- Check execution times
```

**Verify**:
- [ ] Query times acceptable
- [ ] No performance degradation

---

### Test 3: Concurrent Requests
```bash
# Use Apache Bench or similar
ab -n 100 -c 10 http://localhost/api/health
```

**Verify**:
- [ ] All requests successful
- [ ] No errors
- [ ] Acceptable response times

---

## Monitoring

### Check Logs (First 24 Hours)
```bash
# Application logs
docker exec traidnet-backend tail -f storage/logs/laravel.log

# RADIUS logs
docker logs -f traidnet-freeradius

# PostgreSQL logs
docker logs -f traidnet-postgres

# Nginx logs
docker logs -f traidnet-nginx
```

**Monitor for**:
- [ ] No errors
- [ ] No warnings
- [ ] Normal operation

---

### Check Metrics
```bash
# System metrics
docker stats

# Database connections
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT count(*) FROM pg_stat_activity;"

# Redis info
docker exec traidnet-redis redis-cli INFO
```

**Verify**:
- [ ] CPU usage normal
- [ ] Memory usage normal
- [ ] Database connections normal
- [ ] Redis working

---

## Rollback Procedure (If Needed)

### Step 1: Stop Containers
```bash
docker-compose down
```

### Step 2: Rollback Migrations
```bash
docker exec traidnet-backend php artisan migrate:rollback --step=2
```

### Step 3: Restore Dictionary
```bash
# Edit freeradius/dictionary
# Remove Tenant-ID line

# Or restore from backup
cp freeradius/dictionary.backup freeradius/dictionary
```

### Step 4: Revert Code
```bash
git revert HEAD~9..HEAD
# Or
git reset --hard PREVIOUS_COMMIT
```

### Step 5: Restart Containers
```bash
docker-compose up -d
```

### Step 6: Verify Rollback
```bash
# Check migrations
docker exec traidnet-backend php artisan migrate:status

# Check application
curl http://localhost/api/health
```

---

## Success Criteria

### All Tests Pass ✅
- [ ] All pre-deployment checks complete
- [ ] All deployment steps successful
- [ ] All post-deployment tests pass
- [ ] All performance tests acceptable
- [ ] No errors in logs
- [ ] All features functional

### Production Ready
- [ ] Tested in development
- [ ] Tested in staging (if available)
- [ ] Team notified
- [ ] Documentation updated
- [ ] Rollback procedure tested

---

## Sign-Off

### Deployment Team
- [ ] Developer: _________________ Date: _______
- [ ] QA: _________________ Date: _______
- [ ] DevOps: _________________ Date: _______
- [ ] Product Owner: _________________ Date: _______

### Notes
```
Add any deployment notes here:
- Issues encountered:
- Resolutions applied:
- Performance observations:
- Recommendations:
```

---

## Next Steps

After successful deployment:
1. Monitor for 24-48 hours
2. Collect performance metrics
3. Gather user feedback
4. Plan Phase 2 implementation
5. Schedule Phase 2 deployment

---

**Checklist Version**: 1.0  
**Created**: November 30, 2025  
**Phase**: 1 - Foundation  
**Status**: Ready for Deployment
