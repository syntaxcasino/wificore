# Production Login Fix Deployment Guide

## Issue Summary
Login authentication failing due to RADIUS shared secret mismatch between application and FreeRADIUS server.

## Pre-Deployment Checklist
- [ ] Backup current `.env.production` file
- [ ] Verify FreeRADIUS is using `testing123` as shared secret
- [ ] Confirm user exists in RADIUS tables
- [ ] Have database access ready

## Deployment Steps

### Step 1: Update Environment Configuration on Production Server

SSH into the production server:
```bash
ssh kja2aro@traidnet
cd /opt/wificore
```

Backup current configuration:
```bash
cp .env.production .env.production.backup.$(date +%Y%m%d_%H%M%S)
```

Update the RADIUS_SECRET in `.env.production`:
```bash
# Edit the file
nano .env.production

# Find and update this line:
RADIUS_SECRET=testing123
```

Save and exit (Ctrl+X, Y, Enter)

### Step 2: Pull Latest Code Changes

```bash
# Pull latest changes from repository
git pull origin main

# Or if you need to stash local changes first:
git stash
git pull origin main
git stash pop
```

### Step 3: Verify User Exists in Database

Run the verification script:
```bash
docker-compose exec wificore-backend php artisan tinker < backend/database/scripts/verify_and_fix_user.php
```

This script will:
- Check if user exists in `users` table
- Verify schema mapping
- Check RADIUS tables (radcheck, radreply)
- Create missing user entry if needed
- Test RADIUS authentication

### Step 4: Restart Backend Service

```bash
# Restart the backend container to pick up new environment variables
docker-compose restart wificore-backend

# Verify it's running
docker-compose ps wificore-backend
```

### Step 5: Clear Application Cache

```bash
# Clear config cache
docker-compose exec wificore-backend php artisan config:clear

# Clear application cache
docker-compose exec wificore-backend php artisan cache:clear

# Verify RADIUS configuration
docker-compose exec wificore-backend sh -c "env | grep RADIUS"
```

Expected output:
```
RADIUS_SERVER_HOST=wificore-freeradius
RADIUS_SERVER_PORT=1812
RADIUS_SECRET=testing123
```

### Step 6: Test Login

#### Option A: Via Frontend
1. Open browser to `https://wificore.traidsolutions.com`
2. Try logging in with:
   - Username: `traidnetsolution`
   - Password: `0dt?h2*Wk?4KoP*E`

#### Option B: Via API (curl)
```bash
curl -X POST https://wificore.traidsolutions.com/api/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "traidnetsolution",
    "password": "0dt?h2*Wk?4KoP*E"
  }'
```

Expected response:
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": { ... },
    "token": "...",
    "dashboard_route": "/dashboard"
  }
}
```

### Step 7: Monitor Logs

Watch the logs during login attempt:
```bash
# Terminal 1: Backend logs
docker-compose logs -f wificore-backend

# Terminal 2: FreeRADIUS logs
docker-compose logs -f wificore-freeradius
```

**Success indicators in FreeRADIUS logs:**
- ✓ `Authentication successful for user: traidnetsolution`
- ✓ `Sent Access-Accept`
- ✗ NO "unprintable characters" warnings
- ✗ NO "password does not match" errors

## Verification Commands

### Check User in Database
```bash
docker exec wificore-postgres psql -U admin -d wms_770_ts -c \
  "SELECT id, username, email, role, is_active, tenant_id FROM users WHERE username = 'traidnetsolution';"
```

### Check RADIUS Credentials
```bash
docker exec wificore-postgres psql -U admin -d wms_770_ts -c \
  "SET search_path TO ts_2465bf5e1d12; \
   SELECT username, attribute, value FROM radcheck WHERE username = 'traidnetsolution';"
```

### Check Schema Mapping
```bash
docker exec wificore-postgres psql -U admin -d wms_770_ts -c \
  "SELECT username, schema_name, tenant_id, is_active FROM radius_user_schema_mapping WHERE username = 'traidnetsolution';"
```

### Test RADIUS Authentication Directly
```bash
docker-compose exec wificore-backend php artisan tinker

# In tinker:
$radius = app(\App\Services\RadiusService::class);
$result = $radius->authenticate('traidnetsolution', '0dt?h2*Wk?4KoP*E');
echo $result ? "SUCCESS" : "FAILED";
exit
```

## Troubleshooting

### Issue: Still getting "unprintable characters" error

**Solution**: RADIUS secret still mismatched
```bash
# Check application's RADIUS secret
docker-compose exec wificore-backend sh -c "env | grep RADIUS_SECRET"

# Check FreeRADIUS client configuration
docker-compose exec wificore-freeradius cat /opt/etc/raddb/clients.conf | grep secret

# They must match exactly
```

### Issue: User not found in database

**Solution**: Run the fix script
```bash
docker-compose exec wificore-backend php artisan tinker < backend/database/scripts/verify_and_fix_user.php
```

### Issue: Schema mapping missing

**Solution**: Create schema mapping manually
```bash
docker exec wificore-postgres psql -U admin -d wms_770_ts -c \
  "INSERT INTO radius_user_schema_mapping (username, schema_name, tenant_id, is_active, created_at, updated_at) 
   VALUES ('traidnetsolution', 'ts_2465bf5e1d12', '4cc86783-b4ea-4f5c-bd45-1d44fadae257', true, NOW(), NOW());"
```

### Issue: Container not picking up new environment

**Solution**: Full restart
```bash
docker-compose down
docker-compose up -d
```

## Rollback Procedure

If issues occur:
```bash
# Restore backup
cp .env.production.backup.YYYYMMDD_HHMMSS .env.production

# Restart services
docker-compose restart wificore-backend

# Clear cache
docker-compose exec wificore-backend php artisan config:clear
```

## Post-Deployment

### 1. Update Password (Recommended)
The current password `0dt?h2*Wk?4KoP*E` meets complexity requirements, but consider changing it:

```bash
# Via API
curl -X POST https://wificore.traidsolutions.com/api/change-password \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "0dt?h2*Wk?4KoP*E",
    "new_password": "NewSecure@Pass123",
    "new_password_confirmation": "NewSecure@Pass123"
  }'
```

### 2. Security Hardening (Future)
For production, change the RADIUS secret from `testing123` to a strong random value:

```bash
# Generate strong secret
openssl rand -base64 32

# Update in both:
# 1. .env.production → RADIUS_SECRET=<generated_secret>
# 2. FreeRADIUS clients.conf → secret = <generated_secret>

# Then restart both services
docker-compose restart wificore-backend wificore-freeradius
```

## Success Criteria
- [ ] Login succeeds with correct credentials
- [ ] Login fails with incorrect credentials
- [ ] No "unprintable characters" in FreeRADIUS logs
- [ ] User dashboard loads after login
- [ ] Token is generated and valid
- [ ] No errors in backend logs

## Support
If issues persist, check:
1. `docs/LOGIN_FIX.md` - Detailed technical explanation
2. Backend logs: `docker-compose logs wificore-backend`
3. FreeRADIUS logs: `docker-compose logs wificore-freeradius`
4. Database connectivity: `docker-compose ps wificore-postgres`
