# 🚀 CRITICAL FIX - Tenant Schema Context in fetchConfig

## ✅ ISSUE FIXED

### Problem: "routers table does not exist"

**Error:**
```
SQLSTATE[42P01]: Undefined table: 7 ERROR: relation "routers" does not exist
```

**Root Cause:**
- `fetchConfig` is a **public endpoint** (no authentication required)
- Router table exists in **tenant schema** (e.g., `ts_407a816ad0944946bd3ca81bb4110f14`)
- Query runs in **public schema** by default (no tenant context)
- Result: Table not found

**Why This Happened:**
1. Public endpoint has no authenticated user
2. No tenant context set by middleware
3. `Router::where('config_token', $configToken)` runs in public schema
4. Routers table doesn't exist in public schema

---

## 🔧 SOLUTION IMPLEMENTED

### Multi-Tenant Schema Search

The `fetchConfig` endpoint now:

1. **Gets all active tenants** from public schema
2. **Loops through each tenant schema**
3. **Sets search_path** to tenant schema
4. **Searches for router** by config_token
5. **When found**, keeps that schema context
6. **Fetches VPN config** and router config
7. **Always resets** to public schema

### Code Changes

```php
public function fetchConfig($configToken)
{
    try {
        // Get all active tenants
        $tenants = \App\Models\Tenant::where('is_active', true)->get();
        
        $router = null;
        $foundTenant = null;
        
        // Search for router in each tenant schema
        foreach ($tenants as $tenant) {
            try {
                // Set search path to tenant schema
                DB::statement("SET search_path TO {$tenant->schema_name}, public");
                
                // Try to find router in this tenant's schema
                $router = Router::where('config_token', $configToken)->first();
                
                if ($router) {
                    $foundTenant = $tenant;
                    break;
                }
            } catch (\Exception $e) {
                continue;
            }
        }
        
        // Reset to public schema
        DB::statement("SET search_path TO public");
        
        if (!$router || !$foundTenant) {
            return response('# ERROR: Configuration not found', 404)
                ->header('Content-Type', 'text/plain');
        }
        
        // Set correct tenant context for subsequent queries
        DB::statement("SET search_path TO {$foundTenant->schema_name}, public");
        
        // Get VPN configuration
        $vpnConfig = $router->vpnConfiguration;
        
        // ... generate and return configuration script ...
        
        // Reset to public schema
        DB::statement("SET search_path TO public");
        
        return response($completeScript, 200)
            ->header('Content-Type', 'text/plain; charset=utf-8');
            
    } catch (\Exception $e) {
        // Always reset to public schema on error
        DB::statement("SET search_path TO public");
        
        return response('# ERROR: Configuration not found', 404)
            ->header('Content-Type', 'text/plain');
    }
}
```

---

## 📦 DEPLOYMENT STEPS

### Step 1: Pull Latest Code

```bash
ssh root@144.91.71.208
cd /opt/wificore

# Pull latest code
git pull origin main

# Should show commit: 1fb65e9
git log --oneline -1
```

### Step 2: Rebuild and Restart

```bash
# Rebuild backend
docker compose -f docker-compose.production.yml build wificore-backend

# Restart services
docker compose -f docker-compose.production.yml down
docker compose -f docker-compose.production.yml up -d

# Wait for services to start
sleep 30
```

### Step 3: Clear Caches

```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan cache:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan config:clear
docker compose -f docker-compose.production.yml exec wificore-backend php artisan route:clear
```

---

## ✅ VERIFICATION TESTS

### Test 1: Get Config Token

```bash
# Get a valid config token from database
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

# In tinker:
$tenants = App\Models\Tenant::where('is_active', true)->get();
foreach ($tenants as $tenant) {
    DB::statement("SET search_path TO {$tenant->schema_name}, public");
    $router = App\Models\Router::first();
    if ($router) {
        echo "Tenant: {$tenant->slug}\n";
        echo "Router: {$router->name}\n";
        echo "Config Token: {$router->config_token}\n";
        echo "Fetch URL: " . config('app.url') . "/api/routers/{$router->config_token}/fetch-config\n";
        break;
    }
}
DB::statement("SET search_path TO public");
exit
```

### Test 2: Test Fetch Endpoint

```bash
# Use the config token from Test 1
CONFIG_TOKEN="your-config-token-here"

# Test the fetch endpoint
curl -k "https://wificore.traidsolutions.com/api/routers/$CONFIG_TOKEN/fetch-config"

# Expected: Returns plain text configuration script
# Should NOT return: "# ERROR: Configuration not found"
```

### Test 3: Check Logs

```bash
# Check for successful fetch
docker compose -f docker-compose.production.yml logs --tail=50 wificore-backend | grep "Router configuration fetched"

# Should show:
# Router found in tenant schema
# Router configuration fetched successfully

# Check for errors
docker compose -f docker-compose.production.yml logs --tail=50 wificore-backend | grep "routers table does not exist"

# Should show: NO RESULTS (error is fixed)
```

### Test 4: Test on MikroTik

```routeros
# Copy the provisioning command from UI
/tool fetch mode=https url="https://wificore.traidsolutions.com/api/routers/{TOKEN}/fetch-config" dst-path=config.rsc; :delay 2s; /import config.rsc

# Expected output:
#   status: finished
#   downloaded: XXX bytes
#   
# Importing configuration from config.rsc...
# (configuration commands execute)
```

---

## 🔍 TROUBLESHOOTING

### Issue: Still getting "routers table does not exist"

**Check if code was deployed:**
```bash
cd /opt/wificore
git log --oneline -1

# Should show: 1fb65e9 FIX: Tenant schema context in fetchConfig endpoint
```

**Check if container was rebuilt:**
```bash
docker compose -f docker-compose.production.yml ps

# Check "Created" timestamp - should be recent
```

**Restart services:**
```bash
docker compose -f docker-compose.production.yml restart wificore-backend
```

### Issue: "Configuration not found" (404)

**Verify config token is valid:**
```bash
docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

# Check all tenants for the router
$tenants = App\Models\Tenant::all();
foreach ($tenants as $tenant) {
    DB::statement("SET search_path TO {$tenant->schema_name}, public");
    $router = App\Models\Router::where('config_token', 'YOUR-TOKEN')->first();
    if ($router) {
        echo "Found in tenant: {$tenant->slug}\n";
        echo "Router: {$router->name}\n";
    }
}
DB::statement("SET search_path TO public");
exit
```

### Issue: Tenant schema doesn't exist

**Check tenant schemas:**
```bash
docker compose -f docker-compose.production.yml exec wificore-postgres psql -U wificore -d wificore

# In psql:
\dn

# Should show tenant schemas like: ts_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

# If missing, create schema:
\q

docker compose -f docker-compose.production.yml exec wificore-backend php artisan tinker

$tenant = App\Models\Tenant::where('slug', 'YOUR-SLUG')->first();
$manager = app(App\Services\TenantMigrationManager::class);
$manager->setupTenantSchema($tenant);
exit
```

---

## 📊 MONITORING

### Watch for Errors

```bash
# Monitor logs in real-time
docker compose -f docker-compose.production.yml logs -f wificore-backend | grep -E "(routers table|Router configuration fetched|Router found in tenant)"
```

### Check Success Rate

```bash
# Count successful fetches
docker compose -f docker-compose.production.yml logs wificore-backend | grep "Router configuration fetched successfully" | wc -l

# Count errors
docker compose -f docker-compose.production.yml logs wificore-backend | grep "Failed to fetch router config" | wc -l
```

---

## 🎯 EXPECTED BEHAVIOR AFTER FIX

### Before Fix:
```
[2026-01-04 03:48:12] production.ERROR: Failed to fetch router config 
{"config_token":"f91beb4a-292a-4c7f-99f8-801e09f613db",
"error":"SQLSTATE[42P01]: Undefined table: 7 ERROR: relation \"routers\" does not exist"}
```

### After Fix:
```
[2026-01-04 XX:XX:XX] production.INFO: Router found in tenant schema
{"tenant_id":"407a816a-d094-4946-bd3c-a81bb4110f14",
"tenant_slug":"tenant-slug",
"schema_name":"ts_407a816ad0944946bd3ca81bb4110f14",
"router_id":"2178d9af-0944-4c4e-9f42-7fdc907f88dc",
"config_token":"f91beb4a-292a-4c7f-99f8-801e09f613db"}

[2026-01-04 XX:XX:XX] production.INFO: Router configuration fetched successfully
{"tenant_id":"407a816a-d094-4946-bd3c-a81bb4110f14",
"router_id":"2178d9af-0944-4c4e-9f42-7fdc907f88dc",
"router_name":"router-name",
"config_token":"f91beb4a-292a-4c7f-99f8-801e09f613db"}
```

---

## 📝 COMMIT HISTORY

| Commit | Description |
|--------|-------------|
| `ff0a885` | Implement fetch-based deployment |
| `1fb65e9` | **Fix tenant schema context in fetchConfig** ✅ |

---

## ✅ POST-DEPLOYMENT CHECKLIST

- [ ] Code pulled (commit 1fb65e9)
- [ ] Backend container rebuilt
- [ ] Services restarted
- [ ] Caches cleared
- [ ] Fetch endpoint tested with valid token
- [ ] Returns plain text configuration (not JSON)
- [ ] No "routers table does not exist" errors in logs
- [ ] Logs show "Router found in tenant schema"
- [ ] Logs show "Router configuration fetched successfully"
- [ ] MikroTik /tool fetch command works
- [ ] Configuration imports successfully

---

**CRITICAL FIX READY FOR DEPLOYMENT**

Commit: `1fb65e9`

This fix resolves the "routers table does not exist" error by properly handling tenant schema context in the public fetchConfig endpoint.
