# Backend Audit Gap Register — WifiCore SaaS
**Audit Date:** 2026-04-21  
**Baseline Tag:** `v1.3.4-pre-audit-fixes`  
**Scope:** Provisioning reliability, security, performance, multi-tenancy

---

## CRITICAL (must fix before next release)

### GAP-01 · SSH connect timeout too low for low-end devices
- **File:** `app/Services/RouterResourceManager.php` — `RESOURCE_LIMITS['low_end']['ssh_timeout'] = 30`
- **Root cause:** `SshExecutor::connect()` passes `$this->timeout` to the `SSH2` constructor. On a hAP lite under load, TCP handshake + SSH key exchange can take 35–50 s, exceeding the 30 s limit and aborting before any provisioning command runs.
- **Impact:** Low-end provisioning fails at SSH connect; all 5 job retries wasted.
- **Fix:** Raise `low_end.ssh_timeout` to 60 s.

### GAP-02 · ~180 s cumulative `sleep()` blocks a queue worker
- **File:** `app/Services/MikrotikProvisioningService.php` — `executeBatchedCommands()`
- **Root cause:** 10 s inter-batch sleep × up to 10 batches + 3 s post-upload + 5 s post-import = up to 180 s of pure `sleep()` inside a queue worker. Combined with the 120 s `/import` timeout, the job's 600 s wall-clock limit is hit and the worker is killed mid-provisioning.
- **Impact:** Provisioning silently abandoned at batch N; router left in partial state.
- **Fix:** Reduce inter-batch sleep to 3 s (sufficient for hAP lite memory flush per testing), post-upload to 1 s, post-import to 2 s.

### GAP-03 · Dual idempotency lock keys — retry conflict
- **File:** `app/Jobs/RouterProvisioningJob.php` (key: `provision_router_<id>`) and `app/Services/MikrotikProvisioningService.php` (key: `router_provision_lock_<id>`)
- **Root cause:** Two different lock keys guard the same resource. Inner lock TTL (default 60 s via `MIKROTIK_PROVISION_LOCK_TTL`) is far shorter than job timeout (600 s). On a crash-restart, job re-acquires outer lock but inner lock may still be held from the previous run's TTL window, or may have expired allowing a concurrent duplicate.
- **Impact:** Duplicate provisioning runs possible; router can receive duplicate firewall/RADIUS rules.
- **Fix:** Unify to a single lock key `mikrotik_provision_<routerId>` with TTL matching job timeout (600 s). Remove the inner lock in `applyConfigs()`.

### GAP-04 · `extractConfigFromScript()` — fragile regex breaks REST API path
- **File:** `app/Services/MikrotikProvisioningService.php:1947–2095`
- **Root cause:** When REST API is selected for low-end devices, config parameters are parsed from the already-generated RSC script using brittle single-line regex. Multi-line commands or any quoting variation silently yields empty fields (`bridge=null`, `radius_servers=[]`). The API configurator proceeds with an empty config and fails at `verify()`.
- **Impact:** REST API path always fails → falls back to SSH batching even when API was viable, wasting 30–60 s.
- **Fix:** Pass the structured config array from the generator directly through the provisioning pipeline instead of re-parsing the script string.

### GAP-05 · `mikrotik.php` has 4 duplicate config keys
- **File:** `config/mikrotik.php` lines 13–14, 22/54, 32/63, 46/77
- **Root cause:** The file was copy-pasted and `pass`, `timeout`, `default_profile`, `cache_ttl` defined twice. PHP uses the last value silently.
- **Impact:** The first half of the config is dead code; any env changes to the first entry are ignored.
- **Fix:** Deduplicate all four keys.

### GAP-06 · `RadiusService` uses `env()` directly — breaks `config:cache`
- **File:** `app/Services/RadiusService.php:17–29`
- **Root cause:** Constructor calls `env('RADIUS_SERVER_HOST', ...)` and `env('RADIUS_SECRET', 'testing123')`. After `php artisan config:cache`, `env()` always returns the default, so production connects to `wificore-freeradius` with secret `testing123`.
- **Impact:** All RADIUS authentication fails silently in a cached-config production deployment.
- **Fix:** Use `config('radius.server_ip')` / `config('radius.secret')` via the existing `config/radius.php`.

### GAP-07 · `MikroTikRestApiService` uses raw `Crypt::decrypt()` instead of `safeDecrypt()`
- **File:** `app/Services/MikroTik/MikroTikRestApiService.php:53`
- **Root cause:** `Crypt::decrypt($router->password)` throws `DecryptException` if password was stored with an older encryption scheme. `PasswordEncryptionService::safeDecrypt()` catches and logs this gracefully.
- **Impact:** REST API path throws uncaught exception → falls back to SSH batching silently.
- **Fix:** Replace with `PasswordEncryptionService::safeDecrypt($router)` with null-check.

### GAP-08 · `DDoSProtection` middleware bypasses all authenticated users
- **File:** `app/Http/Middleware/DDoSProtection.php:23–26`
- **Root cause:** Early return when `$request->user()` is truthy — any stolen/brute-forced token bypasses all rate limiting.
- **Impact:** Authenticated API endpoints (router management, user CRUD) unprotected from abuse.
- **Fix:** Remove the early return; allow the middleware to run for all requests. Authenticated users already have per-endpoint throttle middleware.

### GAP-09 · `DDoSProtection` serialises a growing PHP array per request (O(n) Redis write)
- **File:** `app/Http/Middleware/DDoSProtection.php:51–61`
- **Root cause:** Request timestamps stored as a serialized PHP array in Redis, deserialized → mutated → re-serialized on every unauthenticated request.
- **Impact:** Redis CPU spikes under load; memory overhead proportional to request rate.
- **Fix:** Replace with Redis `INCR` + `EXPIRE` sliding counter (single atomic operation).

---

## WARNING (fix before next minor release)

### GAP-10 · `isLowEndDevice()` and `RouterResourceManager::LOW_END_MODELS` diverge
- **Files:** `ZeroConfigPPPoEGenerator.php:424–441` vs `RouterResourceManager.php:17–27`
- **Root cause:** The generator's local pattern list includes `cAP`, `wAP`, `SXT`, `LDF`, `Metal`; `RouterResourceManager` only has 7 entries. SSH timeout and batch size come from `RouterResourceManager`; script generation profile from the local method.
- **Impact:** Devices like `cAP lite` receive low-end script (minimal firewall) but full 30 s SSH timeout — still causes SSH timeouts on some models.
- **Fix:** Merge lists into `RouterResourceManager::LOW_END_PATTERNS` and delegate from `isLowEndDevice()`.

### GAP-11 · `validateInterface()` strips `.` — corrupts VLAN sub-interface names
- **File:** `app/Services/MikroTik/BaseMikroTikService.php:197–198`
- **Root cause:** `preg_replace('/[^a-zA-Z0-9\-_]/', '', $interface)` removes dots. RouterOS VLAN interfaces use `ether2.100`.
- **Impact:** Generated scripts reference invalid interface names; bridge port add fails silently.
- **Fix:** Allow `.` in the character class.

### GAP-12 · `SshExecutor::exec()` logs every command at `info` level
- **File:** `app/Services/MikroTik/SshExecutor.php:150`
- **Root cause:** `Log::info("SSH command executed successfully", [..., 'command' => $command])` on every successful exec.
- **Impact:** Dozens of log entries per provisioning job; thousands per minute under multi-tenant load. Bloats log storage and degrades Loki/Graylog search.
- **Fix:** Downgrade to `Log::debug(...)`.

### GAP-13 · `isCommandError()` — `'no such item'` causes false-positive errors
- **File:** `app/Services/MikroTik/SshExecutor.php:311`
- **Root cause:** `str_contains($outputLower, 'no such item')` matches anywhere in output. RouterOS can print `no such item` as informational context (e.g. empty list response) without it being a fatal error.
- **Impact:** Valid provisioning commands trigger spurious retries; verification checks throw on empty lists.
- **Fix:** Match only at the start of a line: `preg_match('/^no such item/m', $outputLower)`.

### GAP-14 · `bootstrapSecurityHardening()` emits only 1 rule despite BCP 38 claim
- **File:** `app/Services/MikroTik/ZeroConfigBootstrapTrait.php:655–670`
- **Root cause:** Method body generates only an ICMP rate-limit drop rule. Anti-spoofing (RP filter / ingress source validation) and subscriber rate limits advertised in the docblock are absent.
- **Impact:** Routers are not actually protected with BCP 38 anti-spoofing; DoS amplification possible.
- **Fix:** Add ingress source-address verification rule and document the actual rule set.

### GAP-15 · `scheduleBatchCleanup()` uses session-level `SET search_path` (PgBouncer unsafe)
- **File:** `app/Services/MikrotikProvisioningService.php:1828–1831`
- **Root cause:** Raw `DB::statement("SET search_path TO ...")` without a wrapping transaction. PgBouncer transaction pooling releases the backend connection after the statement, losing the session-level SET.
- **Impact:** Batch file cleanup closure runs in the wrong schema; `Router::find()` may return null or query the wrong tenant's table.
- **Fix:** Wrap the closure body in `DB::transaction()` and use `SET LOCAL search_path` (already the standard pattern in `SetTenantContext`).

### GAP-16 · `Router::getTenantIdAttribute()` — N+1 cross-schema lookup on every property access
- **File:** `app/Models/Router.php:90–96`
- **Root cause:** `RouterTenantMap::findTenantByRouterId()` fires a DB query to `public.router_tenant_map` on every `$router->tenant_id` access. No caching. Loading 20 routers = 20 extra queries.
- **Impact:** Router list endpoint performance degrades linearly with router count.
- **Fix:** Add a simple instance-level cache: store result in `$this->cachedTenantId` after first resolution.

### GAP-17 · `RouterController::index()` no pagination, eager-loads all relations
- **File:** `app/Http/Controllers/Api/RouterController.php:33–35`
- **Root cause:** `Router::with(['services', 'accessPoints'])->get()` with no `paginate()` or `limit()`.
- **Impact:** For tenants with 50+ routers each with multiple services/APs, this fetches thousands of rows per page load.
- **Fix:** Add `paginate(25)` as default, with `?per_page=` override capped at 100.

### GAP-18 · Login handler logs full request payload — PII exposure
- **File:** `app/Http/Controllers/Api/UnifiedAuthController.php:35–40`
- **Root cause:** `'payload' => $request->except('password')` still captures all fields. If the frontend sends credentials under an unexpected key (`pass`, `secret`, etc.), they appear in application logs.
- **Impact:** Credential leakage in Graylog / Loki / CloudWatch.
- **Fix:** Log only `username` and `ip`; remove the `payload` key entirely.

---

## Notes
- **Connectivity script credential exposure** (GAP not yet numbered for code fix): The `generateConnectivityScript()` method embeds a plaintext decrypted password into a RouterOS script stored in `router_configs`. This table should be considered sensitive. Recommend rotating connectivity scripts post-provisioning or storing only a hash reference.
- **Shared SNMP community**: All tenant routers share the same SNMP community string from `config('telegraf.snmp_community')`. SNMPv3 or per-tenant communities should be adopted in a future iteration.
- **`captivePortalUrl` router_id exposure**: `router_id` (UUID) appears in captive portal redirect URLs visible to hotspot clients. Ensure `CaptivePortalController` strictly validates session ownership.
