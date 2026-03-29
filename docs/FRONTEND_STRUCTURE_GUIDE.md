# Frontend Structure Quick Reference Guide

## 📁 Directory Overview

```
frontend/src/
├── 📂 components/          # Reusable UI components
├── 📂 composables/         # Vue composables (business logic)
├── 📂 views/              # Page-level components
├── 📂 router/             # Vue Router config
├── 📂 stores/             # Pinia state management
├── 📂 assets/             # Static assets
└── 📂 plugins/            # Vue plugins
```

## 🎯 Where to Put New Files

### Adding a New Component

**Question:** Is it reusable across multiple pages?

**YES** → `components/`
- Common UI: `components/common/`
- Feature-specific: `components/[feature]/`

**NO** → Keep it in the view file or create a local component

### Adding a New Page

**Question:** What section does it belong to?

- **Public pages** → `views/public/`
- **Auth pages** → `views/auth/`
- **Dashboard pages** → `views/dashboard/[feature]/`

### Adding Business Logic

**Question:** What type of logic?

- **Data fetching** → `composables/data/`
- **Utilities** → `composables/utils/`
- **WebSocket** → `composables/websocket/`
- **Authentication** → `composables/auth/`

## 📋 File Naming Conventions

### Components
```
✅ PascalCase with descriptive names
✅ RouterList.vue
✅ PaymentModal.vue
✅ CreateRouterModal.vue

❌ routerlist.vue
❌ router-list.vue
❌ Overlay.vue (too generic)
```

### Views
```
✅ PascalCase with "View" suffix
✅ DashboardView.vue
✅ RoutersView.vue
✅ LoginView.vue

❌ Dashboard.vue (in views/)
❌ routers.vue
❌ login-page.vue
```

### Composables
```
✅ camelCase with "use" prefix
✅ useAuth.js
✅ useRouters.js
✅ usePaymentWebSocket.js

❌ auth.js
❌ UseAuth.js
❌ use-auth.js
```

## 🗂️ Component Organization

### Dashboard Components

```
components/dashboard/
├── cards/              # Stat/metric cards
│   ├── StatsCard.vue
│   ├── FinancialCard.vue
│   └── NetworkCard.vue
│
├── charts/             # Data visualization
│   ├── ActiveUsersChart.vue
│   ├── PaymentsChart.vue
│   └── RetentionRate.vue
│
└── widgets/            # Dashboard widgets
    ├── DataUsage.vue
    ├── SessionLogs.vue
    └── SystemLogs.vue
```

### Router Components

```
components/routers/
├── RouterList.vue          # List of routers
├── RouterCard.vue          # Single router card
├── RouterForm.vue          # Router form
└── modals/                 # Modal dialogs
    ├── CreateRouterModal.vue
    ├── UpdateRouterModal.vue
    ├── RouterDetailsModal.vue
    └── ProvisioningModal.vue
```

## 🔧 Composables Organization

### Data Composables
```javascript
// composables/data/
useDashboard.js     // Dashboard statistics
useRouters.js       // Router CRUD operations
usePackages.js      // Package management
usePayments.js      // Payment processing
useLogs.js          // Log fetching

// Usage
import { useDashboard } from '@/composables/data/useDashboard'
// OR
import { useDashboard, useRouters } from '@/composables/data'
```

### Utility Composables
```javascript
// composables/utils/
useRouterUtils.js   // Router formatting utilities
useChartData.js     // Chart data processing
useTheme.js         // Theme management

// Usage
import { useRouterUtils } from '@/composables/utils/useRouterUtils'
```

### WebSocket Composables
```javascript
// composables/websocket/
useBroadcasting.js          // Laravel Echo
usePaymentWebSocket.js      // Payment updates
useRouterProvisioning.js    // Router provisioning

// Usage
import { useBroadcasting } from '@/composables/websocket/useBroadcasting'
```

## 📄 Views Organization

### Dashboard Views Structure

```
views/dashboard/
├── DashboardView.vue           # Main dashboard

├── routers/                    # Router management
│   ├── RoutersView.vue
│   └── RouterDetailsView.vue

├── hotspot/                    # Hotspot features
│   ├── HotspotView.vue
│   ├── ActiveSessionsView.vue
│   ├── ProfilesView.vue
│   └── VouchersView.vue

├── packages/                   # Package management
│   ├── PackagesView.vue
│   └── PackageDetailsView.vue

├── billing/                    # Billing & payments
│   ├── PaymentsView.vue
│   ├── InvoicesView.vue
│   └── MpesaTransactionsView.vue

├── monitoring/                 # Network monitoring
│   ├── LiveConnectionsView.vue
│   ├── SessionLogsView.vue
│   └── TrafficGraphsView.vue

└── settings/                   # Settings
    ├── SettingsView.vue
    └── GeneralSettingsView.vue
```

## 🎨 Import Path Examples

### Before Reorganization
```javascript
// ❌ Old messy imports
import { useAuth } from '@/composables/useAuth'
import { useDashboard } from '@/composables/useDashboard'
import { useRouters } from '@/composables/useRouters'
import RouterManagement from '@/components/dashboard/RouterManagement.vue'
import createOverlay from '@/components/dashboard/routers/createOverlay.vue'
```

### After Reorganization
```javascript
// ✅ New organized imports
import { useAuth } from '@/composables/auth/useAuth'
import { useDashboard, useRouters } from '@/composables/data'
import RoutersView from '@/views/dashboard/routers/RoutersView.vue'
import CreateRouterModal from '@/components/routers/modals/CreateRouterModal.vue'
```

## 🚀 Common Patterns

### Creating a New Feature

**Example: Adding "Reports" feature**

1. **Create view:**
   ```
   views/dashboard/reports/ReportsView.vue
   ```

2. **Create components (if needed):**
   ```
   components/reports/ReportCard.vue
   components/reports/ReportFilters.vue
   ```

3. **Create composable:**
   ```
   composables/data/useReports.js
   ```

4. **Add route:**
   ```javascript
   // router/index.js
   {
     path: '/dashboard/reports',
     component: () => import('@/views/dashboard/reports/ReportsView.vue')
   }
   ```

### Creating a Reusable Component

**Example: Custom Button**

1. **Determine category:**
   - Common UI → `components/common/`
   - Feature-specific → `components/[feature]/`

2. **Create component:**
   ```
   components/common/CustomButton.vue
   ```

3. **Use in views:**
   ```vue
   <script setup>
   import CustomButton from '@/components/common/CustomButton.vue'
   </script>
   ```

### Creating a Composable

**Example: User Management**

1. **Determine type:**
   - Data fetching → `composables/data/`
   - Utilities → `composables/utils/`

2. **Create file:**
   ```
   composables/data/useUsers.js
   ```

3. **Export from index:**
   ```javascript
   // composables/data/index.js
   export { useUsers } from './useUsers'
   ```

4. **Use in components:**
   ```javascript
   import { useUsers } from '@/composables/data'
   ```

## 📊 Decision Tree

### "Where should this file go?"

```
START
  │
  ├─ Is it a page/route?
  │  └─ YES → views/
  │      ├─ Public? → views/public/
  │      ├─ Auth? → views/auth/
  │      └─ Dashboard? → views/dashboard/[feature]/
  │
  ├─ Is it reusable UI?
  │  └─ YES → components/
  │      ├─ Common? → components/common/
  │      ├─ Feature-specific? → components/[feature]/
  │      └─ Modal? → components/[feature]/modals/
  │
  ├─ Is it business logic?
  │  └─ YES → composables/
  │      ├─ Data fetching? → composables/data/
  │      ├─ Utilities? → composables/utils/
  │      ├─ WebSocket? → composables/websocket/
  │      └─ Auth? → composables/auth/
  │
  └─ Is it configuration?
      └─ YES → config/ or plugins/
```

## ✅ Best Practices

### DO:
- ✅ Use descriptive, specific names
- ✅ Group related files together
- ✅ Keep components small and focused
- ✅ Use barrel exports (index.js) for cleaner imports
- ✅ Follow the established naming conventions
- ✅ Put shared logic in composables
- ✅ Keep views thin, move logic to composables

### DON'T:
- ❌ Create deeply nested directories (max 3 levels)
- ❌ Use generic names (Overlay.vue, Helper.js)
- ❌ Mix different concerns in one file
- ❌ Duplicate code across components
- ❌ Put business logic in components
- ❌ Create circular dependencies
- ❌ Use inconsistent naming

## 🔍 Finding Files

### Quick Reference

**Need to find...**
- **A page?** → Check `views/`
- **A reusable component?** → Check `components/`
- **Business logic?** → Check `composables/`
- **Router config?** → Check `router/index.js`
- **Global state?** → Check `stores/`

### Search Tips

```bash
# Find all views
find src/views -name "*.vue"

# Find all composables
find src/composables -name "*.js"

# Find specific feature
find src -name "*router*" -o -name "*Router*"
```

## 📝 Summary

**Key Principles:**
1. **Separation of Concerns** - Views, Components, Logic
2. **Feature-Based Organization** - Group by feature, not type
3. **Consistent Naming** - Follow conventions strictly
4. **Shallow Hierarchy** - Max 3 directory levels
5. **Clear Purpose** - Each file has one clear responsibility

**When in doubt:**
- Ask: "Is this reusable?"
- Ask: "What feature does this belong to?"
- Ask: "Is this UI or logic?"
- Follow the decision tree above
