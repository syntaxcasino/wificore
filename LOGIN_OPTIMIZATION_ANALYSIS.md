# Login Performance Optimization Analysis
## Date: 2026-05-22
## Issue: Login takes ~20 seconds

---

## 🔍 ROOT CAUSE ANALYSIS

### 1. RADIUS Authentication Flow

**Current Flow (UnifiedAuthController::login):**
```
1. Rate limiting check (Redis) ~10ms
2. User lookup by username/email (DB) ~50-100ms
3. Tenant lookup (DB) ~50ms
4. Schema mapping validation (DB) ~50ms
5. RADIUS authenticate() call ~2000ms (2s timeout, possible retries)
6. TrackFailedLoginJob dispatch ~50ms
7. UpdateLoginStatsJob dispatch ~50ms
8. Sanctum token creation ~100ms
9. Response preparation ~50ms
```

**Total theoretical:** ~2.5-3 seconds
**Actual observed:** ~20 seconds

**Gap identified:** The Dapphp\Radius library may have internal retry logic that's not visible in our code. If it retries 10 times with a 2s timeout, that's 20 seconds.

### 2. Database Query Analysis

**Queries per login:**
1. `SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1` ~50ms
2. `SELECT * FROM tenants WHERE id = ? LIMIT 1` ~50ms
3. `SELECT * FROM radius_user_schema_mapping WHERE username = ? AND is_active = true LIMIT 1` ~50ms
4. Token creation (Sanctum inserts) ~100ms

**Total DB time:** ~250ms (not the bottleneck)

### 3. External Service Calls

**RADIUS Authentication:**
- Library: `Dapphp\Radius\Radius`
- Configured timeout: 2 seconds
- Server: `wificore-freeradius` (Docker internal)
- Port: 1812
- **SUSPICION:** Library may have internal retry mechanism

### 4. Middleware Overhead

**Applied to login route:**
1. `DDoSProtection` - Redis INCR operation (O(1)) ~5ms
2. `EnforceSubdomainTenantBinding` - Skips for unauthenticated ~1ms
3. `AddCacheHeaders` - Header manipulation ~1ms
4. `throttle:5,1` - Rate limiting ~10ms

**Total middleware:** ~20ms (not the bottleneck)

---

## 🎯 OPTIMIZATIONS IDENTIFIED

### CRITICAL: RADIUS Retry Investigation

The 20-second delay strongly suggests the RADIUS library is retrying failed connections multiple times with the 2-second timeout.

**Potential causes:**
1. FreeRADIUS container not responding
2. Network/DNS resolution delay to `wificore-freeradius`
3. Library internal retry logic (3-10 retries)
4. Connection establishment timeout separate from auth timeout

### HIGH PRIORITY: Async Job Dispatching

Current code dispatches jobs synchronously:
```php
TrackFailedLoginJob::dispatch($user->id, $request->ip())
    ->onQueue('auth-tracking'); // Still blocks until job is pushed
```

Should use `dispatchAfterResponse()` for non-critical tracking jobs.

### MEDIUM PRIORITY: Connection Pooling

RADIUS connection is created fresh on every auth attempt:
```php
public function __construct() {
    $this->radius = new Radius(); // New connection each time
}
```

Could use connection pooling or persistent connections.

### LOW PRIORITY: Caching Tenant Info

Tenant lookups could be cached for 60 seconds to reduce DB queries.

---

## 📊 PERFORMANCE IMPACT ESTIMATES

| Optimization | Current | After | Improvement |
|-------------|---------|-------|-------------|
| Fix RADIUS retries | 20s | 2-3s | **~90% faster** |
| Async job dispatch | Blocks ~100ms | Non-blocking | ~100ms saved |
| Connection pooling | 200ms connect | 50ms reuse | ~150ms saved |
| Tenant caching | 50ms query | 5ms cache | ~45ms saved |

**Potential total improvement: 20s → 2-3s (85-90% faster)**

---

## 🧪 DEBUGGING STEPS TO CONFIRM ROOT CAUSE

1. Add detailed timing logs around RADIUS call:
```php
$start = microtime(true);
$authenticated = $this->radiusService->authenticate($username, $password);
$elapsed = microtime(true) - $start;
Log::info("RADIUS auth took {$elapsed}s");
```

2. Check FreeRADIUS container health:
```bash
docker exec wificore-freeradius radtest testuser testpassword localhost:18120 testing123
```

3. Monitor RADIUS library behavior:
```php
// Check if library has retry configuration
$radius = new Radius();
$reflection = new ReflectionClass($radius);
$retryProperty = $reflection->getProperty('retries');
$retryProperty->setAccessible(true);
Log::info('RADIUS retries: ' . $retryProperty->getValue($radius));
```

---

## 🚀 RECOMMENDED OPTIMIZATION IMPLEMENTATION

### Phase 1: Immediate Fixes (RADIUS Investigation)

1. Add connection timeout vs auth timeout separation
2. Verify FreeRADIUS container health
3. Check DNS resolution time for `wificore-freeradius`
4. Add fallback to local auth if RADIUS fails quickly

### Phase 2: Code Optimizations

1. Use `dispatchAfterResponse()` for tracking jobs
2. Add timing logs throughout login flow
3. Implement tenant caching
4. Optimize database query ordering

### Phase 3: Infrastructure

1. Connection pooling for RADIUS
2. Redis caching for user lookups
3. Read replica for login queries

---

## 📝 IMPLEMENTATION PLAN

1. **Tag current state** (DONE: v1.4.0-pre-login-optimization)
2. **Add instrumentation** - Detailed timing logs
3. **Optimize RADIUS configuration** - Reduce timeout, add fallback
4. **Make jobs async** - Use dispatchAfterResponse()
5. **Test and measure** - Verify improvements
6. **Create final tag** - Mark optimized state

---

## ⚠️ RISK ASSESSMENT

- **RADIUS timeout reduction:** May cause auth failures on slow networks
  - Mitigation: Add fallback to database password hash
  
- **Async job dispatch:** Failed tracking jobs won't block login
  - Mitigation: Jobs are non-critical (tracking only)

- **Caching tenant info:** Stale tenant status possible
  - Mitigation: Short 60s TTL + active status checked separately

---

## ✅ SUCCESS CRITERIA

- [ ] Login time reduced from 20s to <3s
- [ ] All existing users can still login
- [ ] Failed login tracking still works
- [ ] Login stats still recorded
- [ ] No regression in security
