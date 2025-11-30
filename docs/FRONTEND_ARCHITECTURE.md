# Frontend Architecture Documentation

**Project**: TraidNet WiFi Hotspot Management System  
**Date**: Oct 28, 2025, 2:55 PM  
**Version**: 2.0 (Restructured)

---

## 📁 **Directory Structure**

The frontend is organized into three main modules:
1. **Common** - Shared components, views, and logic
2. **System Admin** - System administrator specific features
3. **Tenant** - Tenant-specific features

```
frontend/
├── src/
│   ├── modules/
│   │   ├── common/              # Shared across all user types
│   │   │   ├── components/      # Reusable UI components
│   │   │   ├── composables/     # Shared composables
│   │   │   ├── views/           # Common views (auth, public)
│   │   │   └── stores/          # Shared Pinia stores
│   │   │
│   │   ├── system-admin/        # System administrator module
│   │   │   ├── components/      # System admin components
│   │   │   ├── composables/     # System admin composables
│   │   │   ├── views/           # System admin views
│   │   │   └── stores/          # System admin stores
│   │   │
│   │   └── tenant/              # Tenant module
│   │       ├── components/      # Tenant components
│   │       ├── composables/     # Tenant composables
│   │       ├── views/           # Tenant views
│   │       └── stores/          # Tenant stores
│   │
│   ├── router/                  # Vue Router configuration
│   ├── stores/                  # Global Pinia stores
│   ├── plugins/                 # Vue plugins
│   ├── assets/                  # Static assets
│   ├── App.vue                  # Root component
│   └── main.js                  # Application entry point
│
├── public/                      # Public static files
├── docs/                        # Documentation
└── package.json                 # Dependencies
```

---

## 🔵 **Common Module**

**Purpose**: Shared components, composables, and views used across all user types.

### Components (`src/modules/common/components/`)

#### Layout Components
- **`layout/`** - Application layout components
  - `DashboardLayout.vue` - Main dashboard layout with sidebar and topbar
  - `AppSidebar.vue` - Sidebar navigation (role-based menu visibility)
  - `AppTopbar.vue` - Top navigation bar
  - `PublicLayout.vue` - Public pages layout

#### Base UI Components
- **`base/`** - Fundamental UI building blocks
  - `BaseButton.vue` - Button component
  - `BaseInput.vue` - Input field component
  - `BaseCard.vue` - Card container
  - `BaseModal.vue` - Modal dialog
  - `BaseTable.vue` - Data table
  - `BaseAlert.vue` - Alert/notification
  - `BaseBadge.vue` - Badge component
  - `BaseSpinner.vue` - Loading spinner
  - `BasePagination.vue` - Pagination controls
  - `BaseDropdown.vue` - Dropdown menu
  - `BaseTooltip.vue` - Tooltip component
  - `BaseTabs.vue` - Tab navigation

#### UI Components
- **`ui/`** - Higher-level UI components
  - `LoadingSpinner.vue` - Loading indicator
  - `ErrorMessage.vue` - Error display
  - `SuccessMessage.vue` - Success notification
  - `ConfirmDialog.vue` - Confirmation dialog

#### Auth Components
- **`auth/`** - Authentication related
  - `LoginForm.vue` - Login form
  - `RegisterForm.vue` - Registration form

#### Icons
- **`icons/`** - SVG icon components
  - `IconDashboard.vue`
  - `IconRouter.vue`
  - `IconUser.vue`
  - `IconPackage.vue`
  - `IconSettings.vue`

#### Other
- **`common/`** - Common utilities
  - `NotificationToast.vue` - Toast notifications
  - `SearchBar.vue` - Search input
  - `DatePicker.vue` - Date selection
  - `FileUpload.vue` - File upload

- **`debug/`** - Development tools
  - `WebSocketDebug.vue` - WebSocket testing

- `AppHeader.vue` - Application header

### Views (`src/modules/common/views/`)

#### Auth Views
- **`auth/`**
  - `LoginView.vue` - Login page
  - `TenantRegistrationView.vue` - Tenant registration
  - `VerifyEmailView.vue` - Email verification

#### Public Views
- **`public/`**
  - `PublicView.vue` - Public landing page
  - `AboutView.vue` - About page
  - `ContactView.vue` - Contact page
  - `PricingView.vue` - Pricing information
  - `FeaturesView.vue` - Features showcase

#### Test Views
- **`test/`**
  - `WebSocketTest.vue` - WebSocket testing
  - `ComponentShowcase.vue` - Component demo

### Composables (`src/modules/common/composables/`)

#### Auth Composables
- **`auth/`**
  - `useAuth.js` - Authentication logic
  - `usePermissions.js` - Permission checking

#### Utils Composables
- **`utils/`**
  - `useNotification.js` - Toast notifications
  - `useModal.js` - Modal management
  - `useDebounce.js` - Debounce utility
  - `useClipboard.js` - Clipboard operations
  - `useLocalStorage.js` - Local storage wrapper

#### WebSocket Composables
- **`websocket/`**
  - `useWebSocket.js` - WebSocket connection
  - `useEcho.js` - Laravel Echo integration
  - `useBroadcasting.js` - Broadcasting utilities
  - `usePresence.js` - Presence channels

### Stores (`src/modules/common/stores/`)

- `auth.js` - Authentication state
- `notification.js` - Notification state
- `theme.js` - Theme preferences

---

## 🔴 **System Admin Module**

**Purpose**: Features and components specific to system administrators.

### Components (`src/modules/system-admin/components/`)

**Currently**: No system admin specific components yet.

**Future Components**:
- `TenantManagement/` - Tenant CRUD operations
- `SystemMetrics/` - Platform-wide metrics
- `UserManagement/` - All users management
- `AuditLogs/` - System audit logs
- `SecurityAlerts/` - Security monitoring

### Views (`src/modules/system-admin/views/`)

- **`system/`**
  - `SystemDashboardNew.vue` - System admin dashboard (platform-wide stats)
  - `SystemDashboard.vue` - Legacy system dashboard (deprecated)

**Future Views**:
- `TenantManagementView.vue` - Manage all tenants
- `PlatformMetricsView.vue` - Platform analytics
- `SystemUsersView.vue` - All users across tenants
- `AuditLogsView.vue` - System-wide audit logs
- `SecurityMonitoringView.vue` - Security dashboard

### Composables (`src/modules/system-admin/composables/`)

**Future Composables**:
- `useTenantManagement.js` - Tenant CRUD operations
- `useSystemMetrics.js` - Platform metrics
- `useAuditLogs.js` - Audit log fetching
- `useSecurityMonitoring.js` - Security alerts

### Stores (`src/modules/system-admin/stores/`)

**Future Stores**:
- `tenants.js` - All tenants state
- `systemMetrics.js` - Platform metrics
- `auditLogs.js` - Audit logs state

---

## 🟢 **Tenant Module**

**Purpose**: Features and components specific to tenant administrators and users.

### Components (`src/modules/tenant/components/`)

#### Dashboard Components
- **`dashboard/`** - Dashboard widgets
  - `StatsCard.vue` - Statistics card
  - `RevenueChart.vue` - Revenue chart
  - `UsersChart.vue` - Users chart
  - `RouterStatusWidget.vue` - Router status
  - `RecentActivityWidget.vue` - Recent activity
  - `QuickActionsWidget.vue` - Quick actions
  - `AlertsWidget.vue` - Alerts and notifications
  - `PerformanceMetrics.vue` - Performance metrics
  - `TopPackages.vue` - Top selling packages
  - `ActiveSessions.vue` - Active hotspot sessions
  - `PaymentHistory.vue` - Recent payments
  - `SystemHealth.vue` - System health status
  - `NetworkTraffic.vue` - Network traffic graph
  - `UserGrowth.vue` - User growth chart

#### Router Components
- **`routers/`** - Router management
  - `RouterList.vue` - List of routers
  - `RouterCard.vue` - Router card display
  - `RouterForm.vue` - Add/edit router
  - `RouterDetails.vue` - Router details view
  - `RouterProvisioning.vue` - Router provisioning wizard

#### Package Components
- **`packages/`** - Package management
  - `PackageList.vue` - List of packages
  - `PackageCard.vue` - Package card
  - `PackageForm.vue` - Add/edit package
  - `PackageDetails.vue` - Package details
  - `PackagePricing.vue` - Pricing display

#### Payment Components
- **`payment/`** - Payment processing
  - `PaymentForm.vue` - Payment form
  - `PaymentHistory.vue` - Payment history

#### Session Components
- **`sessions/`** - Hotspot sessions
  - `SessionList.vue` - Active sessions list

#### User Components
- **`users/`** - User management
  - `UserList.vue` - List of users
  - `UserForm.vue` - Add/edit user
  - `UserDetails.vue` - User details

#### Other
- `PackageSelector.vue` - Package selection component

### Views (`src/modules/tenant/views/`)

#### Main Dashboard
- `Dashboard.vue` - Tenant dashboard (main view)

#### Dashboard Sub-Views
- **`dashboard/`** - Dashboard pages
  - **Admin Views**:
    - `AdminDashboard.vue` - Admin overview
    - `AdminUsers.vue` - User management
    - `AdminRouters.vue` - Router management
    - `AdminPackages.vue` - Package management
    - `AdminPayments.vue` - Payment management
    - `AdminReports.vue` - Reports
    - `AdminSettings.vue` - Settings
    - `AdminProfile.vue` - Admin profile
    - `AdminNotifications.vue` - Notifications
    - `AdminAuditLogs.vue` - Audit logs
    - `SystemUpdates.vue` - System updates
  
  - **Hotspot User Views**:
    - `HotspotUserDashboard.vue` - Hotspot user dashboard
    - `HotspotUserPackages.vue` - Available packages
    - `HotspotUserProfile.vue` - User profile
    - `HotspotUserPayments.vue` - Payment history
    - `HotspotUserSessions.vue` - Session history
  
  - **Router Management**:
    - `RouterManagement.vue` - Router list
    - `RouterDetails.vue` - Router details
    - `RouterProvisioning.vue` - Router setup
    - `RouterMonitoring.vue` - Router monitoring
    - `RouterConfiguration.vue` - Router config
  
  - **Package Management**:
    - `PackageManagement.vue` - Package list
    - `PackageCreate.vue` - Create package
    - `PackageEdit.vue` - Edit package
  
  - **User Management**:
    - `UserManagement.vue` - User list
    - `UserCreate.vue` - Create user
    - `UserEdit.vue` - Edit user
    - `UserDetails.vue` - User details
  
  - **Payment Management**:
    - `PaymentManagement.vue` - Payment list
    - `PaymentDetails.vue` - Payment details
    - `PaymentProcessing.vue` - Process payment
  
  - **Reports**:
    - `ReportsOverview.vue` - Reports dashboard
    - `RevenueReport.vue` - Revenue report
    - `UsageReport.vue` - Usage report
    - `UserReport.vue` - User report
  
  - **Settings**:
    - `GeneralSettings.vue` - General settings
    - `BillingSettings.vue` - Billing config
    - `NotificationSettings.vue` - Notifications
    - `SecuritySettings.vue` - Security settings
    - `IntegrationSettings.vue` - Integrations

#### Protected Views
- **`protected/`** - Protected tenant pages
  - Various tenant-specific protected views

### Composables (`src/modules/tenant/composables/`)

#### Data Composables
- **`data/`** - Data fetching and management
  - `useRouters.js` - Router data management
  - `usePackages.js` - Package data management
  - `useUsers.js` - User data management
  - `usePayments.js` - Payment data management
  - `useSessions.js` - Session data management
  - `useDashboardStats.js` - Dashboard statistics
  - `useReports.js` - Report generation

#### Other Composables
- `useRouterProvisioning.js` - Router provisioning workflow

### Stores (`src/modules/tenant/stores/`)

**Future Stores**:
- `routers.js` - Router state
- `packages.js` - Package state
- `users.js` - User state
- `payments.js` - Payment state
- `sessions.js` - Session state
- `dashboardStats.js` - Dashboard statistics

---

## 🔄 **Import Path Conventions**

### Importing from Common Module

```javascript
// Components
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import AppSidebar from '@/modules/common/components/layout/AppSidebar.vue'

// Composables
import { useAuth } from '@/modules/common/composables/auth/useAuth'
import { useNotification } from '@/modules/common/composables/utils/useNotification'

// Views
import LoginView from '@/modules/common/views/auth/LoginView.vue'

// Stores
import { useAuthStore } from '@/modules/common/stores/auth'
```

### Importing from System Admin Module

```javascript
// Components
import TenantManagement from '@/modules/system-admin/components/TenantManagement.vue'

// Composables
import { useSystemMetrics } from '@/modules/system-admin/composables/useSystemMetrics'

// Views
import SystemDashboardNew from '@/modules/system-admin/views/system/SystemDashboardNew.vue'

// Stores
import { useTenantsStore } from '@/modules/system-admin/stores/tenants'
```

### Importing from Tenant Module

```javascript
// Components
import RouterList from '@/modules/tenant/components/routers/RouterList.vue'
import PackageCard from '@/modules/tenant/components/packages/PackageCard.vue'

// Composables
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useRouterProvisioning } from '@/modules/tenant/composables/useRouterProvisioning'

// Views
import Dashboard from '@/modules/tenant/views/Dashboard.vue'
import AdminDashboard from '@/modules/tenant/views/dashboard/AdminDashboard.vue'

// Stores
import { useRoutersStore } from '@/modules/tenant/stores/routers'
```

---

## 🎨 **Module Boundaries**

### Common Module Rules
- ✅ Can be imported by System Admin module
- ✅ Can be imported by Tenant module
- ❌ Cannot import from System Admin module
- ❌ Cannot import from Tenant module

### System Admin Module Rules
- ✅ Can import from Common module
- ❌ Cannot import from Tenant module
- ✅ System admin specific features only

### Tenant Module Rules
- ✅ Can import from Common module
- ❌ Cannot import from System Admin module
- ✅ Tenant specific features only

---

## 📦 **Component Naming Conventions**

### Common Components
- **Base Components**: `Base[ComponentName].vue` (e.g., `BaseButton.vue`)
- **Layout Components**: `App[ComponentName].vue` (e.g., `AppSidebar.vue`)
- **Icon Components**: `Icon[IconName].vue` (e.g., `IconDashboard.vue`)

### Feature Components
- **List Components**: `[Feature]List.vue` (e.g., `RouterList.vue`)
- **Form Components**: `[Feature]Form.vue` (e.g., `RouterForm.vue`)
- **Card Components**: `[Feature]Card.vue` (e.g., `PackageCard.vue`)
- **Details Components**: `[Feature]Details.vue` (e.g., `UserDetails.vue`)
- **Widget Components**: `[Feature]Widget.vue` (e.g., `StatsWidget.vue`)

### View Naming
- **Dashboard Views**: `[Role]Dashboard.vue` (e.g., `AdminDashboard.vue`)
- **Management Views**: `[Feature]Management.vue` (e.g., `UserManagement.vue`)
- **Action Views**: `[Feature][Action].vue` (e.g., `PackageCreate.vue`)

---

## 🔧 **Configuration Files**

### Vite Configuration (`vite.config.js`)

```javascript
export default defineConfig({
  resolve: {
    alias: {
      '@': '/src',
      '@common': '/src/modules/common',
      '@system-admin': '/src/modules/system-admin',
      '@tenant': '/src/modules/tenant'
    }
  }
})
```

### Path Aliases

- `@/` - Root src directory
- `@common/` - Common module
- `@system-admin/` - System admin module
- `@tenant/` - Tenant module

---

## 🚀 **Best Practices**

### 1. Module Isolation
- Keep modules independent
- Use common module for shared code
- Avoid cross-module dependencies (except common)

### 2. Component Reusability
- Create generic components in common module
- Extend common components in feature modules
- Use props and slots for flexibility

### 3. Composable Organization
- One composable per file
- Group related composables in folders
- Export named functions, not default

### 4. State Management
- Use Pinia stores for global state
- Keep component-level state in components
- Use composables for shared logic

### 5. Code Splitting
- Lazy load routes with `() => import()`
- Split large components into smaller ones
- Use dynamic imports for heavy libraries

---

## 📊 **Module Statistics**

### Common Module
- **Components**: ~40 files
- **Views**: ~10 files
- **Composables**: ~15 files
- **Purpose**: Shared infrastructure

### System Admin Module
- **Components**: ~0 files (to be developed)
- **Views**: ~2 files
- **Composables**: ~0 files (to be developed)
- **Purpose**: Platform management

### Tenant Module
- **Components**: ~35 files
- **Views**: ~110 files
- **Composables**: ~8 files
- **Purpose**: Tenant operations

---

## 🔄 **Migration Guide**

### Updating Import Paths

**Old Structure**:
```javascript
import BaseButton from '@/components/base/BaseButton.vue'
import { useAuth } from '@/composables/auth/useAuth'
import Dashboard from '@/views/Dashboard.vue'
```

**New Structure**:
```javascript
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import { useAuth } from '@/modules/common/composables/auth/useAuth'
import Dashboard from '@/modules/tenant/views/Dashboard.vue'
```

### Using Path Aliases

```javascript
// Instead of long paths
import BaseButton from '@/modules/common/components/base/BaseButton.vue'

// Use aliases (if configured)
import BaseButton from '@common/components/base/BaseButton.vue'
```

---

## 📝 **Future Enhancements**

### System Admin Module
- [ ] Tenant management components
- [ ] Platform metrics dashboard
- [ ] System-wide user management
- [ ] Audit log viewer
- [ ] Security monitoring dashboard

### Tenant Module
- [ ] Advanced reporting components
- [ ] Real-time analytics widgets
- [ ] Bulk operations components
- [ ] Export/import utilities
- [ ] Advanced filtering components

### Common Module
- [ ] Advanced form components
- [ ] Rich text editor
- [ ] File manager component
- [ ] Advanced data table with sorting/filtering
- [ ] Chart components library

---

## 🎯 **Summary**

The frontend is now organized into three clear modules:

1. **Common** (`src/modules/common/`)
   - Shared components, views, composables
   - Used by both system admin and tenant modules
   - Foundation of the application

2. **System Admin** (`src/modules/system-admin/`)
   - Platform-wide management features
   - System administrator specific views
   - Monitoring and administration tools

3. **Tenant** (`src/modules/tenant/`)
   - Tenant-specific features
   - Router, package, user management
   - Dashboard and reporting

This structure provides:
- ✅ Clear separation of concerns
- ✅ Better code organization
- ✅ Easier maintenance
- ✅ Improved scalability
- ✅ Module independence

---

**Last Updated**: Oct 28, 2025, 2:55 PM  
**Status**: ✅ **RESTRUCTURED**  
**Version**: 2.0
