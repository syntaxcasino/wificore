# UNUSED FILES AUDIT REPORT

**Date**: February 8, 2026
**Method**: Static code analysis (grep, import tracing, route/scheduler/provider cross-referencing)
**Mode**: PARTIAL ARCHIVAL — Categories 13 & 14 archived on Feb 8, 2026

---

## Summary

| Category | Files Scanned | Candidates Found |
|----------|--------------|-----------------|
| Frontend Views (old/superseded) | ~100 | 33 |
| Frontend Components (unused) | ~50 | 8 |
| Frontend Composables/Stores | ~20 | 4 |
| Frontend .bak files | 3 | 3 |
| Backend Services | 59 | 3 |
| Backend Controllers | 52 | 3 |
| Backend Jobs | 54 | 5 |
| Backend Events | 65 | 3 |
| Backend Notifications | 11 | 0 |
| Root-level Scripts | 56 | 56 |
| Root-level Docs/MD | 28 | 28 |
| **TOTAL** | **~500+** | **~146** |

---

## CATEGORY 1: Frontend — Old/Superseded Views (NOT in router, NOT imported anywhere)

These are old versions of views that have been replaced by "New" versions in the router.
The router imports the "New" version; the old version has ZERO references anywhere.

### Settings (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 1 | `frontend/src/modules/tenant/views/dashboard/settings/GeneralSettings.vue` | Not imported anywhere | GeneralSettingsNew.vue |
| 2 | `frontend/src/modules/tenant/views/dashboard/settings/MikrotikApiCredentials.vue` | Not imported anywhere | MikrotikApiCredentialsNew.vue |
| 3 | `frontend/src/modules/tenant/views/dashboard/settings/RadiusServerSettings.vue` | Not imported anywhere | RadiusServerSettingsNew.vue |
| 4 | `frontend/src/modules/tenant/views/dashboard/settings/MpesaApiKeys.vue` | Not imported anywhere | MpesaApiKeysNew.vue |
| 5 | `frontend/src/modules/tenant/views/dashboard/settings/TimezoneLocale.vue` | Not imported anywhere | TimezoneLocaleNew.vue |
| 6 | `frontend/src/modules/tenant/views/dashboard/settings/EmailSmsSettings.vue` | Not imported anywhere | Replaced by CommunicationChannels.vue |
| 7 | `frontend/src/modules/tenant/views/dashboard/settings/EmailSmsSettingsNew.vue` | Not imported anywhere | Replaced by CommunicationChannels.vue |

### Billing (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 8 | `frontend/src/modules/tenant/views/dashboard/billing/Invoices.vue` | Not imported anywhere | InvoicesNew.vue |
| 9 | `frontend/src/modules/tenant/views/dashboard/billing/Payments.vue` | Not imported anywhere | PaymentsNew.vue |
| 10 | `frontend/src/modules/tenant/views/dashboard/billing/MpesaTransactions.vue` | Not imported anywhere | MpesaTransactionsNew.vue |
| 11 | `frontend/src/modules/tenant/views/dashboard/billing/WalletAccountBalance.vue` | Not imported anywhere | WalletAccountBalanceNew.vue |
| 12 | `frontend/src/modules/tenant/views/dashboard/billing/PaymentMethods.vue` | Not imported anywhere | PaymentMethodsNew.vue |

### Admin (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 13 | `frontend/src/modules/tenant/views/dashboard/admin/ActivityLogs.vue` | Not imported anywhere | ActivityLogsNew.vue |
| 14 | `frontend/src/modules/tenant/views/dashboard/admin/BackupRestore.vue` | Not imported anywhere | BackupRestoreNew.vue |
| 15 | `frontend/src/modules/tenant/views/dashboard/admin/RolesPermissions.vue` | Not imported anywhere | RolesPermissionsNew.vue |

### Monitoring (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 16 | `frontend/src/modules/tenant/views/dashboard/monitoring/LiveConnections.vue` | Not imported anywhere | LiveConnectionsNew.vue |
| 17 | `frontend/src/modules/tenant/views/dashboard/monitoring/SystemLogs.vue` | Not imported anywhere | SystemLogsNew.vue |
| 18 | `frontend/src/modules/tenant/views/dashboard/monitoring/TrafficGraphs.vue` | Not imported anywhere | TrafficGraphsNew.vue |
| 19 | `frontend/src/modules/tenant/views/dashboard/monitoring/SessionLogs.vue` | Not imported anywhere | SessionLogsNew.vue |

### Reports (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 20 | `frontend/src/modules/tenant/views/dashboard/reports/DailyLoginReports.vue` | Not imported anywhere | DailyLoginReportsNew.vue |
| 21 | `frontend/src/modules/tenant/views/dashboard/reports/PaymentReports.vue` | Not imported anywhere | PaymentReportsNew.vue |
| 22 | `frontend/src/modules/tenant/views/dashboard/reports/BandwidthUsageSummary.vue` | Not imported anywhere | BandwidthUsageSummaryNew.vue |
| 23 | `frontend/src/modules/tenant/views/dashboard/reports/UserSessionHistory.vue` | Not imported anywhere | UserSessionHistoryNew.vue |

### Support (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 24 | `frontend/src/modules/tenant/views/dashboard/support/AllTickets.vue` | Not imported anywhere | AllTicketsNew.vue |
| 25 | `frontend/src/modules/tenant/views/dashboard/support/CreateTicket.vue` | Not imported anywhere | CreateTicketNew.vue |

### Packages (old versions — replaced by *New.vue in router)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 26 | `frontend/src/modules/tenant/views/dashboard/packages/AddPackage.vue` | Not imported anywhere | AddPackageNew.vue |
| 27 | `frontend/src/modules/tenant/views/dashboard/packages/PackageGroups.vue` | Not imported anywhere | PackageGroupsNew.vue |

### Hotspot (old version)
| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 28 | `frontend/src/modules/tenant/views/dashboard/hotspot/VouchersGenerate.vue` | Not imported anywhere | VouchersGenerateNew.vue |

---

## CATEGORY 2: Frontend — Orphaned Dashboard Sub-Views (NOT in router, NOT imported)

These are old dashboard sub-views that existed before the current dashboard layout.
None are imported in router/index.js or any other file.

| # | File | Reason |
|---|------|--------|
| 29 | `frontend/src/modules/tenant/views/dashboard/Overview.vue` | Not in router, not imported anywhere |
| 30 | `frontend/src/modules/tenant/views/dashboard/Logs.vue` | Not in router, not imported anywhere |
| 31 | `frontend/src/modules/tenant/views/dashboard/Payments.vue` | Not in router, not imported anywhere |
| 32 | `frontend/src/modules/tenant/views/dashboard/Users.vue` | Not in router, not imported anywhere |
| 33 | `frontend/src/modules/tenant/views/dashboard/SystemHealth.vue` | Not in router, not imported anywhere |
| 34 | `frontend/src/modules/tenant/views/dashboard/DailyWeeklyStatistics.vue` | Not in router, not imported anywhere |

### Orphaned Logs sub-directory (NOT in router)
| # | File | Reason |
|---|------|--------|
| 35 | `frontend/src/modules/tenant/views/dashboard/logs/AccessLogs.vue` | Not in router, not imported anywhere |
| 36 | `frontend/src/modules/tenant/views/dashboard/logs/LogsLayout.vue` | Not in router, not imported anywhere |
| 37 | `frontend/src/modules/tenant/views/dashboard/logs/SystemLogs.vue` | Not in router, not imported anywhere |

---

## CATEGORY 3: Frontend — Orphaned "protected/" Directory (Entire directory NOT in router)

The entire `views/protected/` directory is an old layout. ZERO files from it appear in router/index.js or any import.

| # | File | Reason |
|---|------|--------|
| 38 | `frontend/src/modules/tenant/views/protected/ClientsView.vue` | Not in router |
| 39 | `frontend/src/modules/tenant/views/protected/ReportsView.vue` | Not in router |
| 40 | `frontend/src/modules/tenant/views/protected/SettingsView.vue` | Not in router |
| 41 | `frontend/src/modules/tenant/views/protected/hotspot/BandwidthView.vue` | Not in router |
| 42 | `frontend/src/modules/tenant/views/protected/hotspot/ClientsView.vue` | Not in router |
| 43 | `frontend/src/modules/tenant/views/protected/hotspot/ConfigView.vue` | Not in router |
| 44 | `frontend/src/modules/tenant/views/protected/hotspot/HotspotBandwidth.vue` | Not in router |
| 45 | `frontend/src/modules/tenant/views/protected/hotspot/HotspotConfig.vue` | Not in router |
| 46 | `frontend/src/modules/tenant/views/protected/hotspot/HotspotUsers.vue` | Not in router |
| 47 | `frontend/src/modules/tenant/views/protected/hotspot/HotspotVouchers.vue` | Not in router |
| 48 | `frontend/src/modules/tenant/views/protected/hotspot/PaymentsView.vue` | Not in router |
| 49 | `frontend/src/modules/tenant/views/protected/hotspot/ReportsView.vue` | Not in router |
| 50 | `frontend/src/modules/tenant/views/protected/hotspot/SettingsView.vue` | Not in router |
| 51 | `frontend/src/modules/tenant/views/protected/hotspot/UsersView.vue` | Not in router |
| 52 | `frontend/src/modules/tenant/views/protected/hotspot/VouchersView.vue` | Not in router |

---

## CATEGORY 4: Frontend — Superseded Dashboard Views

| # | File | Reason | Superseded By |
|---|------|--------|---------------|
| 53 | `frontend/src/modules/tenant/views/Dashboard.vue` | Not in router (DashboardClean.vue used) | DashboardClean.vue |
| 54 | `frontend/src/modules/tenant/views/DashboardExample.vue` | Not in router, not imported anywhere | DashboardClean.vue |
| 55 | `frontend/src/modules/tenant/views/DashboardNew.vue` | Not in router, not imported anywhere | DashboardClean.vue |

---

## CATEGORY 5: Frontend — Unused Components

| # | File | Reason |
|---|------|--------|
| 56 | `frontend/src/components/AsyncOperationStatus.vue` | Not imported anywhere |
| 57 | `frontend/src/components/LoadingProgress.vue` | Not imported anywhere (only ref in .bak file) |
| 58 | `frontend/src/components/ServiceSlider.vue` | Not imported anywhere |
| 59 | `frontend/src/modules/common/components/ErrorBoundary.vue` | Not imported anywhere |
| 60 | `frontend/src/modules/common/components/layout/AppSidebarOld.vue` | Not imported anywhere (old sidebar) |
| 61 | `frontend/src/modules/common/components/dashboard/RoleBasedDashboard.vue` | Not imported anywhere |
| 62 | `frontend/src/modules/tenant/components/PackageSelector.vue` | Not imported anywhere |
| 63 | `frontend/src/modules/tenant/components/dashboard/PackagesManager.vue` | Not imported anywhere |

### Vue scaffold icon components (Vue CLI defaults, never used)
| # | File | Reason |
|---|------|--------|
| 64 | `frontend/src/modules/common/components/icons/IconCommunity.vue` | Not imported anywhere |
| 65 | `frontend/src/modules/common/components/icons/IconDocumentation.vue` | Not imported anywhere |
| 66 | `frontend/src/modules/common/components/icons/IconEcosystem.vue` | Not imported anywhere |
| 67 | `frontend/src/modules/common/components/icons/IconSupport.vue` | Not imported anywhere |
| 68 | `frontend/src/modules/common/components/icons/IconTooling.vue` | Not imported anywhere |

### Duplicate base components (common/ duplicates of base/)
| # | File | Reason |
|---|------|--------|
| 69 | `frontend/src/modules/common/components/common/BaseButton.vue` | Duplicate of base/BaseButton.vue, not imported |
| 70 | `frontend/src/modules/common/components/common/BaseModal.vue` | Duplicate of base/BaseModal.vue, not imported |
| 71 | `frontend/src/modules/common/components/common/ErrorMessage.vue` | Not imported anywhere |
| 72 | `frontend/src/modules/common/components/common/LoadingSpinner.vue` | Not imported anywhere |

---

## CATEGORY 6: Frontend — Unused Composables/Stores/Services

| # | File | Reason |
|---|------|--------|
| 73 | `frontend/src/composables/useWebSocketEvents.js` | Only self-referencing, never imported by any component |
| 74 | `frontend/src/composables/useAsyncOperation.js` | Only self-referencing, never imported by any component |
| 75 | `frontend/src/stores/counter.js` | Vue scaffold default, never imported |
| 76 | `frontend/src/components/__tests__/HelloWorld.spec.js` | Vue scaffold test, references non-existent component |

---

## CATEGORY 7: Frontend — Unused Views

| # | File | Reason |
|---|------|--------|
| 77 | `frontend/src/views/TenantPackagesView.vue` | Not in router, not imported anywhere |
| 78 | `frontend/src/modules/tenant/views/billing/PaybillSettingsView.vue` | Not in router, not imported anywhere |
| 79 | `frontend/src/modules/tenant/views/dashboard/routers/RoutersView.vue` | Not in router (only self-ref in MikrotikList.vue comment) |

---

## CATEGORY 8: Frontend — .bak Files

| # | File | Reason |
|---|------|--------|
| 80 | `frontend/src/modules/common/components/layout/AppSidebar.vue.bak` | Backup file |
| 81 | `frontend/src/modules/common/components/layout/AppSidebarOld.vue.bak` | Backup file |
| 82 | `frontend/src/modules/common/views/auth/TenantRegistrationView.vue.bak` | Backup file |

---

## CATEGORY 9: Backend — Unused Services

| # | File | Reason | Checks Performed |
|---|------|--------|-----------------|
| 83 | `backend/app/Services/MikroTik/QoSManagementService.php` | Only self-referencing + vendor autoload | Searched all .php files |
| 84 | `backend/app/Services/MikroTik/RouterHardeningService.php` | Only self-referencing + vendor autoload | Searched all .php files |
| 85 | `backend/app/Services/MikroTik/ServiceTemplateService.php` | Only self-referencing + vendor autoload | Searched all .php files |

---

## CATEGORY 10: Backend — Unused Controllers (NOT in routes, NOT referenced)

| # | File | Reason | Checks Performed |
|---|------|--------|-----------------|
| 86 | `backend/app/Http/Controllers/Api/VoucherController.php` | Not in routes/*.php, not referenced anywhere | Searched routes + all .php |
| 87 | `backend/app/Http/Controllers/Api/WireguardController.php` | Not in routes/*.php, not referenced anywhere | Searched routes + all .php |
| 88 | `backend/app/Http/Controllers/Api/SystemHealthController.php` | Not in routes/*.php, only self-ref + autoload | Searched routes + all .php |

---

## CATEGORY 11: Backend — Unused Jobs (NOT dispatched, NOT scheduled)

| # | File | Reason | Checks Performed |
|---|------|--------|-----------------|
| 89 | `backend/app/Jobs/DisconnectExpiredSessions.php` | Only self-ref + autoload. Not dispatched, not scheduled | Searched all .php + console.php |
| 90 | `backend/app/Jobs/UpdateRouterStatusJob.php` | Not found in any .php file at all | Searched all .php + console.php |
| 91 | `backend/app/Jobs/UpdateUserStatusJob.php` | Not found in any .php file at all | Searched all .php + console.php |
| 92 | `backend/app/Jobs/VpnCleanupJob.php` | Not found in any .php file at all | Searched all .php + console.php |
| 93 | `backend/app/Jobs/VpnHealthCheckJob.php` | Not found in any .php file at all | Searched all .php + console.php |
| 94 | `backend/app/Jobs/SendTenantVerificationEmailJob.php` | Only self-ref + autoload. Not dispatched anywhere | Searched all .php + console.php |

---

## CATEGORY 12: Backend — Unused Events

| # | File | Reason | Checks Performed |
|---|------|--------|-----------------|
| 95 | `backend/app/Events/HotspotUserProvisionRequested.php` | Only self-ref + autoload | Searched all .php |
| 96 | `backend/app/Events/RouterDisconnected.php` | Not found in any .php file | Searched all .php |

---

## CATEGORY 13: Root-Level Scripts (One-time fix/deploy/test scripts)

These are standalone shell/PowerShell/SQL/JS/PHP scripts at the repository root.
They are NOT referenced by docker-compose, Dockerfile, CI/CD, or any application code.
They were used for one-time fixes, deployments, or testing.

### Shell Scripts (.sh)
| # | File | Purpose |
|---|------|---------|
| 97 | `CHECK_APP_KEY.sh` | One-time app key check |
| 98 | `CRITICAL_FIX.sh` | One-time critical fix |
| 99 | `DEPLOY_BACKEND_FIX.sh` | One-time deploy fix |
| 100 | `check-job-dispatch.sh` | Debug script |
| 101 | `check-tenant-schema.sh` | Debug script |
| 102 | `create_all_remaining_components.sh` | Scaffold generator |
| 103 | `deploy-redis-fix.sh` | One-time fix |
| 104 | `deploy-vpn-fix.sh` | One-time fix |
| 105 | `deploy-wireguard-fix.sh` | One-time fix |
| 106 | `diagnose-all-tenants.sh` | Debug script |
| 107 | `fix-tenant-routers.sh` | One-time fix |
| 108 | `fix-vpn-peer.sh` | One-time fix |
| 109 | `fix-wireguard-interface.sh` | One-time fix |
| 110 | `fix_router_password.sh` | One-time fix |
| 111 | `production-diagnostics.sh` | Debug script |
| 112 | `rebuild-wireguard.sh` | One-time rebuild |
| 113 | `setup-wireguard.sh` | One-time setup |
| 114 | `test-metrics-flow.sh` | Test script |
| 115 | `test-queue-workers.sh` | Test script |
| 116 | `test-verification.sh` | Test script |
| 117 | `test_tenant_creation.sh` | Test script |
| 118 | `unblock-ip.sh` | One-time fix |
| 119 | `unblock-ip-docker.sh` | One-time fix |

### PowerShell Scripts (.ps1)
| # | File | Purpose |
|---|------|---------|
| 120 | `apply-complete-fix.ps1` | One-time fix |
| 121 | `apply-sudo-fix.ps1` | One-time fix |
| 122 | `final-fix-all-issues.ps1` | One-time fix |
| 123 | `fix-dashboard-docker.ps1` | One-time fix |
| 124 | `fix-dashboard-metrics.ps1` | One-time fix |
| 125 | `fix-freeradius-permissions.ps1` | One-time fix |
| 126 | `fix-login-issues.ps1` | One-time fix |
| 127 | `fix-seeder-duplicate.ps1` | One-time fix |
| 128 | `fix-soketi-websocket.ps1` | One-time fix |
| 129 | `move-docs.ps1` | One-time utility |
| 130 | `rebuild-and-fix-all.ps1` | One-time rebuild |
| 131 | `rebuild-optimized.ps1` | One-time rebuild |
| 132 | `reload-supervisor.ps1` | One-time fix |
| 133 | `rollback-dockerfiles.ps1` | One-time rollback |
| 134 | `test-api.ps1` | Test script |
| 135 | `test-dashboard-apis.ps1` | Test script |
| 136 | `test-http-api.ps1` | Test script |
| 137 | `test-worker-detection.ps1` | Test script |
| 138 | `verify-optimization.ps1` | Test script |

### SQL Scripts (.sql)
| # | File | Purpose |
|---|------|---------|
| 139 | `create_tenant_user_complete.sql` | One-time SQL |
| 140 | `create_test_user.sql` | Test SQL |
| 141 | `fix_boolean_operator.sql` | One-time fix |
| 142 | `fix_metrics_tables.sql` | One-time fix |
| 143 | `fix_password.sql` | One-time fix |

### JavaScript Scaffold Generators (.js)
| # | File | Purpose |
|---|------|---------|
| 144 | `create_composables.js` | Scaffold generator |
| 145 | `create_vue_components.js` | Scaffold generator |
| 146 | `generate_all_components.js` | Scaffold generator |

### PHP Scripts (.php)
| # | File | Purpose |
|---|------|---------|
| 147 | `create_events.php` | Scaffold generator |
| 148 | `test_todos_api.php` | Test script |
| 149 | `test_todos_comprehensive.php` | Test script |

### Other
| # | File | Purpose |
|---|------|---------|
| 150 | `test-config.rsc` | MikroTik test config |
| 151 | `COMMIT_MESSAGE.txt` | Old commit message |

---

## CATEGORY 14: Root-Level Documentation (Old deployment/fix docs)

These are standalone markdown files at the repository root documenting past fixes and deployments.
They are NOT referenced by any code. Consider moving to `docs/archive/`.

| # | File |
|---|------|
| 152 | `BACKEND_AUDIT_FIXES.md` |
| 153 | `BUGFIX_MODEL_SERIALIZATION.md` |
| 154 | `CAPTIVE_PORTAL_IMPLEMENTATION.md` |
| 155 | `CAPTIVE_PORTAL_INTEGRATION.md` |
| 156 | `CRITICAL_FIXES_SUMMARY.md` |
| 157 | `CRITICAL_PRODUCTION_DEPLOYMENT.md` |
| 158 | `DEPLOYMENT_FETCH_BASED.md` |
| 159 | `DEPLOYMENT_GUIDE.md` |
| 160 | `DEPLOYMENT_GUIDE_JAN3.md` |
| 161 | `DEPLOYMENT_INSTRUCTIONS.md` |
| 162 | `DEPLOYMENT_JAN4_COMPLETE.md` |
| 163 | `DEPLOYMENT_PEER_AUTO_CONFIG.md` |
| 164 | `DEPLOYMENT_PERFORMANCE_ANALYSIS.md` |
| 165 | `DEPLOYMENT_SCHEMA_FIX.md` |
| 166 | `FINAL_DEPLOYMENT_JAN3.md` |
| 167 | `HOSPITAL_DEPLOYMENT_GUIDE.md` |
| 168 | `LOW_END_DEVICE_OPTIMIZATION.md` |
| 169 | `PASSWORD_DECRYPTION_FIX.md` |
| 170 | `PERFORMANCE_OPTIMIZATIONS.md` |
| 171 | `PRODUCTION_DEPLOYMENT_FIXES.md` |
| 172 | `PRODUCTION_ENV_CHECKLIST.md` |
| 173 | `PROVISIONING_SERVICE_CONFIG.md` |
| 174 | `STACK_OPTIMIZATION_REVIEW.md` |
| 175 | `TEST_CAPTIVE_PORTAL_URL.md` |
| 176 | `UNIFIED_CAPTIVE_PORTAL.md` |
| 177 | `ZERO_CONFIG_PROVISIONING.md` |
| 178 | `stack_analysis_report.md` |

---

## CATEGORY 15: Docker Compose Files (Potentially Unused)

| # | File | Reason |
|---|------|--------|
| 179 | `docker-compose-deployment.yml` | Separate deployment compose — verify if still used |
| 180 | `docker-compose.monitoring.yml` | Monitoring compose — verify if still used |
| 181 | `docker-compose.wireguard.yml` | Wireguard compose — verify if still used |
| 182 | `docker-compose.production - Copy.yml` | Copy of production compose — likely unused |

**NOTE**: `docker-compose.yml` and `docker-compose.production.yml` are ACTIVE — do NOT touch.

---

## FILES EXPLICITLY KEPT (NOT marked unused)

The following were investigated but confirmed USED:

### Backend — Used in Scheduler (console.php)
- `RotateLogs`, `CheckExpiredSessionsJob`, `SyncRadiusAccountingJob`, `UnsuspendExpiredAccountsJob`
- `ProcessGracePeriodJob`, `SyncAccessPointStatusJob`, `ProcessScheduledPackages`
- `CheckPppoePaymentsJob`, `CacheRoutersJob`, `UpdateDashboardStatsJob`
- `SendTenantExpiryWarningJob`, `CheckPppoePaymentStatusJob`, `CheckHotspotExpirationsJob`
- `MetricsService` (called in scheduler closures)

### Backend — Used by Controllers/Services
- `HealthCheckService` → HealthController
- `RouterResourceManager` → ZeroConfig generators
- `ServiceDeploymentValidator` → ServiceConfigurationController
- `SystemMetricsService` → SystemAdminController
- `ScriptBuilder` → HotspotService
- `SshKeyRotationService` → RotateRouterSshKeys command
- `ProvisionVpnConfigurationJob` → VpnConfigurationController
- `SendVerificationEmailJob` → TenantRegistrationController
- `GrantHotspotAccessJob` → HotspotController
- `HotspotUserLoginAttempted` → CaptivePortalController
- `CreateTenantWorkspaceJob` → TenantRegistrationController
- `TenantInvoiceNotification` → LandlordBillingController
- `TenantPaymentReceiptNotification` → LandlordBillingController

### Backend — Artisan Commands (auto-discovered, available via `php artisan`)
All 32 commands in `app/Console/Commands/` are auto-discovered by Laravel 12.
They are available via `php artisan` even without explicit references.
**Decision: KEEP ALL** — they are diagnostic/maintenance utilities.

### Infrastructure — Active in docker-compose
- `provisioning-service/` → active in docker-compose.yml
- `wireguard-controller/` → active in docker-compose.yml
- `build-and-push.sh` → deployment script (KEEP)
- `deploy.sh` → deployment script (KEEP)

### Files NEVER marked unused (per rules)
- All `database/migrations/*`
- All `config/*`
- All `routes/*`
- `composer.json`, `package.json`
- `.env.example`, `.env.production.example`
- `public/index.php`, `bootstrap/*`

---

## SAFETY NOTE

> **Files were identified through static analysis only. No files were moved, deleted, or modified.**
> **Before archiving any file, verify it is not dynamically loaded, reflected, or referenced in ways not detectable by static grep.**
> **When in doubt — KEEP the file.**

---

## RECOMMENDED NEXT STEPS

1. **Review this list** — confirm each candidate is truly unused
2. **Start with highest-confidence items**:
   - `.bak` files (3 files) — safest to archive
   - `protected/` directory (15 files) — entire old layout, zero references
   - Old "non-New" views (28 files) — superseded by New versions
   - Root-level scripts (55 files) — one-time fix/test scripts
3. **Archive in batches** — move to `docs/unused/<original-path>/`
4. **Test after each batch** — run `npm run build` and `php artisan route:list`
5. **Backend items require extra caution** — verify no container resolution or reflection
