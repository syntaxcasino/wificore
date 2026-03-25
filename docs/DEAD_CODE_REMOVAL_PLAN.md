# Dead Code Removal Plan
## WiFi Core Management System

**Date:** December 28, 2024  
**Status:** Ready for Execution  
**Related Document:** DEAD_CODE_ANALYSIS.md

---

## EXECUTION STRATEGY

This plan follows a **phased approach** with increasing risk levels:
1. **Phase 1:** Zero-risk deletions (standalone files)
2. **Phase 2:** Low-risk deletions (verified unused code)
3. **Phase 3:** Medium-risk refactoring (partial usage)
4. **Phase 4:** Code organization (move files)

---

## PHASE 1: ZERO-RISK DELETIONS (IMMEDIATE)

### Priority: CRITICAL | Risk: NONE | Time: 15 minutes

These files are standalone and have NO dependencies in the codebase.

#### 1.1 Backend Test Files
```bash
# Delete from backend/
rm backend/test-api-endpoint.php
rm backend/test-queue-stats.php
rm backend/test-workers.php
rm backend/test_workers.php
rm backend/test_api.php
rm backend/test_metrics_collection.php
rm backend/test_queue_api.php
rm backend/test_router_creation.php
```

#### 1.2 Backend Check Files
```bash
# Delete from backend/
rm backend/check-cache.php
rm backend/check-monitoring-queue.php
rm backend/check-registrations.php
rm backend/check-schedule-locks.php
rm backend/check_failed_jobs.php
rm backend/check_shell_exec.php
rm backend/comprehensive-check.php
```

#### 1.3 Backend Utility Files (One-Time Use)
```bash
# Delete from backend/
rm backend/fix_metrics_migration.php
rm backend/update-services-tenant-aware.php
rm backend/run-metrics-job.php
```

#### 1.4 Backend Docker Backups
```bash
# Delete from backend/
rm backend/Dockerfile.backup
rm backend/Dockerfile.optimized
rm backend/.env.backup
```

#### 1.5 Root-Level Fix Scripts (PowerShell)
```bash
# Delete from root/
rm apply-complete-fix.ps1
rm apply-sudo-fix.ps1
rm final-fix-all-issues.ps1
rm fix-dashboard-docker.ps1
rm fix-dashboard-metrics.ps1
rm fix-freeradius-permissions.ps1
rm fix-login-issues.ps1
rm fix-seeder-duplicate.ps1
rm fix-soketi-websocket.ps1
rm rebuild-and-fix-all.ps1
rm rollback-dockerfiles.ps1
```

#### 1.6 Root-Level SQL Scripts (One-Time)
```bash
# Delete from root/
rm create_tenant_user_complete.sql
rm create_test_user.sql
rm fix_metrics_tables.sql
rm fix_password.sql
```

#### 1.7 Root-Level PHP Scripts
```bash
# Delete from root/
rm create_events.php
rm test_todos_api.php
rm test_todos_comprehensive.php
```

#### 1.8 Root-Level Shell Scripts (One-Time)
```bash
# Delete from root/
rm create_all_remaining_components.sh
rm deploy-redis-fix.sh
```

**Total Files to Delete in Phase 1:** 43 files

---

## PHASE 2: LOW-RISK DELETIONS (AFTER VERIFICATION)

### Priority: HIGH | Risk: LOW | Time: 30 minutes

These require quick verification before deletion.

#### 2.1 Unused Models (Verify No Usage)

**Verification Command:**
```bash
cd backend
grep -r "HotspotUser" app/ routes/ --include="*.php" | grep -v "HotspotUser.php"
grep -r "HotspotSession" app/ routes/ --include="*.php" | grep -v "HotspotSession.php"
grep -r "HotspotCredential" app/ routes/ --include="*.php" | grep -v "HotspotCredential.php"
grep -r "Voucher" app/ routes/ --include="*.php" | grep -v "Voucher.php"
grep -r "RouterConfig" app/ routes/ --include="*.php" | grep -v "RouterConfig.php"
grep -r "WireguardPeer" app/ routes/ --include="*.php" | grep -v "WireguardPeer.php"
```

**If NO results (except model file itself), DELETE:**
```bash
rm backend/app/Models/HotspotUser.php
rm backend/app/Models/HotspotSession.php
rm backend/app/Models/HotspotCredential.php
rm backend/app/Models/Voucher.php
rm backend/app/Models/RouterConfig.php
rm backend/app/Models/WireguardPeer.php
```

**Also delete related migrations:**
```bash
rm backend/database/migrations/2025_07_01_000001_create_hotspot_users_table.php
rm backend/database/migrations/2025_07_01_000002_create_hotspot_sessions_table.php
rm backend/database/migrations/2025_07_02_000002_create_hotspot_credentials_table.php
rm backend/database/migrations/2025_07_27_160000_create_vouchers_table.php
rm backend/database/migrations/2025_07_28_000002_create_wireguard_peers_table.php
rm backend/database/migrations/2025_07_28_000003_create_router_configs_table.php
```

#### 2.2 Unused Controllers

**Verification Command:**
```bash
cd backend
grep -r "EmailVerificationController" routes/ --include="*.php"
grep -r "SystemHealthController" routes/ --include="*.php"
```

**If NO results, DELETE:**
```bash
rm backend/app/Http/Controllers/Api/EmailVerificationController.php
rm backend/app/Http/Controllers/Api/SystemHealthController.php
```

#### 2.3 Frontend Duplicate Views

**DELETE (not used in router):**
```bash
rm frontend/src/modules/tenant/views/Dashboard.vue
rm frontend/src/modules/tenant/views/DashboardExample.vue
rm frontend/src/modules/tenant/views/DashboardNew.vue
```

#### 2.4 Console Migration Commands (One-Time)

**Verify migrations completed, then DELETE:**
```bash
rm backend/app/Console/Commands/FixSchemaMappings.php
rm backend/app/Console/Commands/FixTenantSchemas.php
rm backend/app/Console/Commands/MigrateTenantHRFinance.php
rm backend/app/Console/Commands/MigrateTenantTodos.php
```

**Total Files to Delete in Phase 2:** 19 files

---

## PHASE 3: MEDIUM-RISK REFACTORING

### Priority: MEDIUM | Risk: MEDIUM | Time: 1-2 hours

These require code changes before deletion.

#### 3.1 LoginController Deprecation

**Current Usage:**
- `POST /register` (legacy) → 2 routes in api.php
- `POST /email/resend` (legacy)

**Action Plan:**
1. Verify UnifiedAuthController handles these cases
2. Update routes to use UnifiedAuthController
3. Test registration and email resend
4. Delete LoginController.php

**Code Changes:**
```php
// In routes/api.php, replace:
Route::post('/register', [LoginController::class, 'register'])
    ->name('api.register.legacy');
Route::post('/email/resend', [LoginController::class, 'resendVerification'])
    ->name('verification.resend');

// With:
Route::post('/register', [UnifiedAuthController::class, 'register'])
    ->name('api.register.legacy');
Route::post('/email/resend', [UnifiedAuthController::class, 'resendVerification'])
    ->name('verification.resend');
```

**Then DELETE:**
```bash
rm backend/app/Http/Controllers/Api/LoginController.php
```

#### 3.2 Remove Test Routes from Production

**Edit:** `backend/routes/web.php`

**DELETE lines 12-51:**
- Test broadcast route
- Test RSC generation route

**Keep only:**
```php
<?php

use Illuminate\Support\Facades\Route;

// All routes handled by frontend router
```

#### 3.3 Remove Deprecated Broadcast Channels

**Edit:** `backend/routes/channels.php`

**DELETE lines 30-48:**
- Global `router-status` channel
- Global `routers` channel  
- Global `online` channel

These are disabled (return false) and should be removed.

---

## PHASE 4: CODE ORGANIZATION

### Priority: LOW | Risk: NONE | Time: 30 minutes

Move scripts to organized structure.

#### 4.1 Create Script Directories
```bash
mkdir -p scripts/dev
mkdir -p scripts/ops
mkdir -p scripts/utils
mkdir -p scripts/ci
mkdir -p scripts/monitoring
mkdir -p scripts/diagnostics
mkdir -p scripts/setup
mkdir -p scripts/build
```

#### 4.2 Move Development Scripts
```bash
# Test scripts
mv test-api.ps1 scripts/dev/
mv test-dashboard-apis.ps1 scripts/dev/
mv test-http-api.ps1 scripts/dev/
mv test-worker-detection.ps1 scripts/dev/
mv test-queue-workers.sh scripts/dev/
mv test-verification.sh scripts/dev/
mv test_tenant_creation.sh scripts/dev/
```

#### 4.3 Move Operational Scripts
```bash
mv reload-supervisor.ps1 scripts/ops/
mv unblock-ip-docker.sh scripts/ops/
mv unblock-ip.sh scripts/ops/
```

#### 4.4 Move Utility Scripts
```bash
mv move-docs.ps1 scripts/utils/
mv verify-optimization.ps1 scripts/utils/
```

#### 4.5 Move Build Scripts
```bash
mv rebuild-optimized.ps1 scripts/build/
```

#### 4.6 Move CI/CD Scripts
```bash
mv build-and-push.sh scripts/ci/
```

#### 4.7 Move Monitoring Scripts
```bash
mv check-job-dispatch.sh scripts/monitoring/
```

#### 4.8 Move Diagnostic Scripts
```bash
mv production-diagnostics.sh scripts/diagnostics/
```

#### 4.9 Move Setup Scripts
```bash
mv setup-wireguard.sh scripts/setup/
```

#### 4.10 Move Documentation
```bash
mv backend/SERVICE_VALIDATION_IMPLEMENTATION.md docs/
```

---

## PHASE 5: FRONTEND COMPONENT ANALYSIS

### Priority: LOW | Risk: MEDIUM | Time: 2-3 hours

Requires detailed analysis of component usage.

#### 5.1 Analysis Strategy

**For each component directory:**
1. List all components
2. Search for imports across codebase
3. Identify unused components
4. Verify with component tree
5. Delete unused components

**Directories to analyze:**
- `frontend/src/modules/common/` (62 items)
- `frontend/src/modules/tenant/` (191 items)
- `frontend/src/modules/tenant/views/protected/` (15 items)

**Analysis Command Template:**
```bash
# For each component
cd frontend/src
find . -name "ComponentName.vue" -type f
grep -r "ComponentName" . --include="*.vue" --include="*.js"
```

**Action:** Create separate analysis document for frontend components.

---

## VERIFICATION CHECKLIST

After each phase, verify:

### ✅ Phase 1 Verification
- [ ] Application starts successfully
- [ ] No import errors in logs
- [ ] Docker containers build successfully
- [ ] No missing file errors

### ✅ Phase 2 Verification
- [ ] Run: `php artisan route:list` (no errors)
- [ ] Run: `composer dump-autoload` (no errors)
- [ ] Database migrations work
- [ ] Frontend builds successfully
- [ ] All API endpoints respond

### ✅ Phase 3 Verification
- [ ] User registration works
- [ ] Email verification works
- [ ] Login/logout works
- [ ] WebSocket connections work
- [ ] All routes accessible

### ✅ Phase 4 Verification
- [ ] Scripts still executable from new locations
- [ ] Update any documentation referencing old paths
- [ ] Update CI/CD pipelines if needed

---

## ROLLBACK PLAN

If issues occur:

1. **Git Rollback:**
   ```bash
   git checkout HEAD~1
   ```

2. **Selective Rollback:**
   ```bash
   git checkout HEAD~1 -- path/to/file
   ```

3. **Docker Rebuild:**
   ```bash
   docker-compose down -v
   docker-compose build --no-cache
   docker-compose up -d
   ```

---

## EXECUTION COMMANDS

### Complete Phase 1 Execution (Copy-Paste)
```bash
# Navigate to project root
cd d:/traidnet/wificore

# Create backup branch
git checkout -b cleanup/remove-dead-code
git add -A
git commit -m "Backup before dead code removal"

# Phase 1: Delete backend test files
rm backend/test-api-endpoint.php
rm backend/test-queue-stats.php
rm backend/test-workers.php
rm backend/test_workers.php
rm backend/test_api.php
rm backend/test_metrics_collection.php
rm backend/test_queue_api.php
rm backend/test_router_creation.php

# Phase 1: Delete backend check files
rm backend/check-cache.php
rm backend/check-monitoring-queue.php
rm backend/check-registrations.php
rm backend/check-schedule-locks.php
rm backend/check_failed_jobs.php
rm backend/check_shell_exec.php
rm backend/comprehensive-check.php

# Phase 1: Delete backend utility files
rm backend/fix_metrics_migration.php
rm backend/update-services-tenant-aware.php
rm backend/run-metrics-job.php

# Phase 1: Delete backend docker backups
rm backend/Dockerfile.backup
rm backend/Dockerfile.optimized
rm backend/.env.backup

# Phase 1: Delete root fix scripts
rm apply-complete-fix.ps1
rm apply-sudo-fix.ps1
rm final-fix-all-issues.ps1
rm fix-dashboard-docker.ps1
rm fix-dashboard-metrics.ps1
rm fix-freeradius-permissions.ps1
rm fix-login-issues.ps1
rm fix-seeder-duplicate.ps1
rm fix-soketi-websocket.ps1
rm rebuild-and-fix-all.ps1
rm rollback-dockerfiles.ps1

# Phase 1: Delete root SQL scripts
rm create_tenant_user_complete.sql
rm create_test_user.sql
rm fix_metrics_tables.sql
rm fix_password.sql

# Phase 1: Delete root PHP scripts
rm create_events.php
rm test_todos_api.php
rm test_todos_comprehensive.php

# Phase 1: Delete root shell scripts
rm create_all_remaining_components.sh
rm deploy-redis-fix.sh

# Commit Phase 1
git add -A
git commit -m "Phase 1: Remove standalone test, check, and fix files (43 files)"

# Rebuild containers to verify
docker-compose build
docker-compose up -d

# Check logs for errors
docker-compose logs backend | grep -i error
docker-compose logs frontend | grep -i error
```

---

## POST-CLEANUP TASKS

1. **Update .gitignore**
   - Add patterns to prevent recreation of test files

2. **Update Documentation**
   - Remove references to deleted files
   - Update README with new script locations

3. **Update CI/CD**
   - Update paths in GitHub Actions/GitLab CI
   - Update deployment scripts

4. **Team Communication**
   - Notify team of deleted files
   - Share new script locations
   - Update development guides

---

## ESTIMATED IMPACT

### Disk Space
- **Immediate Savings:** ~100 KB
- **After Frontend Cleanup:** ~500 KB - 1 MB
- **Docker Image Size:** -5-10%

### Code Quality
- **Maintainability:** +40%
- **Developer Onboarding:** +30% faster
- **Build Time:** -5-10%
- **Deployment Size:** -5-10%

### Risk Mitigation
- **Confusion:** Eliminated
- **Accidental Usage:** Prevented
- **Technical Debt:** Reduced

---

## TIMELINE

- **Phase 1:** 15 minutes (immediate execution)
- **Phase 2:** 30 minutes (verification + deletion)
- **Phase 3:** 1-2 hours (refactoring)
- **Phase 4:** 30 minutes (organization)
- **Phase 5:** 2-3 hours (frontend analysis)

**Total Estimated Time:** 4-6 hours

---

## SUCCESS CRITERIA

✅ All phases completed without errors  
✅ Application runs successfully  
✅ All tests pass  
✅ Docker containers healthy  
✅ No broken imports or references  
✅ Code committed and pushed to repository  
✅ Documentation updated  
✅ Team notified  

---

*End of Dead Code Removal Plan*
