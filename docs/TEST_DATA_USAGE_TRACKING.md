# 🧪 Testing Guide - Data Usage Tracking

**Purpose:** Verify end-to-end data usage tracking functionality  
**Date:** November 1, 2025

---

## 🎯 Quick Verification Commands

### **1. Check Migration Status**
```bash
docker exec traidnet-backend php artisan migrate:status | grep "data_used"
```
**Expected Output:**
```
✅ 2025_11_01_122125_add_data_used_to_user_sessions_table [2] Ran
```

---

### **2. Verify Database Schema**
```bash
docker exec traidnet-backend php artisan tinker --execute="
\$columns = DB::select('SELECT column_name FROM information_schema.columns WHERE table_name = \'user_sessions\' AND column_name LIKE \'%data%\'');
foreach(\$columns as \$col) { echo \$col->column_name . PHP_EOL; }
"
```
**Expected Output:**
```
data_used
data_upload
data_download
```

---

### **3. Check Backend Jobs**
```bash
docker logs traidnet-backend --tail 20 --since 30s | grep "update-dashboard-stats"
```
**Expected Output:**
```
✅ Running [update-dashboard-stats] ......... XX.XXms DONE
✅ No errors
```

---

### **4. Verify PostgreSQL Logs**
```bash
docker logs traidnet-postgres --tail 30 --since 60s | grep -i "error"
```
**Expected Output:**
```
✅ No "column does not exist" errors
✅ Clean logs
```

---

### **5. Test API Endpoint (After Login)**
```bash
# First, get auth token (replace with actual credentials)
TOKEN=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}' \
  | jq -r '.token')

# Then test dashboard stats endpoint
curl -s http://localhost/api/dashboard/stats \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.data | {data_usage, data_usage_upload, data_usage_download, monthly_data_usage, today_data_usage}'
```
**Expected Output:**
```json
{
  "data_usage": 0.00,
  "data_usage_upload": 0.00,
  "data_usage_download": 0.00,
  "monthly_data_usage": 0.00,
  "today_data_usage": 0.00
}
```

---

### **6. Monitor Real-Time Updates**
```bash
# Watch backend logs for job execution
docker logs -f traidnet-backend | grep "update-dashboard-stats"
```
**Expected Behavior:**
```
✅ Job runs every 5 seconds
✅ Completes in <50ms
✅ No errors
```

---

### **7. Check Container Health**
```bash
docker ps --filter name=traidnet --filter health=healthy --format "{{.Names}}: {{.Status}}"
```
**Expected Output:**
```
✅ traidnet-backend: Up X minutes (healthy)
✅ traidnet-frontend: Up X minutes (healthy)
✅ traidnet-postgres: Up X minutes (healthy)
✅ All containers healthy
```

---

## 🔬 Detailed Testing Scenarios

### **Scenario 1: Create Test Session with Data Usage**

```bash
docker exec traidnet-backend php artisan tinker --execute="
// Create a test user session with data usage
\$session = new App\Models\UserSession([
    'tenant_id' => App\Models\Tenant::first()->id,
    'voucher' => 'TEST-' . time(),
    'mac_address' => '00:11:22:33:44:55',
    'start_time' => now(),
    'end_time' => now()->addHours(1),
    'status' => 'active',
    'data_used' => 1073741824, // 1 GB in bytes
    'data_upload' => 536870912, // 512 MB
    'data_download' => 536870912, // 512 MB
]);
\$session->save();
echo 'Test session created with ID: ' . \$session->id . PHP_EOL;
echo 'Data usage: 1 GB' . PHP_EOL;
"
```

**Expected Result:**
- ✅ Session created successfully
- ✅ Data usage recorded
- ✅ Dashboard will show 1 GB on next update

---

### **Scenario 2: Verify Dashboard Stats Calculation**

```bash
docker exec traidnet-backend php artisan tinker --execute="
// Manually trigger dashboard stats calculation
\$job = new App\Jobs\UpdateDashboardStatsJob();
\$job->handle();
echo 'Dashboard stats updated' . PHP_EOL;

// Check cached stats
\$stats = Cache::get('dashboard_stats_global');
if (\$stats) {
    echo 'Total Data Usage: ' . \$stats['data_usage'] . ' GB' . PHP_EOL;
    echo 'Upload: ' . \$stats['data_usage_upload'] . ' GB' . PHP_EOL;
    echo 'Download: ' . \$stats['data_usage_download'] . ' GB' . PHP_EOL;
}
"
```

**Expected Output:**
```
✅ Dashboard stats updated
✅ Total Data Usage: X.XX GB
✅ Upload: X.XX GB
✅ Download: X.XX GB
```

---

### **Scenario 3: Test Tenant Isolation**

```bash
docker exec traidnet-backend php artisan tinker --execute="
// Get stats for specific tenant
\$tenant = App\Models\Tenant::first();
\$sessions = App\Models\UserSession::where('tenant_id', \$tenant->id)->get();
\$totalData = \$sessions->sum('data_used') / (1024 * 1024 * 1024);
echo 'Tenant: ' . \$tenant->name . PHP_EOL;
echo 'Total Sessions: ' . \$sessions->count() . PHP_EOL;
echo 'Total Data Usage: ' . round(\$totalData, 2) . ' GB' . PHP_EOL;
"
```

**Expected Result:**
- ✅ Shows only tenant-specific data
- ✅ No cross-tenant data leakage

---

### **Scenario 4: Test WebSocket Broadcasting**

**Frontend Console Test:**
```javascript
// Open browser console on dashboard page
// Check WebSocket connection
console.log('WebSocket connected:', window.Echo?.connector?.pusher?.connection?.state === 'connected');

// Listen for dashboard stats updates
window.Echo.private('tenant.YOUR_TENANT_ID.dashboard-stats')
  .listen('DashboardStatsUpdated', (event) => {
    console.log('Dashboard stats updated:', event);
    console.log('Data usage:', event.stats.data_usage, 'GB');
  });
```

**Expected Behavior:**
- ✅ WebSocket connected
- ✅ Receives updates every 5 seconds
- ✅ Data usage values present in event

---

### **Scenario 5: Performance Test**

```bash
# Monitor query performance
docker exec traidnet-backend php artisan tinker --execute="
\$start = microtime(true);
\$totalData = App\Models\UserSession::sum('data_used');
\$duration = (microtime(true) - \$start) * 1000;
echo 'Query executed in: ' . round(\$duration, 2) . ' ms' . PHP_EOL;
echo 'Total data: ' . round(\$totalData / (1024*1024*1024), 2) . ' GB' . PHP_EOL;
"
```

**Expected Performance:**
- ✅ Query time: <20ms (with indexes)
- ✅ No slow query warnings

---

## 📊 Frontend Testing

### **Test 1: Dashboard Display**

1. **Login to dashboard:** `http://localhost`
2. **Navigate to:** Dashboard page
3. **Verify display:**
   - ✅ Data Usage card visible
   - ✅ Shows value in GB/TB format
   - ✅ Updates every 5 seconds
   - ✅ No loading spinners or jank

### **Test 2: Real-Time Updates**

1. **Open dashboard in browser**
2. **Open browser DevTools → Console**
3. **Create test session** (using Scenario 1 above)
4. **Wait 5 seconds**
5. **Verify:**
   - ✅ Dashboard updates automatically
   - ✅ New data usage reflected
   - ✅ No page refresh needed

### **Test 3: WebSocket Connection**

1. **Open dashboard**
2. **Check connection indicator:**
   - ✅ Shows "Live Updates" with green badge
   - ✅ Pulsing animation active
3. **Check console:**
   - ✅ No WebSocket errors
   - ✅ "Dashboard stats updated via WebSocket" logs present

---

## 🐛 Troubleshooting Tests

### **Issue: Data not updating**

**Test:**
```bash
# Check if job is running
docker logs traidnet-backend --tail 50 | grep "update-dashboard-stats"

# Check cache
docker exec traidnet-backend php artisan tinker --execute="
echo 'Cache driver: ' . config('cache.default') . PHP_EOL;
echo 'Stats cached: ' . (Cache::has('dashboard_stats_global') ? 'Yes' : 'No') . PHP_EOL;
"
```

**Fix:**
```bash
# Clear cache and restart
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan queue:restart
```

---

### **Issue: WebSocket not connecting**

**Test:**
```bash
# Check Soketi status
docker logs traidnet-soketi --tail 30

# Test WebSocket endpoint
curl http://localhost:6001/
```

**Expected:** Soketi server info page

---

### **Issue: Database queries slow**

**Test:**
```bash
# Check if indexes exist
docker exec traidnet-backend php artisan tinker --execute="
\$indexes = DB::select('SELECT indexname FROM pg_indexes WHERE tablename = \'user_sessions\' AND indexname LIKE \'%data%\'');
foreach(\$indexes as \$idx) { echo \$idx->indexname . PHP_EOL; }
"
```

**Expected:**
```
✅ idx_user_sessions_data_used
✅ idx_user_sessions_tenant_data
```

---

## ✅ Acceptance Criteria Checklist

### **Database:**
- [ ] Migration ran successfully
- [ ] Columns added: data_used, data_upload, data_download
- [ ] Indexes created and active
- [ ] No schema errors in logs

### **Backend:**
- [ ] UpdateDashboardStatsJob runs every 5 seconds
- [ ] Calculates data usage correctly
- [ ] Tenant isolation working
- [ ] Broadcasting to correct channels
- [ ] No errors in logs

### **Frontend:**
- [ ] Dashboard displays data usage
- [ ] Real-time updates working
- [ ] WebSocket connected
- [ ] Smooth UI updates
- [ ] Proper formatting (GB/TB)

### **Performance:**
- [ ] Job completes in <50ms
- [ ] Database queries <20ms
- [ ] Cache hit rate >90%
- [ ] WebSocket latency <100ms

### **Security:**
- [ ] Tenant data isolated
- [ ] Authentication required
- [ ] No cross-tenant leakage
- [ ] Secure channels only

---

## 🎯 Success Metrics

### **Functional:**
- ✅ Data usage tracked per session
- ✅ Dashboard shows accurate data
- ✅ Updates in real-time
- ✅ Tenant isolation enforced

### **Performance:**
- ✅ Job execution: <50ms
- ✅ Query time: <20ms
- ✅ Update frequency: 5 seconds
- ✅ UI responsiveness: Smooth

### **Reliability:**
- ✅ No errors in logs
- ✅ All containers healthy
- ✅ Jobs running continuously
- ✅ WebSocket stable

---

## 📝 Test Report Template

```markdown
## Test Execution Report

**Date:** [Date]
**Tester:** [Name]
**Environment:** [Production/Staging/Development]

### Test Results:

| Test Case | Status | Notes |
|-----------|--------|-------|
| Migration Status | ✅/❌ | |
| Database Schema | ✅/❌ | |
| Backend Jobs | ✅/❌ | |
| API Endpoints | ✅/❌ | |
| Frontend Display | ✅/❌ | |
| WebSocket | ✅/❌ | |
| Performance | ✅/❌ | |
| Security | ✅/❌ | |

### Issues Found:
- [List any issues]

### Recommendations:
- [List recommendations]

### Overall Status: ✅ PASS / ❌ FAIL
```

---

## 🚀 Automated Testing Script

Save as `test-data-usage.sh`:

```bash
#!/bin/bash

echo "🧪 Testing Data Usage Tracking..."
echo "=================================="

# Test 1: Migration
echo "Test 1: Checking migration..."
docker exec traidnet-backend php artisan migrate:status | grep "data_used" && echo "✅ PASS" || echo "❌ FAIL"

# Test 2: Backend Jobs
echo "Test 2: Checking backend jobs..."
docker logs traidnet-backend --tail 20 --since 30s | grep -q "update-dashboard-stats.*DONE" && echo "✅ PASS" || echo "❌ FAIL"

# Test 3: PostgreSQL Logs
echo "Test 3: Checking PostgreSQL logs..."
! docker logs traidnet-postgres --tail 30 --since 60s | grep -q "column.*does not exist" && echo "✅ PASS" || echo "❌ FAIL"

# Test 4: Container Health
echo "Test 4: Checking container health..."
[ $(docker ps --filter name=traidnet --filter health=healthy | wc -l) -ge 7 ] && echo "✅ PASS" || echo "❌ FAIL"

echo "=================================="
echo "✅ Testing complete!"
```

**Run with:**
```bash
chmod +x test-data-usage.sh
./test-data-usage.sh
```

---

## 📞 Support Commands

### **View Real-Time Logs:**
```bash
# Backend
docker logs -f traidnet-backend

# PostgreSQL
docker logs -f traidnet-postgres

# All containers
docker-compose logs -f
```

### **Restart Services:**
```bash
# Restart backend only
docker-compose restart traidnet-backend

# Restart all
docker-compose restart

# Rebuild and restart
docker-compose up -d --build
```

### **Clear Cache:**
```bash
docker exec traidnet-backend php artisan cache:clear
docker exec traidnet-backend php artisan config:clear
docker exec traidnet-backend php artisan queue:restart
```

---

**Testing Guide Complete!** ✅  
**All test scenarios documented!** 📋  
**Ready for QA validation!** 🎯
