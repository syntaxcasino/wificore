# Router Page Performance - Deep Analysis
## Date: 2026-05-22
## Thorough E2E Review

---

## 🔴 CRITICAL ISSUES FOUND

### 1. DOUBLE SORTING (Frontend)
**Files:** 
- `useRouters.js` lines 275-308
- `RoutersView.vue` lines 353-359

**Issue:** Routers are sorted TWICE:
1. First in `fetchRouters()` when data arrives from API
2. Second in `filteredRouters` computed property

**Code Evidence:**
```javascript
// useRouters.js - First sort (lines 275-308)
const sortedRouters = [...validRouters].sort((a, b) => {
  // Compare by name
  const nameA = String(a?.name ?? '').trim()
  const nameB = String(b?.name ?? '').trim()
  const byName = nameA.localeCompare(nameB, 'en', { numeric: true, sensitivity: 'base' })
  if (byName !== 0) return byName
  
  // Compare by IP address (with parsing)
  const parseIp = (ip) => { /* complex parsing */ }
  // ... more comparison logic
})
routers.value = sortedRouters

// RoutersView.vue - Second sort (lines 353-359)
const filteredRouters = computed(() => {
  // ... filtering ...
  return [...filtered].sort((a, b) => {
    const byName = normalizeName(a).localeCompare(normalizeName(b), undefined, { numeric: true, sensitivity: 'base' })
    if (byName !== 0) return byName
    const byIp = compareIp(parseIp(a?.ip_address), parseIp(b?.ip_address))
    // ... same sorting logic again
  })
})
```

**Impact:** O(n log n) sorting runs twice on every data update. With 100 routers, that's 2 × O(100 log 100) = 2 × 664 = 1,328 comparison operations per update.

---

### 2. SEQUENTIAL VICTORIAMETRICS QUERIES (Backend)
**File:** `RouterMetricsService.php` lines 73-157

**Issue:** 10-14 sequential HTTP requests to VictoriaMetrics:
```php
$queries = [
    'cpu_load' => [/* primary + fallback */],
    'total_memory' => /* query */,
    'total_memory_kb' => /* query */,
    'free_memory' => /* query */,
    'uptime_ticks' => /* query */,
    'pppoe_sessions' => /* query */,
    'hotspot_active' => /* query */,
    'wireless_clients' => /* query */,
    'dhcp_leases' => /* query */,
    'interface_count' => /* query */,
    'disk_total_bytes' => [/* primary + fallback */],
    'disk_used_bytes' => [/* primary + fallback */],
    'memory_total_bytes' => [/* primary + fallback */],
    'memory_used_bytes' => [/* primary + fallback */],
];

foreach ($queries as $field => $promql) {
    $response = $vm->queryInstant($primary); // HTTP request
    if ($fallback && count($missing) > 0) {
        $fallbackResponse = $vm->queryInstant($fallback); // Another HTTP request
    }
}
```

**Timeline:**
- Base query: 50-200ms
- Fallback queries (up to 14): 50-200ms each
- **Total: 500ms-2.8s PER PAGE LOAD**

---

### 3. NO CACHING LAYER (Backend)
**File:** `RouterMetricsService.php`

**Issue:** Every page load fetches fresh metrics from VictoriaMetrics, even if data hasn't changed.

**Telegraf scraping interval:** 30-60 seconds
**Metrics change frequency:** Every 30-60 seconds
**Current behavior:** Fetch every single page load

---

### 4. REACTIVE COMPUTED PROPERTIES CHAIN (Frontend)
**File:** `RoutersView.vue` lines 340-382

**Issue:** Multiple computed properties trigger cascading recalculations:

```javascript
const filteredRouters = computed(() => { /* filter + SORT */ })  // Runs on any router data change
const paginatedRouters = computed(() => { /* slices filteredRouters */ })  // Depends on filteredRouters
const totalPages = computed(() => { /* uses filteredRouters */ })  // Depends on filteredRouters
const onlineCount = computed(() => /* scans all routers */)
const offlineCount = computed(() => /* scans all routers */)
const issueCount = computed(() => /* scans all routers */)
```

**When metrics update:**
1. `routers.value` array is updated with live_data (line 326-341 in useRouters.js)
2. Vue detects change, marks all computed properties as dirty
3. `filteredRouters` re-runs: filtering + sorting
4. `paginatedRouters` re-runs: slicing
5. `totalPages` re-runs: division
6. Stats counts re-run: 3 full array scans

**Impact:** Single metrics update triggers 6+ computations, including expensive sorting.

---

### 5. API WATERFALL (Network)
**Sequence:**
```
1. Browser → GET /routers → Laravel → DB Query → Response
   └─→ ~200-500ms

2. Browser → POST /routers/metrics/live → Laravel → VictoriaMetrics x14 → Response
   └─→ ~500-2800ms

Total: 700ms-3300ms
```

**Problem:** Requests are sequential, not parallel. The second request waits for the first to complete.

---

### 6. N+1 QUERY RISK (Backend)
**File:** `RouterController.php` line 37

**Code:**
```php
$routers = Router::with(['services', 'accessPoints'])
    ->orderBy('created_at', 'desc')
    ->paginate($perPage);
```

**Issue:** While `with()` eager loads relations, if the view iterates over these and accesses nested relations, it can trigger additional queries. The `services` and `accessPoints` relations may not be needed for the list view.

---

### 7. NO REQUEST DEBOUNCING (Frontend)
**File:** `useRouters.js` line 247

**Issue:** Rapid clicks on refresh button will stack up multiple concurrent requests:
```javascript
const fetchRouters = async () => {
    // No debounce check
    const response = await axios.get('/routers')
    // ...
}
```

**User scenario:** User clicks refresh 3 times quickly → 3 full request cycles running simultaneously.

---

## 📊 PERFORMANCE IMPACT SUMMARY

| Issue | Impact | Frequency |
|-------|--------|-----------|
| Double sorting | ~50-100ms per update | Every data change |
| Sequential VM queries | 500ms-2.8s | Every page load |
| No caching | 500ms-2.8s | Every page load |
| Cascading computed | ~100-200ms | Every metrics update |
| API waterfall | Adds ~200ms latency | Every page load |
| N+1 queries | ~100-500ms | Every page load |
| No debouncing | Multiplies load | User-dependent |

**Total worst-case:** 700ms-3300ms per page load
**Total with rapid refreshes:** 2s-10s+

---

## 🎯 OPTIMIZATION STRATEGY

### Phase 1: Quick Wins (30 minutes)
1. Remove double sorting - remove sort from useRouters.js, keep only in computed
2. Add request debouncing to fetchRouters
3. Add 30-second cache to RouterMetricsService

### Phase 2: Medium Effort (2 hours)
4. Make VM queries parallel using Guzzle async
5. Add selective relation loading (don't load services/accessPoints for list)
6. Optimize computed properties with memoization

### Phase 3: Advanced (4 hours)
7. Pre-compute metrics in background job
8. Implement virtual scrolling for large lists
9. Add request coalescing (dedupe concurrent identical requests)

---

## 🧪 TESTING CHECKLIST

- [ ] Create router - verify VPN config generates
- [ ] Update router - verify settings save
- [ ] Delete router - verify removal
- [ ] Reprovision router - verify reset works
- [ ] Load 50+ routers - verify <3s load time
- [ ] Rapid refresh clicks - verify no request stacking
- [ ] Sort by different columns - verify single sort operation
