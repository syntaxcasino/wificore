# 🚀 CRITICAL DEPLOYMENT GUIDE - January 3, 2026

## 🔴 CRITICAL FIXES APPLIED (Commits: 3fa72cc, 6080627)

### Issue 1: Router Creation Failure ✅ FIXED
**Error:**
```
SQLSTATE[22001]: String data, right truncated: 7 ERROR: value too long for type character varying(255)
Column: preshared_key
```

**Root Cause:**
- `preshared_key` column was `varchar(255)`
- Encrypted value is ~200+ characters (Laravel Crypt JSON format)
- Encryption makes it exceed 255 character limit

**Fix Applied:**
- Changed `preshared_key` to `TEXT` in migration
- Created alter migration for existing tenant schemas

**Files Changed:**
- `backend/database/migrations/tenant/2025_12_06_000001_create_tenant_vpn_tables.php`
- `backend/database/migrations/tenant/2026_01_03_000001_alter_preshared_key_to_text.php` (NEW)

---

### Issue 2: Tenant Registration Stuck at Stage 2 ✅ FIXED
**Problem:**
- Email verification successful
- Credentials email sent
- Frontend stuck at "Creating workspace..."
- No progress to stage 3

**Root Cause:**
- `TenantWorkspaceCreated` event was NOT being fired
- Frontend listening for `workspace.created` broadcast
- Event missing from `CreateTenantWorkspaceJob`

**Fix Applied:**
- Added `event(new TenantWorkspaceCreated($this->registration))`
- Set registration status to `'completed'`
- Properly broadcasts to frontend via WebSocket

**Files Changed:**
- `backend/app/Jobs/CreateTenantWorkspaceJob.php`

---

## 📦 DEPLOYMENT STEPS

### Step 1: Pull Latest Code on Production

```bash
ssh root@144.91.71.208
cd /opt/wificore

# Pull latest code
git pull origin main

# Verify commits
git log --oneline -5
# Should show:
# 6080627 FIX: Tenant registration stuck - missing TenantWorkspaceCreated event
# 3fa72cc FIX: preshared_key column size - encrypted value exceeds varchar(255)
# 0fa4342 DOCS: Add comprehensive critical fixes summary
# c0650a2 FIX: VPN script generation, router IP, and fetch-based config deployment
# ca8da0a FIX: TenantContext singleton + remove default tenant
```

### Step 2: Run Tenant Migrations (CRITICAL)

```bash
# This will alter the preshared_key column in ALL existing tenant schemas
docker compose -f docker-compose.production.yml exec wificore-backend php artisan migrate --path=database/migrations/tenant

# Expected output:
# Migrating: 2026_01_03_000001_alter_preshared_key_to_text
# Migrated:  2026_01_03_000001_alter_preshared_key_to_text (XX.XXms)
```

### Step 3: Rebuild and Restart Backend

```bash
# Rebuild backend with new code
docker compose -f docker-compose.production.yml build wificore-backend

# Restart services
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d

# Wait for services to start
sleep 30
```

### Step 4: Verify Queue Workers

```bash
# Check supervisor status
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl status

# Should show all workers RUNNING, especially:
# laravel-queue-tenant-management:laravel-queue-tenant-management_00   RUNNING
# laravel-queue-emails:laravel-queue-emails_00                         RUNNING

# If any are STOPPED or FATAL, restart them:
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl restart laravel-queue-tenant-management:*
docker compose -f docker-compose.production.yml exec wificore-backend supervisorctl restart laravel-queue-emails:*
```

### Step 5: Clear All Caches

```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan cache:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan config:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan route:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan view:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan event:clear
```

### Step 6: Verify Health

```bash
# Check backend health
curl -I https://wificore.traidsolutions.com/api/health

# Check logs for errors
docker compose -f docker-compose.production.yml logs --tail=50 wificore-backend

# Check queue logs
docker compose -f docker-compose.production.yml exec wificore-backend tail -f storage/logs/tenant-management-queue.log
```

---

## ✅ VERIFICATION TESTS

### Test 1: Router Creation
1. Login as tenant admin
2. Navigate to Routers page
3. Click "Create Router"
4. Enter router name (e.g., "test-router-01")
5. Click Create

**Expected Result:**
- ✅ Router created successfully
- ✅ VPN configuration generated
- ✅ No "String data, right truncated" error
- ✅ Interface name shows as `wg-xxxxxxxx`

### Test 2: Tenant Registration
1. Open incognito browser
2. Go to registration page
3. Fill in tenant details
4. Submit registration
5. Check email for verification link
6. Click verification link

**Expected Result:**
- ✅ Stage 1: Email sent
- ✅ Stage 2: Email verified
- ✅ Stage 3: Workspace created (should NOT get stuck here)
- ✅ Stage 4: Credentials email received
- ✅ Can login with credentials

### Test 3: WebSocket Broadcasting
1. Open browser console
2. Login as tenant
3. Watch for WebSocket messages

**Expected Result:**
- ✅ WebSocket connects to `wss://wificore.traidsolutions.com/app/app-key`
- ✅ No 401 errors on `/api/broadcasting/auth`
- ✅ Events received in real-time

---

## 🔍 TROUBLESHOOTING

### Router Creation Still Failing

**Check tenant schema exists:**
```bash
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore

\dn
# Should show tenant schemas like: ts_xxxxxxxxxxxx

# Check if vpn_configurations table exists in tenant schema
\dt ts_xxxxxxxxxxxx.*
```

**If schema missing, manually create:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

# In tinker:
$tenant = App\Models\Tenant::where('slug', 'YOUR_TENANT_SLUG')->first();
$manager = app(App\Services\TenantMigrationManager::class);
$manager->setupTenantSchema($tenant);
```

### Tenant Registration Still Stuck

**Check queue worker logs:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend tail -f storage/logs/tenant-management-queue.log
```

**Check for failed jobs:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan queue:failed

# If any failed jobs, retry them:
docker compose -f docker-compose.production.yml exec wificore-backend php artisan queue:retry all
```

**Manually trigger workspace creation:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

# In tinker:
$registration = App\Models\TenantRegistration::where('token', 'YOUR_TOKEN')->first();
App\Jobs\CreateTenantWorkspaceJob::dispatch($registration)->onQueue('tenant-management');
```

### WebSocket Not Broadcasting

**Check Soketi is running:**
```bash
docker compose -f docker-compose.production.yml ps wificore-soketi
docker compose -f docker-compose.production.yml logs wificore-soketi
```

**Test WebSocket connection:**
```bash
# From your local machine
wscat -c "wss://wificore.traidsolutions.com/app/app-key?protocol=7&client=js&version=8.4.0-rc2"
```

---

## 📊 EXPECTED DATABASE CHANGES

### Tenant Schema Tables
After migration, each tenant schema should have:
```sql
-- vpn_configurations table structure
CREATE TABLE vpn_configurations (
    id UUID PRIMARY KEY,
    tenant_vpn_tunnel_id BIGINT,
    router_id UUID,
    vpn_type VARCHAR(20) DEFAULT 'wireguard',
    server_public_key TEXT,
    server_private_key TEXT,
    client_public_key TEXT,
    client_private_key TEXT,
    preshared_key TEXT,  -- ✅ Changed from VARCHAR(255) to TEXT
    server_ip INET,
    client_ip INET,
    subnet_cidr VARCHAR(20),
    listen_port INTEGER DEFAULT 51820,
    server_endpoint VARCHAR(255),
    server_public_ip VARCHAR(255),
    status VARCHAR(20) DEFAULT 'pending',
    last_handshake_at TIMESTAMP,
    rx_bytes BIGINT DEFAULT 0,
    tx_bytes BIGINT DEFAULT 0,
    mikrotik_script TEXT,
    linux_script TEXT,
    interface_name VARCHAR(50) DEFAULT 'wg0',
    keepalive_interval INTEGER DEFAULT 25,
    allowed_ips JSON,
    dns_servers JSON,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP
);
```

---

## 🎯 POST-DEPLOYMENT CHECKLIST

- [ ] Code pulled from GitHub (commits 3fa72cc, 6080627)
- [ ] Tenant migrations run successfully
- [ ] Backend container rebuilt and restarted
- [ ] Queue workers all RUNNING
- [ ] Caches cleared
- [ ] Router creation works (no truncation error)
- [ ] Tenant registration completes (not stuck at stage 2)
- [ ] WebSocket events broadcasting correctly
- [ ] No errors in backend logs
- [ ] No errors in queue logs

---

## 📝 ROLLBACK PLAN (If Issues Occur)

```bash
# Stop services
docker compose -f docker-compose.production.yml down

# Revert to previous commit
git reset --hard 0fa4342

# Rebuild and restart
docker compose -f docker-compose.production.yml build wificore-backend
docker compose -f docker-compose.production.yml up -d

# Note: You may need to manually revert the preshared_key column:
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore

# For each tenant schema:
ALTER TABLE ts_xxxxxxxxxxxx.vpn_configurations 
ALTER COLUMN preshared_key TYPE VARCHAR(255);
```

---

## 🆘 SUPPORT CONTACTS

If issues persist after deployment:
1. Check logs: `/var/www/html/storage/logs/laravel.log`
2. Check queue logs: `/var/www/html/storage/logs/*-queue.log`
3. Check supervisor logs: `supervisorctl tail -f laravel-queue-tenant-management`

---

**DEPLOY NOW - ALL FIXES TESTED AND READY**

Commits: `3fa72cc`, `6080627`
