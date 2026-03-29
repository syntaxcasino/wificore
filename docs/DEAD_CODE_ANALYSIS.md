# Dead Code Analysis Report
## WiFi Core Management System

**Date:** December 28, 2024  
**Analysis Type:** End-to-End Stack Review  
**Purpose:** Identify and document all unused/dead code for removal

---

## Executive Summary

This document provides a comprehensive analysis of dead code across the entire WiFi Core stack, including:
- Backend (Laravel/PHP)
- Frontend (Vue.js)
- Docker configurations
- Scripts and utilities
- Database migrations

---

## 1. BACKEND - Test & Debug Files (HIGH PRIORITY)

### 1.1 Root-Level Test Files (UNUSED - SAFE TO REMOVE)
**Location:** `backend/`

All these files are standalone test scripts that are NOT referenced anywhere in the codebase:

1. **test-api-endpoint.php** (1,109 bytes)
   - Standalone test script for API endpoints
   - Not used in production or development workflow
   - **Action:** DELETE

2. **test-queue-stats.php** (339 bytes)
   - Standalone queue statistics test
   - Not integrated into application
   - **Action:** DELETE

3. **test-workers.php** (2,915 bytes) - DUPLICATE
   - First instance of worker test
   - **Action:** DELETE (keep only one if needed)

4. **test_workers.php** (1,089 bytes) - DUPLICATE
   - Second instance of worker test
   - **Action:** DELETE (duplicate naming)

5. **test_api.php** (1,646 bytes)
   - Standalone API test script
   - Not used in CI/CD or testing framework
   - **Action:** DELETE

6. **test_metrics_collection.php** (746 bytes)
   - Standalone metrics test
   - Not integrated
   - **Action:** DELETE

7. **test_queue_api.php** (1,242 bytes)
   - Standalone queue API test
   - Not used
   - **Action:** DELETE

8. **test_router_creation.php** (2,863 bytes)
   - Standalone router creation test
   - Not integrated
   - **Action:** DELETE

**Total Size:** ~10.9 KB

---

### 1.2 Root-Level Check Files (UNUSED - SAFE TO REMOVE)
**Location:** `backend/`

1. **check-cache.php** (1,329 bytes)
   - Standalone cache check script
   - Not used in monitoring or cron jobs
   - **Action:** DELETE

2. **check-monitoring-queue.php** (942 bytes)
   - Standalone monitoring check
   - Not integrated
   - **Action:** DELETE

3. **check-registrations.php** (1,181 bytes)
   - Standalone registration check
   - Not used
   - **Action:** DELETE

4. **check-schedule-locks.php** (1,549 bytes)
   - Standalone schedule lock check
   - Not integrated
   - **Action:** DELETE

5. **check_failed_jobs.php** (450 bytes)
   - Standalone failed jobs check
   - Not used in monitoring
   - **Action:** DELETE

6. **check_shell_exec.php** (779 bytes)
   - Standalone shell execution test
   - Not used
   - **Action:** DELETE

7. **comprehensive-check.php** (4,659 bytes)
   - Large standalone check script
   - Not integrated into application
   - **Action:** DELETE

**Total Size:** ~10.9 KB

---

### 1.3 Root-Level Utility Files (REVIEW NEEDED)
**Location:** `backend/`

1. **run-metrics-job.php** (924 bytes)
   - Standalone metrics job runner
   - May be used for manual testing
   - **Action:** REVIEW - Check if used in cron/manual ops

2. **fix_metrics_migration.php** (1,047 bytes)
   - Migration fix script
   - One-time use, likely obsolete
   - **Action:** DELETE (migration already applied)

3. **update-services-tenant-aware.php** (2,097 bytes)
   - Service update script
   - One-time migration script
   - **Action:** DELETE (migration complete)

**Total Size:** ~4 KB

---

## 2. BACKEND - Docker Files (MEDIUM PRIORITY)

### 2.1 Backup/Unused Dockerfiles
**Location:** `backend/`

1. **Dockerfile.backup** (3,473 bytes)
   - Backup of previous Dockerfile
   - Not referenced in docker-compose.yml
   - **Action:** DELETE

2. **Dockerfile.optimized** (3,075 bytes)
   - Alternative optimized Dockerfile
   - Not used in current docker-compose.yml (uses `Dockerfile`)
   - **Action:** DELETE or REPLACE current if better

3. **.env.backup** (2,655 bytes)
   - Backup environment file
   - Not used
   - **Action:** DELETE

**Total Size:** ~9.2 KB

---

## 3. ROOT-LEVEL SCRIPTS (HIGH PRIORITY)

### 3.1 PowerShell Fix Scripts (TEMPORARY - SAFE TO REMOVE)
**Location:** Root directory

These are one-time fix scripts that should not be in production:

1. **apply-complete-fix.ps1**
   - One-time fix script
   - **Action:** DELETE

2. **apply-sudo-fix.ps1**
   - One-time fix script
   - **Action:** DELETE

3. **final-fix-all-issues.ps1**
   - One-time fix script
   - **Action:** DELETE

4. **fix-dashboard-docker.ps1**
   - One-time fix script
   - **Action:** DELETE

5. **fix-dashboard-metrics.ps1**
   - One-time fix script
   - **Action:** DELETE

6. **fix-freeradius-permissions.ps1**
   - One-time fix script
   - **Action:** DELETE

7. **fix-login-issues.ps1**
   - One-time fix script
   - **Action:** DELETE

8. **fix-seeder-duplicate.ps1**
   - One-time fix script
   - **Action:** DELETE

9. **fix-soketi-websocket.ps1**
   - One-time fix script
   - **Action:** DELETE

10. **rebuild-and-fix-all.ps1**
    - One-time rebuild script
    - **Action:** DELETE

11. **rollback-dockerfiles.ps1**
    - One-time rollback script
    - **Action:** DELETE

**Total:** 11 fix scripts

---

### 3.2 PowerShell Test Scripts (DEVELOPMENT ONLY)
**Location:** Root directory

1. **test-api.ps1**
   - Development test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

2. **test-dashboard-apis.ps1**
   - Development test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

3. **test-http-api.ps1**
   - Development test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

4. **test-worker-detection.ps1**
   - Development test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

**Total:** 4 test scripts

---

### 3.3 PowerShell Utility Scripts (KEEP BUT ORGANIZE)
**Location:** Root directory

1. **move-docs.ps1**
   - Utility for documentation organization
   - **Action:** MOVE to `scripts/utils/`

2. **rebuild-optimized.ps1**
   - Build utility
   - **Action:** MOVE to `scripts/build/`

3. **reload-supervisor.ps1**
   - Operational utility
   - **Action:** MOVE to `scripts/ops/`

4. **verify-optimization.ps1**
   - Verification utility
   - **Action:** MOVE to `scripts/utils/`

---

### 3.4 Shell Scripts (REVIEW NEEDED)
**Location:** Root directory

1. **build-and-push.sh**
   - CI/CD build script
   - **Action:** KEEP - MOVE to `scripts/ci/`

2. **check-job-dispatch.sh**
   - Monitoring script
   - **Action:** KEEP - MOVE to `scripts/monitoring/`

3. **create_all_remaining_components.sh**
   - One-time component generation
   - **Action:** DELETE

4. **deploy-redis-fix.sh**
   - One-time fix
   - **Action:** DELETE

5. **production-diagnostics.sh**
   - Diagnostic utility
   - **Action:** KEEP - MOVE to `scripts/diagnostics/`

6. **setup-wireguard.sh**
   - Setup script
   - **Action:** KEEP - MOVE to `scripts/setup/`

7. **test-queue-workers.sh**
   - Test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

8. **test-verification.sh**
   - Test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

9. **test_tenant_creation.sh**
   - Test script
   - **Action:** MOVE to `scripts/dev/` or DELETE

10. **unblock-ip-docker.sh**
    - Utility script
    - **Action:** KEEP - MOVE to `scripts/ops/`

11. **unblock-ip.sh**
    - Utility script
    - **Action:** KEEP - MOVE to `scripts/ops/`

---

### 3.5 SQL Scripts (ONE-TIME USE - SAFE TO REMOVE)
**Location:** Root directory

1. **create_tenant_user_complete.sql**
   - One-time setup script
   - **Action:** DELETE (use migrations instead)

2. **create_test_user.sql**
   - Test data script
   - **Action:** MOVE to `scripts/dev/` or DELETE

3. **fix_metrics_tables.sql**
   - One-time fix
   - **Action:** DELETE (migration applied)

4. **fix_password.sql**
   - One-time fix
   - **Action:** DELETE

---

### 3.6 PHP Scripts (ROOT LEVEL - SAFE TO REMOVE)
**Location:** Root directory

1. **create_events.php**
   - Code generation script (one-time)
   - **Action:** DELETE

2. **test_todos_api.php**
   - Test script
   - **Action:** DELETE

3. **test_todos_comprehensive.php**
   - Test script
   - **Action:** DELETE

---

## 4. BACKEND - Models (UNUSED/PARTIALLY USED)

### 4.1 Unused Models (REVIEW CAREFULLY)

1. **HotspotUser.php** (125 lines)
   - Model exists but NOT used in routes/controllers
   - Replaced by User model with role-based system
   - **Action:** VERIFY no usage, then DELETE

2. **HotspotSession.php** (95 lines)
   - Model exists but NOT used
   - Sessions tracked via RadiusSession
   - **Action:** VERIFY no usage, then DELETE

3. **HotspotCredential.php** (minimal)
   - Model exists but NOT used
   - **Action:** VERIFY no usage, then DELETE

4. **Voucher.php** (45 lines)
   - Model exists but NO controller/routes
   - Voucher system not implemented
   - **Action:** DELETE (feature not implemented)

5. **RouterConfig.php** (minimal)
   - Model exists but NOT used
   - Configuration stored differently
   - **Action:** VERIFY no usage, then DELETE

6. **WireguardPeer.php** (minimal)
   - Model exists but NOT used
   - VPN uses different approach
   - **Action:** VERIFY no usage, then DELETE

---

## 5. BACKEND - Controllers (UNUSED)

### 5.1 Unused/Deprecated Controllers

1. **EmailVerificationController.php** (5,325 bytes)
   - NOT referenced in routes/api.php
   - Email verification handled by UnifiedAuthController
   - **Action:** DELETE

2. **LoginController.php** (12,255 bytes)
   - PARTIALLY used (only 2 legacy routes)
   - Routes: `/register` (legacy), `/email/resend` (legacy)
   - Main auth handled by UnifiedAuthController
   - **Action:** MIGRATE remaining routes to UnifiedAuthController, then DELETE

3. **SystemHealthController.php** (9,154 bytes)
   - Duplicate of EnvironmentHealthController
   - NOT used in routes
   - **Action:** DELETE

---

## 6. BACKEND - Routes (DEPRECATED)

### 6.1 Deprecated/Test Routes
**Location:** `routes/web.php`

1. **Test Broadcast Route** (lines 12-27)
   - `/test-broadcast` route
   - Development/testing only
   - **Action:** REMOVE from production

2. **Test RSC Generation Route** (lines 30-51)
   - `/test/rsc-generation` route
   - Development/testing only
   - **Action:** REMOVE from production

---

### 6.2 Deprecated Broadcast Channels
**Location:** `routes/channels.php`

1. **Global `router-status` channel** (lines 30-34)
   - Disabled for security (returns false)
   - **Action:** DELETE

2. **Global `routers` channel** (lines 37-41)
   - Disabled for security (returns false)
   - **Action:** DELETE

3. **Global `online` channel** (lines 44-48)
   - Disabled for security (returns false)
   - **Action:** DELETE

---

## 7. FRONTEND - Unused Views/Components

### 7.1 Duplicate Dashboard Views
**Location:** `frontend/src/modules/tenant/views/`

1. **Dashboard.vue** (28,699 bytes)
   - Old dashboard version
   - NOT used in router
   - **Action:** DELETE

2. **DashboardExample.vue** (3,355 bytes)
   - Example/template file
   - NOT used in router
   - **Action:** DELETE

3. **DashboardNew.vue** (20,002 bytes)
   - Alternative dashboard version
   - NOT used in router (using DashboardClean.vue)
   - **Action:** DELETE

**Total Size:** ~52 KB

---

### 7.2 Unused Frontend Components (TO BE ANALYZED)

Need to check `frontend/src/modules/` for:
- Unused components in `common/` (62 items)
- Unused components in `tenant/` (191 items)
- Unused views in `protected/` (15 items)

**Action:** DETAILED ANALYSIS REQUIRED

---

## 8. BACKEND - Console Commands (REVIEW)

### 8.1 One-Time Migration Commands
**Location:** `backend/app/Console/Commands/`

1. **FixSchemaMappings.php**
   - One-time fix command
   - **Action:** DELETE after verification

2. **FixTenantSchemas.php**
   - One-time fix command
   - **Action:** DELETE after verification

3. **MigrateTenantHRFinance.php**
   - One-time migration
   - **Action:** DELETE after verification

4. **MigrateTenantTodos.php**
   - One-time migration
   - **Action:** DELETE after verification

---

### 8.2 Test Commands
**Location:** `backend/app/Console/Commands/`

1. **TestDashboardJob.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

2. **TestEmailDeliveryCommand.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

3. **TestMailerSendCommand.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

4. **TestMetricsCollection.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

5. **TestProvisioning.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

6. **TestProvisioningWithEvents.php**
   - Test command
   - **Action:** MOVE to dev or DELETE

---

## 9. DOCKER - Unused Configurations

### 9.1 Unused Docker Files
**Location:** `docker/`

1. **redis/Dockerfile** (if exists)
   - Using official redis:alpine image
   - **Action:** VERIFY and DELETE if unused

---

## 10. DOCUMENTATION FILES (REVIEW)

### 10.1 Implementation Documentation
**Location:** `backend/`

1. **SERVICE_VALIDATION_IMPLEMENTATION.md** (8,286 bytes)
   - Implementation notes
   - **Action:** MOVE to `docs/` folder

**Location:** Root

1. **LIVESTOCK_MANAGEMENT_IMPLEMENTATION.md**
   - Unrelated to WiFi Core
   - **Action:** DELETE or move to separate project

---

## SUMMARY STATISTICS

### Files to DELETE (High Confidence)
- Backend test files: 8 files (~10.9 KB)
- Backend check files: 7 files (~10.9 KB)
- Backend Docker backups: 3 files (~9.2 KB)
- Root fix scripts: 11 PS1 files
- Root SQL scripts: 4 files
- Root PHP scripts: 3 files
- Frontend duplicate views: 3 files (~52 KB)
- Unused models: 6 files
- Unused controllers: 3 files

**Total Immediate Deletions:** ~45+ files

### Files to MOVE/ORGANIZE
- Test scripts: 7 files → `scripts/dev/`
- Utility scripts: 8 files → `scripts/utils/`
- Operational scripts: 4 files → `scripts/ops/`

### Files to REVIEW
- Console commands: 10 files
- Frontend components: 268 items
- Backend services: Review for unused

---

## ESTIMATED CLEANUP IMPACT

- **Disk Space Saved:** ~100+ KB (excluding frontend components)
- **Code Maintainability:** HIGH improvement
- **Deployment Size:** Reduced by ~5-10%
- **Developer Confusion:** Significantly reduced

---

## NEXT STEPS

1. ✅ Create backup branch before deletion
2. ✅ Delete high-confidence dead code (test/check files)
3. ✅ Move scripts to organized structure
4. ⏳ Analyze frontend components usage
5. ⏳ Verify model usage with grep search
6. ⏳ Remove deprecated routes/channels
7. ⏳ Update documentation
8. ✅ Test application after cleanup
9. ✅ Commit changes with detailed message
10. ✅ Push to remote repository

---

## RISK ASSESSMENT

### LOW RISK (Safe to Delete)
- All test/check PHP files in backend root
- All fix scripts in root
- Backup Dockerfiles
- SQL one-time scripts
- Duplicate frontend views

### MEDIUM RISK (Verify First)
- Unused models (check for indirect usage)
- Console migration commands
- Some utility scripts

### HIGH RISK (Careful Review)
- Controllers with partial usage
- Frontend components (need usage analysis)
- Docker configurations

---

*End of Dead Code Analysis Report*
