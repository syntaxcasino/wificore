# WiFi Hotspot Management System - Complete Project Structure

## 📁 Complete Directory Tree

```
wifi-hotspot/
│
├── 📂 backend/                          # Laravel API Backend
│   ├── app/
│   │   ├── Console/                     # Artisan commands
│   │   ├── Events/                      # Laravel events
│   │   ├── Exceptions/                  # Exception handlers
│   │   ├── Http/
│   │   │   ├── Controllers/             # API controllers
│   │   │   ├── Middleware/              # HTTP middleware
│   │   │   └── Requests/                # Form requests
│   │   ├── Jobs/                        # Queue jobs
│   │   ├── Models/                      # Eloquent models
│   │   ├── Providers/                   # Service providers
│   │   └── Services/                    # Business logic services
│   │
│   ├── bootstrap/                       # Application bootstrap
│   ├── config/                          # Configuration files
│   ├── database/
│   │   ├── factories/                   # Model factories
│   │   ├── migrations/                  # Database migrations
│   │   └── seeders/                     # Database seeders
│   │
│   ├── public/                          # Public assets
│   ├── resources/                       # Views & assets
│   ├── routes/
│   │   ├── api.php                      # API routes
│   │   ├── channels.php                 # Broadcast channels
│   │   └── web.php                      # Web routes
│   │
│   ├── storage/                         # Application storage
│   │   ├── app/                         # Application files
│   │   ├── framework/                   # Framework files
│   │   └── logs/                        # Log files
│   │
│   ├── tests/                           # Backend tests
│   │   ├── Feature/                     # Feature tests
│   │   └── Unit/                        # Unit tests
│   │
│   ├── vendor/                          # Composer dependencies
│   ├── .env                             # Environment variables
│   ├── artisan                          # Artisan CLI
│   ├── composer.json                    # PHP dependencies
│   └── phpunit.xml                      # PHPUnit configuration
│
├── 📂 frontend/                         # Vue.js Frontend
│   ├── public/                          # Static public files
│   │   ├── favicon.ico
│   │   └── index.html
│   │
│   ├── src/
│   │   ├── 📂 assets/                   # Static assets
│   │   │   ├── images/
│   │   │   ├── styles/
│   │   │   └── fonts/
│   │   │
│   │   ├── 📂 components/               # Vue components
│   │   │   ├── common/                  # ✅ Shared components
│   │   │   │   ├── Button.vue
│   │   │   │   ├── Modal.vue
│   │   │   │   ├── LoadingSpinner.vue
│   │   │   │   └── ErrorMessage.vue
│   │   │   │
│   │   │   ├── dashboard/               # ✅ Dashboard components
│   │   │   │   ├── cards/               # Stat cards
│   │   │   │   │   └── StatsCard.vue
│   │   │   │   ├── charts/              # Charts
│   │   │   │   │   ├── ActiveUsersChart.vue
│   │   │   │   │   ├── PaymentsChart.vue
│   │   │   │   │   └── RetentionRate.vue
│   │   │   │   ├── widgets/             # Widgets
│   │   │   │   │   ├── DataUsage.vue
│   │   │   │   │   ├── SessionLogs.vue
│   │   │   │   │   └── SystemLogs.vue
│   │   │   │   └── Packages.vue
│   │   │   │
│   │   │   ├── routers/                 # ✅ Router components
│   │   │   │   ├── RouterList.vue
│   │   │   │   └── modals/              # Router modals
│   │   │   │       ├── CreateRouterModal.vue
│   │   │   │       ├── UpdateRouterModal.vue
│   │   │   │       ├── RouterDetailsModal.vue
│   │   │   │       └── ProvisioningModal.vue
│   │   │   │
│   │   │   ├── packages/                # Package components
│   │   │   │   ├── PackageList.vue
│   │   │   │   ├── PackageCard.vue
│   │   │   │   └── PackageSelector.vue
│   │   │   │
│   │   │   ├── payments/                # Payment components
│   │   │   │   ├── PaymentModal.vue
│   │   │   │   └── PhoneInput.vue
│   │   │   │
│   │   │   ├── layout/                  # Layout components
│   │   │   │   ├── AppLayout.vue
│   │   │   │   ├── AppTopbar.vue
│   │   │   │   ├── AppSidebar.vue
│   │   │   │   └── PublicLayout.vue
│   │   │   │
│   │   │   ├── auth/                    # Auth components
│   │   │   │   ├── LoginForm.vue
│   │   │   │   └── AuthLayout.vue
│   │   │   │
│   │   │   ├── ui/                      # UI components
│   │   │   │   ├── AppFooter.vue
│   │   │   │   ├── DashboardSidebar.vue
│   │   │   │   ├── MobileMenu.vue
│   │   │   │   ├── SettingsDrawer.vue
│   │   │   │   └── Topbar.vue
│   │   │   │
│   │   │   ├── debug/                   # Debug tools
│   │   │   │   └── EventMonitor.vue
│   │   │   │
│   │   │   └── icons/                   # Icon components
│   │   │
│   │   ├── 📂 composables/              # ✅ Vue composables
│   │   │   ├── auth/                    # Authentication
│   │   │   │   └── useAuth.js
│   │   │   │
│   │   │   ├── data/                    # Data fetching
│   │   │   │   ├── index.js             # Barrel export
│   │   │   │   ├── useDashboard.js
│   │   │   │   ├── useRouters.js
│   │   │   │   ├── usePackages.js
│   │   │   │   ├── usePayments.js
│   │   │   │   └── useLogs.js
│   │   │   │
│   │   │   ├── utils/                   # Utilities
│   │   │   │   ├── index.js             # Barrel export
│   │   │   │   ├── useRouterUtils.js
│   │   │   │   └── useTheme.js
│   │   │   │
│   │   │   └── websocket/               # WebSocket
│   │   │       ├── index.js             # Barrel export
│   │   │       ├── useBroadcasting.js
│   │   │       ├── usePaymentWebSocket.js
│   │   │       └── useRouterProvisioning.js
│   │   │
│   │   ├── 📂 views/                    # ✅ Page components
│   │   │   ├── Dashboard.vue            # Main dashboard
│   │   │   │
│   │   │   ├── public/                  # Public pages
│   │   │   │   ├── HomeView.vue
│   │   │   │   ├── AboutView.vue
│   │   │   │   ├── NotFoundView.vue
│   │   │   │   └── PublicView.vue
│   │   │   │
│   │   │   ├── auth/                    # Auth pages
│   │   │   │   └── LoginView.vue
│   │   │   │
│   │   │   ├── dashboard/               # Dashboard pages
│   │   │   │   ├── routers/             # Router management
│   │   │   │   │   ├── RoutersView.vue
│   │   │   │   │   ├── RoutersLayout.vue
│   │   │   │   │   ├── MikrotikList.vue
│   │   │   │   │   ├── AddRouter.vue
│   │   │   │   │   ├── ApiConnectionStatus.vue
│   │   │   │   │   └── BackupConfigurations.vue
│   │   │   │   │
│   │   │   │   ├── hotspot/             # Hotspot features
│   │   │   │   │   ├── HotspotLayout.vue
│   │   │   │   │   ├── ActiveSessions.vue
│   │   │   │   │   ├── VouchersGenerate.vue
│   │   │   │   │   ├── VouchersBulk.vue
│   │   │   │   │   ├── VoucherTemplates.vue
│   │   │   │   │   ├── HotspotProfiles.vue
│   │   │   │   │   └── LoginPageCustomization.vue
│   │   │   │   │
│   │   │   │   ├── pppoe/               # PPPoE management
│   │   │   │   │   ├── PPPoELayout.vue
│   │   │   │   │   ├── PPPoESessions.vue
│   │   │   │   │   ├── AddPPPoEUser.vue
│   │   │   │   │   ├── RadiusProfiles.vue
│   │   │   │   │   ├── QueuesBandwidthControl.vue
│   │   │   │   │   └── AutoDisconnectRules.vue
│   │   │   │   │
│   │   │   │   ├── packages/            # Package management
│   │   │   │   │   ├── PackagesLayout.vue
│   │   │   │   │   ├── AllPackages.vue
│   │   │   │   │   ├── AddPackage.vue
│   │   │   │   │   ├── PackageGroups.vue
│   │   │   │   │   └── BandwidthLimitRules.vue
│   │   │   │   │
│   │   │   │   ├── users/               # User management
│   │   │   │   │   ├── UsersLayout.vue
│   │   │   │   │   ├── UserList.vue
│   │   │   │   │   ├── CreateUser.vue
│   │   │   │   │   ├── OnlineUsers.vue
│   │   │   │   │   ├── BlockedUsers.vue
│   │   │   │   │   └── UserGroups.vue
│   │   │   │   │
│   │   │   │   ├── billing/             # Billing & payments
│   │   │   │   │   ├── BillingLayout.vue
│   │   │   │   │   ├── Invoices.vue
│   │   │   │   │   ├── Payments.vue
│   │   │   │   │   ├── MpesaTransactions.vue
│   │   │   │   │   ├── WalletAccountBalance.vue
│   │   │   │   │   └── PaymentMethods.vue
│   │   │   │   │
│   │   │   │   ├── monitoring/          # Monitoring
│   │   │   │   │   ├── MonitoringLayout.vue
│   │   │   │   │   ├── LiveConnections.vue
│   │   │   │   │   ├── TrafficGraphs.vue
│   │   │   │   │   ├── SessionLogs.vue
│   │   │   │   │   ├── LatencyPingTests.vue
│   │   │   │   │   └── SystemLogs.vue
│   │   │   │   │
│   │   │   │   ├── reports/             # Reports
│   │   │   │   │   ├── ReportsLayout.vue
│   │   │   │   │   ├── DailyLoginReports.vue
│   │   │   │   │   ├── PaymentReports.vue
│   │   │   │   │   ├── ExpiredAccounts.vue
│   │   │   │   │   ├── UserSessionHistory.vue
│   │   │   │   │   └── BandwidthUsageSummary.vue
│   │   │   │   │
│   │   │   │   ├── settings/            # Settings
│   │   │   │   │   ├── SettingsLayout.vue
│   │   │   │   │   ├── GeneralSettings.vue
│   │   │   │   │   ├── MikrotikApiCredentials.vue
│   │   │   │   │   ├── RadiusServerSettings.vue
│   │   │   │   │   ├── EmailSmsSettings.vue
│   │   │   │   │   ├── MpesaApiKeys.vue
│   │   │   │   │   └── TimezoneLocale.vue
│   │   │   │   │
│   │   │   │   ├── admin/               # Admin tools
│   │   │   │   │   ├── AdminToolsLayout.vue
│   │   │   │   │   ├── RolesPermissions.vue
│   │   │   │   │   ├── ActivityLogs.vue
│   │   │   │   │   ├── BackupRestore.vue
│   │   │   │   │   ├── CacheManagement.vue
│   │   │   │   │   └── SystemUpdates.vue
│   │   │   │   │
│   │   │   │   ├── support/             # Support
│   │   │   │   │   ├── SupportLayout.vue
│   │   │   │   │   ├── CreateTicket.vue
│   │   │   │   │   ├── AllTickets.vue
│   │   │   │   │   ├── TicketCategories.vue
│   │   │   │   │   └── ResponseTemplates.vue
│   │   │   │   │
│   │   │   │   ├── logs/                # Logs
│   │   │   │   │   ├── LogsLayout.vue
│   │   │   │   │   ├── SystemLogs.vue
│   │   │   │   │   └── AccessLogs.vue
│   │   │   │   │
│   │   │   │   ├── Overview.vue
│   │   │   │   ├── Users.vue
│   │   │   │   ├── Payments.vue
│   │   │   │   ├── Logs.vue
│   │   │   │   ├── SystemHealth.vue
│   │   │   │   └── DailyWeeklyStatistics.vue
│   │   │   │
│   │   │   ├── protected/               # Protected routes
│   │   │   │   ├── ClientsView.vue
│   │   │   │   ├── ReportsView.vue
│   │   │   │   ├── SettingsView.vue
│   │   │   │   └── hotspot/
│   │   │   │       └── PaymentsView.vue
│   │   │   │
│   │   │   └── test/                    # Test pages
│   │   │       └── WebSocketTestView.vue
│   │   │
│   │   ├── 📂 router/                   # Vue Router
│   │   │   └── index.js                 # Route definitions
│   │   │
│   │   ├── 📂 stores/                   # Pinia stores
│   │   │   ├── auth.js                  # Auth store
│   │   │   ├── dashboard.js             # Dashboard store
│   │   │   └── theme.js                 # Theme store
│   │   │
│   │   ├── 📂 plugins/                  # Vue plugins
│   │   │   ├── axios.js                 # Axios setup
│   │   │   └── echo.js                  # Laravel Echo
│   │   │
│   │   ├── App.vue                      # Root component
│   │   └── main.js                      # Entry point
│   │
│   ├── tests/                           # Frontend tests
│   │   ├── unit/                        # Unit tests
│   │   └── e2e/                         # E2E tests
│   │
│   ├── .env                             # Environment variables
│   ├── vite.config.js                   # Vite configuration
│   ├── package.json                     # NPM dependencies
│   ├── tailwind.config.js               # Tailwind CSS config
│   └── index.html                       # HTML entry point
│
├── 📂 docs/                             # ✅ All documentation (77 files)
│   ├── README.md                        # Documentation index
│   ├── PROJECT_STRUCTURE.md             # This file
│   ├── FRONTEND_STRUCTURE_GUIDE.md      # Frontend guide
│   ├── DASHBOARD_REDESIGN.md            # Dashboard docs
│   ├── TESTING_COMPLETE.md              # Testing docs
│   └── ... (72 other documentation files)
│
├── 📂 freeradius/                       # FreeRADIUS configuration
│   ├── clients.conf                     # RADIUS clients
│   ├── radiusd.conf                     # Main config
│   └── users                            # User definitions
│
├── 📂 nginx/                            # Nginx configuration
│   ├── nginx.conf                       # Main config
│   └── sites-available/                 # Site configs
│
├── 📂 postgres/                         # PostgreSQL configuration
│   └── init.sql                         # Initial DB setup
│
├── 📂 soketi/                           # WebSocket server config
│   └── config.json                      # Soketi configuration
│
├── 📂 scripts/                          # Utility scripts
│   ├── deploy.sh                        # Deployment script
│   ├── backup.sh                        # Backup script
│   └── restore.sh                       # Restore script
│
├── 📂 storage/                          # Application storage
│   ├── app/                             # Application files
│   ├── logs/                            # Log files
│   └── backups/                         # Backup files
│
├── 📂 tests/                            # Integration tests
│   └── integration/                     # Integration test suites
│
├── 📄 docker-compose.yml                # Docker Compose config
├── 📄 README.md                         # Project README
└── 📄 reorganize-frontend.ps1           # Frontend reorganization script
```

## 📊 Statistics

### Frontend Organization
- **Total Components:** 100+ Vue components
- **Composables:** 12 organized composables
- **Views:** 80+ page components
- **Structure:** 3-level deep maximum

### Backend Organization
- **Controllers:** 30+ API controllers
- **Models:** 25+ Eloquent models
- **Jobs:** 15+ queue jobs
- **Services:** 10+ service classes

### Documentation
- **Total Files:** 77 documentation files
- **Categories:** 8 main categories
- **Size:** ~500KB total documentation

## 🎯 Key Directories Explained

### Frontend

#### `/components/common/`
Reusable UI components used across the entire application.

#### `/components/dashboard/`
Dashboard-specific components organized by type:
- `cards/` - Metric cards
- `charts/` - Data visualizations
- `widgets/` - Dashboard widgets

#### `/components/routers/`
Router management components with modals for CRUD operations.

#### `/composables/`
Vue 3 composables for business logic:
- `auth/` - Authentication logic
- `data/` - Data fetching and state
- `utils/` - Utility functions
- `websocket/` - Real-time communication

#### `/views/`
Page-level components organized by section:
- `public/` - Public-facing pages
- `auth/` - Authentication pages
- `dashboard/` - Protected dashboard pages
- `test/` - Testing pages

### Backend

#### `/app/Http/Controllers/`
API controllers handling HTTP requests.

#### `/app/Models/`
Eloquent models representing database tables.

#### `/app/Services/`
Business logic services (e.g., MikroTik API, Payment processing).

#### `/app/Jobs/`
Queue jobs for background processing.

#### `/database/migrations/`
Database schema migrations.

## 🔄 Data Flow

```
User Request
    ↓
Vue Router
    ↓
View Component
    ↓
Composable (Business Logic)
    ↓
Axios (HTTP Client)
    ↓
Laravel API
    ↓
Controller
    ↓
Service Layer
    ↓
Model (Database)
    ↓
Response
    ↓
Composable (State Update)
    ↓
View Component (Re-render)
```

## 🌐 WebSocket Flow

```
Backend Event
    ↓
Laravel Broadcasting
    ↓
Soketi (WebSocket Server)
    ↓
Laravel Echo (Frontend)
    ↓
Composable (useBroadcasting)
    ↓
Component (Real-time Update)
```

## 📦 Build Artifacts

### Frontend Build
```
frontend/dist/
├── assets/
│   ├── index-[hash].js      # Main bundle
│   ├── index-[hash].css     # Styles
│   └── vendor-[hash].js     # Vendor bundle
└── index.html               # Entry HTML
```

### Backend Build
```
backend/public/
├── index.php                # Entry point
├── css/                     # Compiled CSS
├── js/                      # Compiled JS
└── storage/                 # Symlink to storage
```

## 🎯 Import Path Examples

### Components
```javascript
// Common components
import Button from '@/components/common/Button.vue'
import Modal from '@/components/common/Modal.vue'

// Dashboard components
import StatsCard from '@/components/dashboard/cards/StatsCard.vue'
import ActiveUsersChart from '@/components/dashboard/charts/ActiveUsersChart.vue'

// Router components
import CreateRouterModal from '@/components/routers/modals/CreateRouterModal.vue'
```

### Composables
```javascript
// Authentication
import { useAuth } from '@/composables/auth/useAuth'

// Data (with barrel exports)
import { useDashboard, useRouters } from '@/composables/data'

// Utils
import { useRouterUtils } from '@/composables/utils'

// WebSocket
import { useBroadcasting } from '@/composables/websocket'
```

### Views
```javascript
// Public views
import HomeView from '@/views/public/HomeView.vue'

// Dashboard views
import DashboardView from '@/views/Dashboard.vue'
import RoutersView from '@/views/dashboard/routers/RoutersView.vue'

// Auth views
import LoginView from '@/views/auth/LoginView.vue'
```

## ✅ Organization Principles

1. **Separation of Concerns** - Views, Components, Logic separated
2. **Feature-Based** - Grouped by feature, not file type
3. **Consistent Naming** - PascalCase for components, camelCase for composables
4. **Shallow Hierarchy** - Maximum 3 levels deep
5. **Clear Purpose** - Each file has one clear responsibility
6. **Barrel Exports** - Cleaner imports with index.js files

## 🚀 Quick Navigation

- **Add new component** → `components/[feature]/`
- **Add new page** → `views/dashboard/[feature]/`
- **Add business logic** → `composables/[type]/`
- **Add documentation** → `docs/`
- **Add backend logic** → `backend/app/Services/`

---

**Last Updated:** 2025-10-08  
**Status:** ✅ Complete and Organized  
**Total Files:** 500+ organized files
