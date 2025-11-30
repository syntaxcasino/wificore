# Frontend File Organization Plan

## Current Issues

### Problems Identified:
1. **Duplicate files** - Multiple Dashboard files (Dashboard.vue, DashboardView.vue, DashboardNew.vue, DashboardOld.vue)
2. **Inconsistent structure** - Views scattered across root and subdirectories
3. **Mixed concerns** - Components and views not clearly separated
4. **Naming inconsistencies** - Some files use PascalCase, others use camelCase
5. **Unclear hierarchy** - Hard to find specific features

## Proposed New Structure

```
frontend/src/
├── assets/                    # Static assets
│   ├── images/
│   ├── styles/
│   └── fonts/
│
├── components/                # Reusable components
│   ├── common/               # Shared across app
│   │   ├── Button.vue
│   │   ├── Modal.vue
│   │   ├── LoadingSpinner.vue
│   │   └── ErrorMessage.vue
│   │
│   ├── layout/               # Layout components
│   │   ├── AppLayout.vue
│   │   ├── AppTopbar.vue
│   │   ├── AppSidebar.vue
│   │   ├── AppFooter.vue
│   │   └── PublicLayout.vue
│   │
│   ├── auth/                 # Authentication components
│   │   ├── LoginForm.vue
│   │   └── AuthLayout.vue
│   │
│   ├── dashboard/            # Dashboard-specific components
│   │   ├── cards/           # Stat cards
│   │   │   ├── StatsCard.vue
│   │   │   ├── FinancialCard.vue
│   │   │   └── NetworkCard.vue
│   │   │
│   │   ├── charts/          # Chart components
│   │   │   ├── ActiveUsersChart.vue
│   │   │   ├── PaymentsChart.vue
│   │   │   └── RetentionRate.vue
│   │   │
│   │   └── widgets/         # Dashboard widgets
│   │       ├── DataUsage.vue
│   │       ├── SessionLogs.vue
│   │       └── SystemLogs.vue
│   │
│   ├── routers/             # Router management components
│   │   ├── RouterList.vue
│   │   ├── RouterCard.vue
│   │   ├── RouterForm.vue
│   │   ├── RouterDetails.vue
│   │   └── modals/
│   │       ├── CreateRouterModal.vue
│   │       ├── UpdateRouterModal.vue
│   │       └── ProvisioningModal.vue
│   │
│   ├── packages/            # Package components
│   │   ├── PackageList.vue
│   │   ├── PackageCard.vue
│   │   └── PackageSelector.vue
│   │
│   ├── payments/            # Payment components
│   │   ├── PaymentModal.vue
│   │   └── PhoneInput.vue
│   │
│   └── debug/               # Debug tools
│       └── EventMonitor.vue
│
├── composables/             # Vue composables (hooks)
│   ├── auth/
│   │   └── useAuth.js
│   │
│   ├── data/
│   │   ├── useDashboard.js
│   │   ├── useRouters.js
│   │   ├── usePackages.js
│   │   ├── usePayments.js
│   │   └── useLogs.js
│   │
│   ├── utils/
│   │   ├── useRouterUtils.js
│   │   ├── useChartData.js
│   │   └── useTheme.js
│   │
│   └── websocket/
│       ├── useBroadcasting.js
│       ├── usePaymentWebSocket.js
│       └── useRouterProvisioning.js
│
├── views/                   # Page-level components
│   ├── public/             # Public pages
│   │   ├── HomeView.vue
│   │   ├── AboutView.vue
│   │   ├── PackagesView.vue
│   │   └── NotFoundView.vue
│   │
│   ├── auth/               # Authentication pages
│   │   └── LoginView.vue
│   │
│   ├── dashboard/          # Dashboard pages
│   │   ├── DashboardView.vue        # Main dashboard
│   │   │
│   │   ├── routers/                 # Router management
│   │   │   ├── RoutersView.vue
│   │   │   └── RouterDetailsView.vue
│   │   │
│   │   ├── hotspot/                 # Hotspot management
│   │   │   ├── HotspotView.vue
│   │   │   ├── ActiveSessionsView.vue
│   │   │   ├── ProfilesView.vue
│   │   │   ├── VouchersView.vue
│   │   │   └── LoginCustomizationView.vue
│   │   │
│   │   ├── pppoe/                   # PPPoE management
│   │   │   ├── PPPoEView.vue
│   │   │   ├── ProfilesView.vue
│   │   │   └── SecretsView.vue
│   │   │
│   │   ├── packages/                # Package management
│   │   │   ├── PackagesView.vue
│   │   │   └── PackageDetailsView.vue
│   │   │
│   │   ├── users/                   # User management
│   │   │   ├── UsersView.vue
│   │   │   └── UserDetailsView.vue
│   │   │
│   │   ├── billing/                 # Billing & payments
│   │   │   ├── PaymentsView.vue
│   │   │   ├── InvoicesView.vue
│   │   │   ├── MpesaTransactionsView.vue
│   │   │   └── WalletView.vue
│   │   │
│   │   ├── monitoring/              # Monitoring
│   │   │   ├── LiveConnectionsView.vue
│   │   │   ├── SessionLogsView.vue
│   │   │   ├── TrafficGraphsView.vue
│   │   │   └── LatencyTestsView.vue
│   │   │
│   │   ├── reports/                 # Reports
│   │   │   ├── ReportsView.vue
│   │   │   ├── RevenueReportView.vue
│   │   │   ├── UsageReportView.vue
│   │   │   └── CustomReportView.vue
│   │   │
│   │   ├── logs/                    # Logs
│   │   │   ├── SystemLogsView.vue
│   │   │   └── AccessLogsView.vue
│   │   │
│   │   ├── settings/                # Settings
│   │   │   ├── SettingsView.vue
│   │   │   ├── GeneralSettingsView.vue
│   │   │   ├── NetworkSettingsView.vue
│   │   │   └── NotificationSettingsView.vue
│   │   │
│   │   ├── admin/                   # Admin tools
│   │   │   ├── AdminView.vue
│   │   │   ├── RolesPermissionsView.vue
│   │   │   ├── BackupRestoreView.vue
│   │   │   └── SystemUpdatesView.vue
│   │   │
│   │   └── support/                 # Support
│   │       ├── SupportView.vue
│   │       ├── TicketsView.vue
│   │       └── DocumentationView.vue
│   │
│   └── test/               # Test pages
│       └── WebSocketTestView.vue
│
├── router/                 # Vue Router configuration
│   └── index.js
│
├── stores/                 # Pinia stores
│   ├── auth.js
│   ├── dashboard.js
│   └── theme.js
│
├── plugins/                # Vue plugins
│   ├── axios.js
│   └── echo.js
│
├── utils/                  # Utility functions
│   ├── formatters.js
│   ├── validators.js
│   └── helpers.js
│
├── config/                 # Configuration files
│   ├── api.js
│   └── constants.js
│
├── App.vue                 # Root component
└── main.js                 # Entry point
```

## Migration Steps

### Phase 1: Clean Up Duplicates ✅
```bash
# Remove duplicate dashboard files
DELETE: DashboardNew.vue
DELETE: DashboardOld.vue
KEEP: Dashboard.vue (rename to DashboardView.vue)
```

### Phase 2: Reorganize Components

#### 2.1 Common Components
```bash
MOVE: components/ui/Button.vue → components/common/Button.vue
MOVE: components/ui/Modal.vue → components/common/Modal.vue
MOVE: components/ui/LoadingSpinner.vue → components/common/LoadingSpinner.vue
MOVE: components/ui/ErrorMessage.vue → components/common/ErrorMessage.vue
```

#### 2.2 Dashboard Components
```bash
# Create new structure
CREATE: components/dashboard/cards/
CREATE: components/dashboard/charts/
CREATE: components/dashboard/widgets/

# Move existing
MOVE: components/dashboard/StatsCard.vue → components/dashboard/cards/StatsCard.vue
MOVE: components/dashboard/ActiveUsersChart.vue → components/dashboard/charts/ActiveUsersChart.vue
MOVE: components/dashboard/PaymentsChart.vue → components/dashboard/charts/PaymentsChart.vue
MOVE: components/dashboard/RetentionRate.vue → components/dashboard/charts/RetentionRate.vue
MOVE: components/dashboard/DataUsage.vue → components/dashboard/widgets/DataUsage.vue
MOVE: components/dashboard/SessionLogs.vue → components/dashboard/widgets/SessionLogs.vue
MOVE: components/dashboard/SystemLogs.vue → components/dashboard/widgets/SystemLogs.vue
```

#### 2.3 Router Components
```bash
# Create router components directory
CREATE: components/routers/
CREATE: components/routers/modals/

# Move and rename
MOVE: components/dashboard/RouterManagement.vue → views/dashboard/routers/RoutersView.vue
MOVE: components/dashboard/routers/RouterList.vue → components/routers/RouterList.vue
MOVE: components/dashboard/routers/createOverlay.vue → components/routers/modals/CreateRouterModal.vue
MOVE: components/dashboard/routers/UpdateOverlay.vue → components/routers/modals/UpdateRouterModal.vue
MOVE: components/dashboard/routers/detailsOverlay.vue → components/routers/modals/RouterDetailsModal.vue
MOVE: components/dashboard/routers/RouterProvisioningOverlay.vue → components/routers/modals/ProvisioningModal.vue
DELETE: components/dashboard/routers/Header.vue (merge into RouterList)
```

### Phase 3: Reorganize Composables

```bash
# Create subdirectories
CREATE: composables/auth/
CREATE: composables/data/
CREATE: composables/utils/
CREATE: composables/websocket/

# Move files
MOVE: composables/useAuth.js → composables/auth/useAuth.js
MOVE: composables/useDashboard.js → composables/data/useDashboard.js
MOVE: composables/useRouters.js → composables/data/useRouters.js
MOVE: composables/usePackages.js → composables/data/usePackages.js
MOVE: composables/usePayment.js → composables/data/usePayments.js
MOVE: composables/useLogs.js → composables/data/useLogs.js
MOVE: composables/useRouterUtils.js → composables/utils/useRouterUtils.js
MOVE: composables/useChartData.js → composables/utils/useChartData.js
MOVE: composables/useTheme.js → composables/utils/useTheme.js
MOVE: composables/useBroadcasting.js → composables/websocket/useBroadcasting.js
MOVE: composables/usePaymentWebSocket.js → composables/websocket/usePaymentWebSocket.js
MOVE: composables/useRouterProvisioning.js → composables/websocket/useRouterProvisioning.js

# Delete empty/unused
DELETE: composables/useDashboardData.js (duplicate of useDashboard.js)
```

### Phase 4: Reorganize Views

```bash
# Clean up root views
MOVE: views/Dashboard.vue → views/dashboard/DashboardView.vue
MOVE: views/DashboardView.vue → DELETE (duplicate)
MOVE: views/LoginPage.vue → views/auth/LoginView.vue
MOVE: views/HomeView.vue → views/public/HomeView.vue
MOVE: views/AboutView.vue → views/public/AboutView.vue
MOVE: views/NotFound.vue → views/public/NotFoundView.vue
MOVE: views/WebSocketTest.vue → views/test/WebSocketTestView.vue

# Organize dashboard views
MOVE: views/HotspotUsers.vue → views/dashboard/hotspot/UsersView.vue
MOVE: views/PackageSettings.vue → views/dashboard/packages/SettingsView.vue
MOVE: views/Payments.vue → views/dashboard/billing/PaymentsView.vue
MOVE: views/PaymentSuccess.vue → views/dashboard/billing/PaymentSuccessView.vue

# Delete duplicates
DELETE: views/dashboard/Payments.vue (duplicate)
DELETE: views/dashboard/Users.vue (consolidate with user management)
```

### Phase 5: Update Imports

After moving files, update all import statements:

```javascript
// OLD
import { useAuth } from '@/composables/useAuth'
import RouterManagement from '@/components/dashboard/RouterManagement.vue'

// NEW
import { useAuth } from '@/composables/auth/useAuth'
import RoutersView from '@/views/dashboard/routers/RoutersView.vue'
```

## Naming Conventions

### Files
- **Components**: PascalCase (e.g., `RouterList.vue`, `PaymentModal.vue`)
- **Views**: PascalCase with "View" suffix (e.g., `DashboardView.vue`, `RoutersView.vue`)
- **Composables**: camelCase with "use" prefix (e.g., `useAuth.js`, `useRouters.js`)
- **Utilities**: camelCase (e.g., `formatters.js`, `validators.js`)

### Directories
- **lowercase with hyphens** for multi-word (e.g., `dashboard-cards/`)
- **OR lowercase single word** (e.g., `routers/`, `payments/`)

## Benefits of New Structure

### 1. **Clear Separation of Concerns**
- Components are reusable UI pieces
- Views are page-level components
- Composables are logic/state management
- Utils are pure functions

### 2. **Easy Navigation**
- Feature-based organization
- Predictable file locations
- Logical grouping

### 3. **Better Scalability**
- Easy to add new features
- Clear where new files go
- Modular structure

### 4. **Improved Maintainability**
- No duplicate files
- Consistent naming
- Clear dependencies

### 5. **Better Developer Experience**
- Faster file finding
- Easier onboarding
- Clear architecture

## Implementation Checklist

### Immediate Actions (High Priority)
- [ ] Delete duplicate Dashboard files
- [ ] Reorganize composables into subdirectories
- [ ] Move router components to proper location
- [ ] Clean up views directory structure

### Short Term (Medium Priority)
- [ ] Reorganize dashboard components
- [ ] Update all import paths
- [ ] Test all routes still work
- [ ] Update documentation

### Long Term (Low Priority)
- [ ] Create utils directory
- [ ] Create config directory
- [ ] Standardize all naming
- [ ] Add index.js exports for cleaner imports

## Migration Script

Create a migration script to automate the reorganization:

```bash
# migration.sh
#!/bin/bash

# Phase 1: Clean duplicates
rm frontend/src/views/DashboardNew.vue
rm frontend/src/views/DashboardOld.vue

# Phase 2: Create new directories
mkdir -p frontend/src/components/common
mkdir -p frontend/src/components/dashboard/{cards,charts,widgets}
mkdir -p frontend/src/components/routers/modals
mkdir -p frontend/src/composables/{auth,data,utils,websocket}
mkdir -p frontend/src/views/public
mkdir -p frontend/src/views/auth
mkdir -p frontend/src/views/test

# Phase 3: Move files (examples)
# ... add move commands here

# Phase 4: Update imports
# Use find and sed to update import paths
```

## Testing After Migration

### Verify:
1. ✅ All routes load correctly
2. ✅ All components render
3. ✅ All imports resolve
4. ✅ No console errors
5. ✅ Build succeeds
6. ✅ All features work

### Test Commands:
```bash
# Check for broken imports
npm run build

# Run dev server
npm run dev

# Check for unused files
npx unimport

# Check for circular dependencies
npx madge --circular src/
```

## Rollback Plan

If issues arise:
1. Keep backup of original structure
2. Use git to revert changes
3. Restore from `DashboardOld.vue` if needed

## Summary

This reorganization will:
- ✅ Remove duplicate files
- ✅ Create logical directory structure
- ✅ Improve code discoverability
- ✅ Make the codebase more maintainable
- ✅ Follow Vue.js best practices
- ✅ Scale better as project grows

**Estimated Time:** 2-3 hours for complete migration
**Risk Level:** Medium (requires careful import updates)
**Benefit:** High (long-term maintainability)
