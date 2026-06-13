import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

// Lazy imports — dashboard shell is large enough to keep out of the entry chunk
const DashboardLayout = () => import('@/modules/common/components/layout/DashboardLayout.vue')

// Lazy imports — everything else is code-split for better performance
const PackagesView = () => import('@/modules/common/views/public/PackagesView.vue')
const LoginView = () => import('@/modules/common/views/auth/LoginView.vue')
const VerifyEmailView = () => import('@/modules/common/views/auth/VerifyEmailView.vue')
const DashboardRouter = () => import('@/modules/common/views/DashboardRouter.vue')

// Users
const UsersLayout = () => import('@/modules/tenant/views/dashboard/users/UsersLayout.vue')
const CreateUser = () => import('@/modules/tenant/views/dashboard/users/CreateUser.vue')
const BlockedUsers = () => import('@/modules/tenant/views/dashboard/users/BlockedUsers.vue')
const UserGroups = () => import('@/modules/tenant/views/dashboard/users/UserGroups.vue')

// Hotspot
const HotspotLayout = () => import('@/modules/tenant/views/dashboard/hotspot/HotspotLayout.vue')
const VouchersGenerate = () => import('@/modules/tenant/views/dashboard/hotspot/VouchersGenerateNew.vue')
const HotspotProfiles = () => import('@/modules/tenant/views/dashboard/hotspot/HotspotProfiles.vue')
const LoginPageCustomization = () => import('@/modules/tenant/views/dashboard/hotspot/LoginPageCustomization.vue')

// PPPoE
const PPPoELayout = () => import('@/modules/tenant/views/dashboard/pppoe/PPPoELayout.vue')

// Billing
const BillingLayout = () => import('@/modules/tenant/views/dashboard/billing/BillingLayout.vue')
const Invoices = () => import('@/modules/tenant/views/dashboard/billing/InvoicesNew.vue')
const Payments = () => import('@/modules/tenant/views/dashboard/billing/PaymentsNew.vue')
const MpesaTransactions = () => import('@/modules/tenant/views/dashboard/billing/MpesaTransactionsNew.vue')
const WalletAccountBalance = () => import('@/modules/tenant/views/dashboard/billing/WalletAccountBalanceNew.vue')
const PaymentMethods = () => import('@/modules/tenant/views/dashboard/billing/PaymentMethodsNew.vue')

// Packages
const AllPackages = () => import('@/modules/tenant/views/dashboard/packages/AllPackagesNew.vue')
const AddPackage = () => import('@/modules/tenant/views/dashboard/packages/AddPackageNew.vue')
const PackageGroups = () => import('@/modules/tenant/views/dashboard/packages/PackageGroupsNew.vue')
const BandwidthLimitRules = () => import('@/modules/tenant/views/dashboard/packages/BandwidthLimitRules.vue')

// Routers
const RoutersLayout = () => import('@/modules/tenant/views/dashboard/routers/RoutersLayout.vue')
const MikrotikList = () => import('@/modules/tenant/views/dashboard/routers/MikrotikList.vue')
const AddRouter = () => import('@/modules/tenant/views/dashboard/routers/AddRouter.vue')
const ApiConnectionStatus = () => import('@/modules/tenant/views/dashboard/routers/ApiConnectionStatus.vue')
const BackupConfigurations = () => import('@/modules/tenant/views/dashboard/routers/BackupConfigurations.vue')

// Monitoring
const MonitoringLayout = () => import('@/modules/tenant/views/dashboard/monitoring/MonitoringLayout.vue')
const LiveConnections = () => import('@/modules/tenant/views/dashboard/monitoring/LiveConnectionsNew.vue')
const SystemLogs = () => import('@/modules/tenant/views/dashboard/monitoring/SystemLogsNew.vue')
const TrafficGraphs = () => import('@/modules/tenant/views/dashboard/monitoring/TrafficGraphsNew.vue')
const SessionLogs = () => import('@/modules/tenant/views/dashboard/monitoring/SessionLogsNew.vue')
const LatencyPingTests = () => import('@/modules/tenant/views/dashboard/monitoring/LatencyPingTests.vue')

// Reports
const ReportsLayout = () => import('@/modules/tenant/views/dashboard/reports/ReportsLayout.vue')
const DailyLoginReports = () => import('@/modules/tenant/views/dashboard/reports/DailyLoginReportsNew.vue')
const PaymentReports = () => import('@/modules/tenant/views/dashboard/reports/PaymentReportsNew.vue')
const UserSessionHistory = () => import('@/modules/tenant/views/dashboard/reports/UserSessionHistoryNew.vue')
const BandwidthUsageSummary = () => import('@/modules/tenant/views/dashboard/reports/BandwidthUsageSummaryNew.vue')
const ExpiredAccounts = () => import('@/modules/tenant/views/dashboard/reports/ExpiredAccounts.vue')

// Support
const SupportLayout = () => import('@/modules/tenant/views/dashboard/support/SupportLayout.vue')
const AllTickets = () => import('@/modules/tenant/views/dashboard/support/AllTicketsNew.vue')
const CreateTicket = () => import('@/modules/tenant/views/dashboard/support/CreateTicketNew.vue')
const TicketCategories = () => import('@/modules/tenant/views/dashboard/support/TicketCategories.vue')
const ResponseTemplates = () => import('@/modules/tenant/views/dashboard/support/ResponseTemplates.vue')

// Settings
const SettingsLayout = () => import('@/modules/tenant/views/dashboard/settings/SettingsLayout.vue')
const GeneralSettings = () => import('@/modules/tenant/views/dashboard/settings/GeneralSettingsNew.vue')
const MikrotikApiCredentials = () => import('@/modules/tenant/views/dashboard/settings/MikrotikApiCredentialsNew.vue')
const RadiusServerSettings = () => import('@/modules/tenant/views/dashboard/settings/RadiusServerSettingsNew.vue')
const CommunicationChannels = () => import('@/modules/tenant/views/dashboard/settings/CommunicationChannels.vue')
const MpesaApiKeys = () => import('@/modules/tenant/views/dashboard/settings/MpesaApiKeysNew.vue')
const TimezoneLocale = () => import('@/modules/tenant/views/dashboard/settings/TimezoneLocaleNew.vue')

// Admin Tools
const AdminToolsLayout = () => import('@/modules/tenant/views/dashboard/admin/AdminToolsLayout.vue')
const RolesPermissions = () => import('@/modules/tenant/views/dashboard/admin/RolesPermissionsNew.vue')
const ActivityLogs = () => import('@/modules/tenant/views/dashboard/admin/ActivityLogsNew.vue')
const BackupRestore = () => import('@/modules/tenant/views/dashboard/admin/BackupRestoreNew.vue')
const CacheManagement = () => import('@/modules/tenant/views/dashboard/admin/CacheManagement.vue')
const SystemUpdates = () => import('@/modules/tenant/views/dashboard/admin/SystemUpdates.vue')

// PPPoE Customer Portal
const PppoePortalLogin = () => import('@/modules/pppoe-portal/views/PppoePortalLogin.vue')
const PppoePortalDashboard = () => import('@/modules/pppoe-portal/views/PppoePortalDashboard.vue')
const PppoePortalHistory = () => import('@/modules/pppoe-portal/views/PppoePortalHistory.vue')

// Dev/Test
const WebSocketTest = () => import('@/modules/common/views/test/WebSocketTestView.vue')
const ComponentShowcase = () => import('@/modules/common/views/test/ComponentShowcase.vue')

// Todos
const TodosView = () => import('@/modules/tenant/views/todos/TodosView.vue')

// HR
const DepartmentsView = () => import('@/modules/tenant/views/hr/DepartmentsView.vue')
const PositionsView = () => import('@/modules/tenant/views/hr/PositionsView.vue')
const EmployeesView = () => import('@/modules/tenant/views/hr/EmployeesView.vue')

// Finance
const ExpensesView = () => import('@/modules/tenant/views/finance/ExpensesView.vue')
const RevenuesView = () => import('@/modules/tenant/views/finance/RevenuesView.vue')

const routes = [
  // Public admin/tenant login (default homepage)
  { path: '/', name: 'home', redirect: '/login' },
  { path: '/login', name: 'login', component: LoginView },
  { path: '/register', name: 'register', component: () => import('@/modules/common/views/auth/TenantRegistrationView.vue'), alias: '/register/tenant' },
  { path: '/email/verify/:id/:hash', name: 'verify-email', component: VerifyEmailView },
  { path: '/register/verify/:token', name: 'verify-registration', component: VerifyEmailView },
  
  // Captive Portal - Only accessible for hotspot users
  { path: '/portal', name: 'captive-portal', component: PackagesView },
  { path: '/hotspot', redirect: '/portal' },
  { path: '/hotspot/login', redirect: '/portal' },
  
  { path: '/websocket-test', name: 'websocket-test', component: WebSocketTest, meta: { requiresAuth: true } },
  { path: '/component-showcase', name: 'component-showcase', component: ComponentShowcase, meta: { requiresAuth: true } },

  // System Admin routes (with layout) — all under /system/*
  {
    path: '/system',
    component: DashboardLayout,
    meta: { requiresAuth: true, requiresRole: 'system_admin' },
    children: [
      { 
        path: 'dashboard', 
        name: 'system.dashboard',
        component: () => import('@/modules/system-admin/views/system/SystemDashboardNew.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'tenants',
        name: 'system.tenants',
        component: () => import('@/modules/system-admin/views/tenants/TenantsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'admins',
        name: 'system.admins',
        component: () => import('@/modules/system-admin/views/admins/SystemAdminsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'billing/configuration',
        name: 'system.billing.config',
        component: () => import('@/modules/system-admin/views/billing/BillingConfigView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'billing/metrics',
        name: 'system.billing.metrics',
        component: () => import('@/modules/system-admin/views/billing/BillingMetricsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'billing/overrides',
        name: 'system.billing.overrides',
        component: () => import('@/modules/system-admin/views/billing/BillingOverridesView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'ip-pools',
        name: 'system.ip-pools',
        component: () => import('@/modules/system-admin/views/network/IpPoolsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'health',
        name: 'system.health',
        component: () => import('@/modules/system-admin/views/monitoring/SystemHealthView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'metrics',
        name: 'system.metrics',
        component: () => import('@/modules/system-admin/views/monitoring/SystemMetricsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'activity-logs',
        name: 'system.activity-logs',
        component: () => import('@/modules/system-admin/views/monitoring/ActivityLogsView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
      {
        path: 'script-preview',
        name: 'system.script-preview',
        component: () => import('@/modules/system-admin/views/tools/ScriptPreviewView.vue'),
        meta: { requiresAuth: true, requiresRole: 'system_admin' }
      },
    ]
  },

  {
    path: '/dashboard',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'overview', component: DashboardRouter },

      {
        path: '/dashboard/users',
        component: UsersLayout,
        children: [
          { path: 'all', name: 'users.all', component: () => import('@/modules/tenant/views/dashboard/users/UserListNew.vue') },
          { path: 'create', name: 'users.create', component: CreateUser },
          { path: 'roles', name: 'users.roles', component: () => import('@/modules/tenant/views/dashboard/users/RolesPermissions.vue') },
          { path: 'online', name: 'users.online', component: () => import('@/modules/tenant/views/dashboard/users/OnlineUsersNew.vue') },
        ],
      },

      {
        path: 'hotspot',
        component: HotspotLayout,
        children: [
          { path: '', redirect: '/dashboard/hotspot/users' },
          { path: 'users', name: 'hotspot.users', component: () => import('@/modules/tenant/views/dashboard/hotspot/HotspotUsers.vue') },
          { path: 'sessions', name: 'hotspot.sessions', component: () => import('@/modules/tenant/views/dashboard/hotspot/ActiveSessionsNew.vue') },
          {
            path: 'vouchers',
            name: 'hotspot.vouchers',
            component: VouchersGenerate,
          },
          { path: 'profiles', name: 'hotspot.profiles', component: HotspotProfiles },
          { path: 'login-page', name: 'hotspot.login-page', component: LoginPageCustomization },
        ],
      },

      {
        path: 'pppoe',
        component: PPPoELayout,
        children: [
          { path: 'users', name: 'pppoe.users', component: () => import('@/modules/tenant/views/dashboard/pppoe/PPPoEUsers.vue') },
        ],
      },

      {
        path: 'billing',
        component: BillingLayout,
        children: [
          // { path: '', redirect: 'invoices' },
          { path: 'invoices', name: 'billing.invoices', component: Invoices },
          { path: 'payments', name: 'billing.payments', component: Payments },
          { path: 'mpesa', name: 'billing.mpesa', component: MpesaTransactions },
          { path: 'wallet', name: 'billing.wallet', component: WalletAccountBalance },
          { path: 'payment-methods', name: 'billing.payment-methods', component: PaymentMethods },
        ],
      },

      // Packages Module
      {
        path: 'packages/all',
        name: 'packages',
        component: AllPackages,
        meta: { requiresAuth: true }
      },
      {
        path: 'packages/add',
        name: 'packages.add',
        component: AddPackage,
        meta: { requiresAuth: true }
      },
      {
        path: 'packages/groups',
        name: 'packages.groups',
        component: PackageGroups,
        meta: { requiresAuth: true }
      },

      {
        path: 'routers',
        component: RoutersLayout,
        children: [
          //{ path: '', redirect: 'mikrotik' },
          { path: 'mikrotik', name: 'routers.mikrotik', component: MikrotikList },
          { path: 'access-points', name: 'routers.access-points', component: () => import('@/modules/tenant/views/dashboard/routers/AccessPointsView.vue') },
          { path: 'add', name: 'routers.add', component: AddRouter },
          { path: 'api-status', name: 'routers.api-status', component: ApiConnectionStatus },
          { path: 'backup', name: 'routers.backup', component: BackupConfigurations },
        ],
      },

      {
        path: 'monitoring',
        component: MonitoringLayout,
        children: [
          // { path: '', redirect: 'connections' },
          { path: 'connections', name: 'monitoring.connections', component: LiveConnections },
          { path: 'traffic-graphs', name: 'monitoring.traffic-graphs', component: TrafficGraphs },
          { path: 'session-logs', name: 'monitoring.session-logs', component: SessionLogs },
          { path: 'latency-tests', name: 'monitoring.latency-tests', component: LatencyPingTests },
          { path: 'system-logs', name: 'monitoring.system-logs', component: SystemLogs },
        ],
      },

      {
        path: 'support',
        component: SupportLayout,
        children: [
          // { path: '', redirect: 'create-ticket' },
          { path: 'create-ticket', name: 'support.create-ticket', component: CreateTicket },
          { path: 'all-tickets', name: 'support.all-tickets', component: AllTickets },
          { path: 'categories', name: 'support.categories', component: TicketCategories },
          {
            path: 'response-templates',
            name: 'support.response-templates',
            component: ResponseTemplates,
          },
        ],
      },

      {
        path: 'reports',
        component: ReportsLayout,
        children: [
          // { path: '', redirect: 'daily-logins' },
          { path: 'daily-logins', name: 'reports.daily-logins', component: DailyLoginReports },
          { path: 'payments', name: 'reports.payments', component: PaymentReports },
          {
            path: 'expired-accounts',
            name: 'reports.expired-accounts',
            component: ExpiredAccounts,
          },
          {
            path: 'session-history',
            name: 'reports.session-history',
            component: UserSessionHistory,
          },
          {
            path: 'bandwidth-usage',
            name: 'reports.bandwidth-usage',
            component: BandwidthUsageSummary,
          },
        ],
      },

      {
        path: 'settings',
        component: SettingsLayout,
        children: [
          // { path: '', redirect: 'general' },
          { path: 'general', name: 'settings.general', component: GeneralSettings },
          {
            path: 'mikrotik-api',
            name: 'settings.mikrotik-api',
            component: MikrotikApiCredentials,
          },
          {
            path: 'radius-server',
            name: 'settings.radius-server',
            component: RadiusServerSettings,
          },
          { path: 'communication-channels', name: 'settings.communication-channels', component: CommunicationChannels },
          { path: 'payment-gateways', name: 'settings.payment-gateways', component: () => import('@/modules/tenant/views/dashboard/settings/PaymentGatewaysNew.vue') },
          { path: 'mpesa-api', name: 'settings.mpesa-api', component: MpesaApiKeys },
          { path: 'timezone-locale', name: 'settings.timezone-locale', component: TimezoneLocale },
        ],
      },

      {
        path: 'admin',
        component: AdminToolsLayout,
        children: [
          //  { path: '', redirect: 'roles-permissions' },
          {
            path: 'roles-permissions',
            name: 'admin.roles-permissions',
            component: RolesPermissions,
          },
          { path: 'activity-logs', name: 'admin.activity-logs', component: ActivityLogs },
          { path: 'backup-restore', name: 'admin.backup-restore', component: BackupRestore },
          { path: 'cache-management', name: 'admin.cache-management', component: CacheManagement },
          { path: 'system-updates', name: 'admin.system-updates', component: SystemUpdates },
        ],
      },

      // Todos Module
      {
        path: 'todos',
        name: 'todos',
        component: TodosView,
        meta: { requiresAuth: true }
      },

      // HR Module - Departments
      {
        path: 'hr/departments',
        name: 'hr.departments',
        component: DepartmentsView,
        meta: { requiresAuth: true }
      },

      // HR Module - Positions
      {
        path: 'hr/positions',
        name: 'hr.positions',
        component: PositionsView,
        meta: { requiresAuth: true }
      },

      // HR Module - Employees
      {
        path: 'hr/employees',
        name: 'hr.employees',
        component: EmployeesView,
        meta: { requiresAuth: true }
      },

      // Finance Module - Expenses
      {
        path: 'finance/expenses',
        name: 'finance.expenses',
        component: ExpensesView,
        meta: { requiresAuth: true }
      },

      // Finance Module - Revenues
      {
        path: 'finance/revenues',
        name: 'finance.revenues',
        component: RevenuesView,
        meta: { requiresAuth: true }
      },

      // Branding Module
      {
        path: 'branding',
        name: 'branding',
        component: () => import('@/modules/tenant/views/dashboard/branding/BrandingView.vue'),
        meta: { requiresAuth: true }
      },
    ],
  },

  // PPPoE Customer Portal Routes (Standalone - No tenant/auth required)
  {
    path: '/portal',
    redirect: '/portal/login',
  },
  {
    path: '/portal/login',
    name: 'pppoe-portal.login',
    component: PppoePortalLogin,
    meta: { public: true }
  },
  {
    path: '/portal/dashboard',
    name: 'pppoe-portal.dashboard',
    component: PppoePortalDashboard,
    meta: { requiresPppoeAuth: true }
  },
  {
    path: '/portal/payment',
    name: 'pppoe-portal.payment',
    component: () => import('@/modules/pppoe-portal/views/PppoePortalPayment.vue'),
    meta: { requiresPppoeAuth: true, public: true } // Public access for captive redirect
  },
  {
    path: '/portal/history',
    name: 'pppoe-portal.history',
    component: PppoePortalHistory,
    meta: { requiresPppoeAuth: true }
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Tenant-only route prefixes that system_admin must not access
// System admin should only use /dashboard (overview) and /system/* routes
const TENANT_ONLY_PATHS = [
  '/dashboard/hotspot',
  '/dashboard/pppoe',
  '/dashboard/packages',
  '/dashboard/routers',
  '/dashboard/monitoring',
  '/dashboard/users',
  '/dashboard/billing',
  '/dashboard/finance',
  '/dashboard/reports',
  '/dashboard/settings',
  '/dashboard/admin',
  '/dashboard/support',
  '/dashboard/todos',
  '/dashboard/hr',
]

// Navigation guard - protect routes that require authentication
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('authToken')
  const role = localStorage.getItem('userRole')
  const dashboardRoute = localStorage.getItem('dashboardRoute') || '/dashboard'
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresRole = to.meta.requiresRole
  const requiresPppoeAuth = to.matched.some(record => record.meta.requiresPppoeAuth)

  // Handle PPPoE Portal routes
  if (requiresPppoeAuth) {
    const pppoeToken = localStorage.getItem('pppoe_portal_token')
    if (!pppoeToken) {
      return next({ name: 'pppoe-portal.login', query: { redirect: to.fullPath } })
    }
    return next()
  }

  // Handle PPPoE public routes (login)
  if (to.meta.public && to.path.startsWith('/portal')) {
    const pppoeToken = localStorage.getItem('pppoe_portal_token')
    const pppoeUser = localStorage.getItem('pppoe_portal_user')
    if (pppoeToken && pppoeUser && to.name === 'pppoe-portal.login') {
      return next({ name: 'pppoe-portal.dashboard' })
    }
    return next()
  }

  if (requiresAuth && !token) {
    // Redirect to login if trying to access protected route without token
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (requiresRole && role !== requiresRole) {
    // Redirect to appropriate dashboard if role doesn't match
    next({ path: dashboardRoute })
  } else if ((to.name === 'login' || to.name === 'register') && token) {
    // Redirect to dashboard if already logged in and trying to access login/register page
    next({ path: dashboardRoute })
  } else if (role === 'system_admin' && TENANT_ONLY_PATHS.some(p => to.path.startsWith(p))) {
    // Block system_admin from accessing tenant-only routes
    next({ path: '/dashboard' })
  } else {
    next()
  }
})

// Handle chunk-load failures after deployment (stale hashed filenames).
// A single automatic reload fetches the new index.html with correct chunk URLs.
router.onError((error, to) => {
  if (
    error.message?.includes('Failed to fetch dynamically imported module') ||
    error.message?.includes('Importing a module script failed')
  ) {
    // Prevent infinite reload loop: only reload once per path
    const key = 'chunk_reload:' + (to?.fullPath || window.location.pathname)
    if (!sessionStorage.getItem(key)) {
      sessionStorage.setItem(key, '1')
      window.location.assign(to?.fullPath || window.location.pathname)
    }
  }
})

export default router
