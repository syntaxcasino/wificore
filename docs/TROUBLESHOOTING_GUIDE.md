# WiFi Hotspot Management System - Troubleshooting Guide

## Table of Contents
1. [System Architecture](#system-architecture)
2. [Common Issues and Solutions](#common-issues-and-solutions)
3. [Testing Commands](#testing-commands)
4. [Database Troubleshooting](#database-troubleshooting)
5. [RADIUS Authentication Issues](#radius-authentication-issues)
6. [Frontend API Issues](#frontend-api-issues)
7. [Docker Container Issues](#docker-container-issues)
8. [Complete System Restart Procedure](#complete-system-restart-procedure)

---

## System Architecture

### Components
- **Frontend**: Vue.js (Vite) - Port 80 (via Nginx)
- **Backend**: Laravel 12 (PHP 8.4) - Port 9000 (PHP-FPM)
- **Database**: PostgreSQL 16 - Port 5432
- **RADIUS**: FreeRADIUS - Ports 1812 (auth), 1813 (acct)
- **WebSocket**: Soketi - Port 6001
- **Reverse Proxy**: Nginx - Port 80

### Data Flow
```
Browser → Nginx → Frontend (Vue.js)
        ↓
        → Backend (Laravel) → RADIUS (FreeRADIUS) → PostgreSQL
        ↓
        → PostgreSQL (users, tokens)
```

---

## Common Issues and Solutions

### Issue 1: 403 Forbidden on API Calls

**Symptoms:**
```
POST http://localhost/api/login 403 (Forbidden)
```

**Cause:** Nginx configuration blocking API routes

**Solution:**
Check `nginx/nginx.conf` - ensure `/api` location block forwards to backend:
```nginx
location /api {
    set $backend_upstream traidnet-backend:9000;
    include fastcgi_params;
    fastcgi_pass $backend_upstream;
    fastcgi_index index.php;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
    # ... other params
}
```

**Test:**
```powershell
Invoke-WebRequest -Uri "http://localhost/api/packages" -Method GET -UseBasicParsing
```

---

### Issue 2: Double `/api` in URLs

**Symptoms:**
```
POST http://localhost/api/api/login 404
```

**Cause:** Incorrect `VITE_API_BASE_URL` configuration

**Solution:**
1. Set base URL in `frontend/.env`:
   ```
   VITE_API_BASE_URL=http://localhost/api
   ```

2. Use relative paths in composables (no `/api` prefix):
   ```javascript
   // ✅ Correct
   axios.post('login', data)
   
   // ❌ Wrong
   axios.post('/api/login', data)
   ```

3. Update public endpoints in `frontend/src/main.js`:
   ```javascript
   const publicEndpoints = ['login', 'packages', 'payments/initiate', 'mpesa/callback']
   ```

4. Rebuild frontend:
   ```powershell
   docker compose build traidnet-frontend
   docker compose restart traidnet-frontend
   ```

---

### Issue 3: RADIUS Authentication Failing

**Symptoms:**
```
(0) ERROR: No Auth-Type found: rejecting the user
(0) [sql] = notfound
```

**Diagnosis Steps:**

1. **Check if FreeRADIUS is listening:**
   ```powershell
   docker logs traidnet-freeradius --tail 20
   # Should see: "Listening on auth address * port 1812"
   ```

2. **Check if SQL module is loaded:**
   ```powershell
   docker logs traidnet-freeradius | Select-String -Pattern "Instantiating module.*sql"
   ```

3. **Check if user exists in database:**
   ```powershell
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM radcheck WHERE username='admin';"
   ```

4. **Test RADIUS authentication:**
   ```powershell
   # Check FreeRADIUS logs during login attempt
   docker logs traidnet-freeradius --tail 100 | Select-String -Pattern "admin|Access-Accept|Access-Reject"
   ```

**Common Causes:**

#### A. SQL User Name Not Set
**Symptom:** Query shows `WHERE username = ''`

**Fix:** Add to `freeradius/sql`:
```
sql_user_name = "%{User-Name}"
```

#### B. Column Name Case Sensitivity
**Symptom:** `column "Username" does not exist`

**Fix:** Use lowercase column names in `freeradius/queries.conf`:
```sql
authorize_check_query = "\
    SELECT id, username, attribute, value, op \
    FROM ${authcheck_table} \
    WHERE username = '%{SQL-User-Name}' \
    ORDER BY id"
```

#### C. SQL Module Not Enabled
**Symptom:** SQL queries not executing

**Fix:** In `freeradius/default` site config, ensure SQL is enabled:
```
authorize {
    # ...
    sql  # NOT -sql
    # ...
}
```

#### D. Queries File Not Included
**Fix:** Add to `freeradius/sql`:
```
$INCLUDE ${modconfdir}/${.:name}/main/${dialect}/queries.conf
```

---

### Issue 4: Laravel Backend Errors

**Symptoms:**
```
500 Internal Server Error
Authentication service error: ...
```

**Diagnosis:**

1. **Check Laravel logs:**
   ```powershell
   docker exec traidnet-backend tail -50 /var/www/html/storage/logs/laravel.log
   ```

2. **Check PHP-FPM status:**
   ```powershell
   docker logs traidnet-backend --tail 20
   ```

3. **Get detailed error:**
   ```powershell
   try { 
       Invoke-WebRequest -Uri "http://localhost/api/login" -Method POST -Body (@{username="admin";password="admin123"} | ConvertTo-Json) -ContentType "application/json" -UseBasicParsing 
   } catch { 
       $_.Exception.Response.GetResponseStream() | ForEach-Object { 
           $reader = New-Object System.IO.StreamReader($_); 
           $content = $reader.ReadToEnd(); 
           ($content | ConvertFrom-Json).message 
       } 
   }
   ```

**Common Errors:**

#### A. Missing `username` Column
**Error:** `column "username" does not exist`

**Fix:**
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "ALTER TABLE users ADD COLUMN IF NOT EXISTS username VARCHAR(255) UNIQUE;"
```

#### B. Missing `HasApiTokens` Trait
**Error:** `Call to undefined method App\Models\User::createToken()`

**Fix:** Update `backend/app/Models/User.php`:
```php
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens;
    
    protected $fillable = [
        'name',
        'username',  // Add this
        'email',
        'password',
    ];
}
```

#### C. Missing `personal_access_tokens` Table
**Error:** `relation "personal_access_tokens" does not exist`

**Fix:**
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "CREATE TABLE IF NOT EXISTS personal_access_tokens (id BIGSERIAL PRIMARY KEY, tokenable_type VARCHAR(255) NOT NULL, tokenable_id BIGINT NOT NULL, name VARCHAR(255) NOT NULL, token VARCHAR(64) NOT NULL UNIQUE, abilities TEXT, last_used_at TIMESTAMP, expires_at TIMESTAMP, created_at TIMESTAMP, updated_at TIMESTAMP); CREATE INDEX IF NOT EXISTS personal_access_tokens_tokenable_type_tokenable_id_index ON personal_access_tokens(tokenable_type, tokenable_id);"
```

---

## Testing Commands

### 1. Test Packages Endpoint (Public)
```powershell
Invoke-WebRequest -Uri "http://localhost/api/packages" -Method GET -UseBasicParsing | Select-Object StatusCode, @{Name='ContentLength';Expression={$_.Content.Length}}
```

**Expected:** `StatusCode: 200`

---

### 2. Test Login (RADIUS Authentication)
```powershell
$response = Invoke-WebRequest -Uri "http://localhost/api/login" -Method POST -Body (@{username="admin";password="admin123"} | ConvertTo-Json) -ContentType "application/json" -UseBasicParsing
$json = $response.Content | ConvertFrom-Json
Write-Host "Status: $($response.StatusCode)"
Write-Host "Success: $($json.success)"
Write-Host "Token: $($json.token.Substring(0,50))..."
Write-Host "User: $($json.user.username)"
```

**Expected:**
```
Status: 200
Success: True
Token: 1|...
User: admin
```

---

### 3. Check All Container Health
```powershell
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

**Expected:** All containers should show `(healthy)`

---

### 4. Check FreeRADIUS Authentication Flow
```powershell
# Trigger login
Invoke-WebRequest -Uri "http://localhost/api/login" -Method POST -Body (@{username="admin";password="admin123"} | ConvertTo-Json) -ContentType "application/json" -UseBasicParsing 2>&1 | Out-Null

# Check RADIUS logs
docker logs traidnet-freeradius --tail 100 | Select-String -Pattern "admin|Access-Accept|Access-Reject|Cleartext-Password" -Context 2
```

**Expected to see:**
```
(0) sql: Cleartext-Password := "admin123"
(0) Sent Access-Accept
```

---

### 5. Test Database Connectivity
```powershell
# From backend to database
docker exec traidnet-backend php artisan db:show

# Direct database query
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT version();"
```

---

### 6. Check Nginx Routing
```powershell
# Check nginx logs
docker logs traidnet-nginx --tail 20

# Test direct backend access (should fail - not exposed)
curl http://localhost:9000
```

---

## Database Troubleshooting

### Check Database Tables
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"
```

### Check RADIUS Tables
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'rad%';"
```

### Check User Data
```powershell
# RADIUS users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT id, username, attribute, value FROM radcheck;"

# Laravel users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT id, username, name, email FROM users;"
```

### Check Sanctum Tokens
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT id, tokenable_id, name, abilities, created_at FROM personal_access_tokens;"
```

### Reset User (if needed)
```powershell
# Delete Laravel user
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM users WHERE username = 'admin';"

# Delete tokens
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "DELETE FROM personal_access_tokens WHERE tokenable_id = 1;"
```

---

## RADIUS Authentication Issues

### Debug RADIUS SQL Queries
```powershell
# Check what query is being executed
docker logs traidnet-freeradius --tail 200 | Select-String -Pattern "SELECT.*radcheck" -Context 2
```

### Test SQL Query Directly
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT id, username, attribute, value, op FROM radcheck WHERE username = 'admin' ORDER BY id;"
```

### Check FreeRADIUS Configuration
```powershell
# Check SQL module config
docker exec traidnet-freeradius cat /opt/etc/raddb/mods-enabled/sql | Select-Object -First 50

# Check queries config
docker exec traidnet-freeradius cat /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf | Select-Object -First 20

# Check default site config
docker exec traidnet-freeradius cat /opt/etc/raddb/sites-enabled/default | Select-String -Pattern "authorize|sql" -Context 3
```

### Restart FreeRADIUS
```powershell
docker compose restart traidnet-freeradius
Start-Sleep -Seconds 10
docker logs traidnet-freeradius --tail 10
```

---

## Frontend API Issues

### Check Frontend Build
```powershell
docker logs traidnet-frontend --tail 20
```

### Check Axios Configuration
```powershell
# View main.js config
docker exec traidnet-frontend cat /usr/share/nginx/html/assets/index-*.js | Select-String -Pattern "baseURL" -Context 2
```

### Rebuild Frontend
```powershell
docker compose build traidnet-frontend
docker compose restart traidnet-frontend
Start-Sleep -Seconds 5
# Hard refresh browser: Ctrl+Shift+R
```

### Check Browser Console
Open browser DevTools (F12) and check:
1. **Network tab** - See actual API calls and responses
2. **Console tab** - Check for JavaScript errors
3. **Application tab** - Check localStorage for tokens

---

## Docker Container Issues

### View Container Logs
```powershell
# All logs
docker logs traidnet-backend --tail 50
docker logs traidnet-frontend --tail 50
docker logs traidnet-freeradius --tail 50
docker logs traidnet-postgres --tail 50
docker logs traidnet-nginx --tail 50

# Follow logs in real-time
docker logs -f traidnet-backend
```

### Check Container Resources
```powershell
docker stats --no-stream
```

### Restart Specific Container
```powershell
docker compose restart traidnet-backend
docker compose restart traidnet-freeradius
```

### Rebuild Specific Container
```powershell
docker compose build traidnet-backend
docker compose up -d traidnet-backend
```

### Execute Commands in Container
```powershell
# Backend
docker exec -it traidnet-backend bash

# Database
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

# FreeRADIUS
docker exec -it traidnet-freeradius sh
```

---

## Complete System Restart Procedure

### 1. Stop All Containers
```powershell
docker compose down
```

### 2. (Optional) Clean Rebuild
```powershell
# Remove all images
docker compose down --rmi all

# Rebuild without cache
docker compose build --no-cache
```

### 3. Start All Services
```powershell
docker compose up -d
```

### 4. Wait for Health Checks
```powershell
Start-Sleep -Seconds 30
docker ps
```

### 5. Verify Each Component

#### A. Database
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT COUNT(*) FROM radcheck;"
```

#### B. FreeRADIUS
```powershell
docker logs traidnet-freeradius --tail 10 | Select-String -Pattern "Ready to process requests"
```

#### C. Backend
```powershell
docker logs traidnet-backend --tail 10 | Select-String -Pattern "php-fpm.*RUNNING"
```

#### D. Frontend
```powershell
Invoke-WebRequest -Uri "http://localhost" -Method GET -UseBasicParsing | Select-Object StatusCode
```

### 6. Run End-to-End Test
```powershell
# Test packages endpoint
Invoke-WebRequest -Uri "http://localhost/api/packages" -Method GET -UseBasicParsing | Select-Object StatusCode

# Test login
$response = Invoke-WebRequest -Uri "http://localhost/api/login" -Method POST -Body (@{username="admin";password="admin123"} | ConvertTo-Json) -ContentType "application/json" -UseBasicParsing
$json = $response.Content | ConvertFrom-Json
Write-Host "Login Success: $($json.success)"
Write-Host "Token Length: $($json.token.Length)"
```

---

## Performance Monitoring

### Check Database Connections
```powershell
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT count(*) FROM pg_stat_activity WHERE datname='wifi_hotspot';"
```

### Check Slow Queries
```powershell
docker exec traidnet-backend tail -100 /var/www/html/storage/logs/laravel.log | Select-String -Pattern "Slow query"
```

### Monitor Container Resources
```powershell
docker stats
```

---

## Useful SQL Queries

### Check All RADIUS Users
```sql
SELECT r.id, r.username, r.attribute, r.value, r.op
FROM radcheck r
ORDER BY r.username;
```

### Check User Sessions
```sql
SELECT username, nasipaddress, acctstarttime, acctsessiontime
FROM radacct
WHERE acctstoptime IS NULL
ORDER BY acctstarttime DESC;
```

### Check Failed Login Attempts
```sql
SELECT username, reply, authdate
FROM radpostauth
WHERE reply = 'Access-Reject'
ORDER BY authdate DESC
LIMIT 10;
```

### Check Active Tokens
```sql
SELECT 
    pat.id,
    pat.name,
    u.username,
    pat.abilities,
    pat.last_used_at,
    pat.created_at
FROM personal_access_tokens pat
JOIN users u ON u.id = pat.tokenable_id
WHERE pat.tokenable_type = 'App\Models\User'
ORDER BY pat.created_at DESC;
```

---

## Emergency Recovery

### If Everything Fails

1. **Stop all containers:**
   ```powershell
   docker compose down
   ```

2. **Remove volumes (⚠️ DELETES ALL DATA):**
   ```powershell
   docker compose down -v
   ```

3. **Rebuild everything:**
   ```powershell
   docker compose build --no-cache
   docker compose up -d
   ```

4. **Wait for initialization:**
   ```powershell
   Start-Sleep -Seconds 60
   ```

5. **Verify database initialized:**
   ```powershell
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"
   ```

6. **Add test user:**
   ```powershell
   docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "INSERT INTO radcheck (username, attribute, op, value) VALUES ('admin', 'Cleartext-Password', ':=', 'admin123') ON CONFLICT DO NOTHING;"
   ```

7. **Test login:**
   ```powershell
   Invoke-WebRequest -Uri "http://localhost/api/login" -Method POST -Body (@{username="admin";password="admin123"} | ConvertTo-Json) -ContentType "application/json" -UseBasicParsing
   ```

---

## Quick Reference

### Default Credentials
- **RADIUS User:** admin / admin123
- **Database:** admin / secret
- **Database Name:** wifi_hotspot

### Important Ports
- **80** - Nginx (HTTP)
- **1812** - FreeRADIUS (Auth)
- **1813** - FreeRADIUS (Acct)
- **5432** - PostgreSQL
- **6001** - Soketi (WebSocket)
- **9000** - PHP-FPM (Internal)

### Key Files
- `frontend/.env` - Frontend configuration
- `backend/.env` - Backend configuration
- `nginx/nginx.conf` - Nginx routing
- `freeradius/sql` - RADIUS SQL config
- `freeradius/queries.conf` - SQL queries
- `freeradius/default` - RADIUS site config
- `docker-compose.yml` - Container orchestration

---

## Contact & Support

For issues not covered in this guide:
1. Check Docker logs for all containers
2. Check Laravel logs: `/var/www/html/storage/logs/laravel.log`
3. Check FreeRADIUS logs: `docker logs traidnet-freeradius`
4. Check browser console for frontend errors

---

**Last Updated:** 2025-10-04
**Version:** 1.0
