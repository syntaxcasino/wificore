import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

import PackagesView from '@/modules/common/views/public/PackagesView.vue'
import LoginView from '@/modules/common/views/auth/LoginView.vue'
import VerifyEmailView from '@/modules/common/views/auth/VerifyEmailView.vue'
import DashboardLayout from '@/modules/common/components/layout/DashboardLayout.vue'

import Dashboard from '@/modules/tenant/views/DashboardClean.vue'

import UsersLayout from '@/modules/tenant/views/dashboard/users/UsersLayout.vue'
import UserList from '@/modules/tenant/views/dashboard/users/UserList.vue'
import CreateUser from '@/modules/tenant/views/dashboard/users/CreateUser.vue'
import OnlineUsers from '@/modules/tenant/views/dashboard/users/OnlineUsers.vue'
import BlockedUsers from '@/modules/tenant/views/dashboard/users/BlockedUsers.vue'
import UserGroups from '@/modules/tenant/views/dashboard/users/UserGroups.vue'

import HotspotLayout from '@/modules/tenant/views/dashboard/hotspot/HotspotLayout.vue'
import ActiveSessions from '@/modules/tenant/views/dashboard/hotspot/ActiveSessions.vue'
import VouchersGenerate from '@/modules/tenant/views/dashboard/hotspot/VouchersGenerateNew.vue'
import VouchersBulk from '@/modules/tenant/views/dashboard/hotspot/VouchersBulk.vue'
import VoucherTemplates from '@/modules/tenant/views/dashboard/hotspot/VoucherTemplates.vue'
import HotspotProfiles from '@/modules/tenant/views/dashboard/hotspot/HotspotProfiles.vue'
import LoginPageCustomization from '@/modules/tenant/views/dashboard/hotspot/LoginPageCustomization.vue'

import PPPoELayout from '@/modules/tenant/views/dashboard/pppoe/PPPoELayout.vue'
import PPPoESessions from '@/modules/tenant/views/dashboard/pppoe/PPPoESessions.vue'
import AddPPPoEUser from '@/modules/tenant/views/dashboard/pppoe/AddPPPoEUser.vue'
import RadiusProfiles from '@/modules/tenant/views/dashboard/pppoe/RadiusProfiles.vue'
import QueuesBandwidthControl from '@/modules/tenant/views/dashboard/pppoe/QueuesBandwidthControl.vue'
import AutoDisconnectRules from '@/modules/tenant/views/dashboard/pppoe/AutoDisconnectRules.vue'

import BillingLayout from '@/modules/tenant/views/dashboard/billing/BillingLayout.vue'
import Invoices from '@/modules/tenant/views/dashboard/billing/InvoicesNew.vue'
import Payments from '@/modules/tenant/views/dashboard/billing/PaymentsNew.vue'
import MpesaTransactions from '@/modules/tenant/views/dashboard/billing/MpesaTransactionsNew.vue'
import WalletAccountBalance from '@/modules/tenant/views/dashboard/billing/WalletAccountBalanceNew.vue'
import PaymentMethods from '@/modules/tenant/views/dashboard/billing/PaymentMethodsNew.vue'

import PackagesLayout from '@/modules/tenant/views/dashboard/packages/PackagesLayout.vue'
import AllPackages from '@/modules/tenant/views/dashboard/packages/AllPackages.vue'
import AddPackage from '@/modules/tenant/views/dashboard/packages/AddPackageNew.vue'
import PackageGroups from '@/modules/tenant/views/dashboard/packages/PackageGroupsNew.vue'
import BandwidthLimitRules from '@/modules/tenant/views/dashboard/packages/BandwidthLimitRules.vue'

import RoutersLayout from '@/modules/tenant/views/dashboard/routers/RoutersLayout.vue'
import MikrotikList from '@/modules/tenant/views/dashboard/routers/MikrotikList.vue'
import AddRouter from '@/modules/tenant/views/dashboard/routers/AddRouter.vue'
import ApiConnectionStatus from '@/modules/tenant/views/dashboard/routers/ApiConnectionStatus.vue'
import BackupConfigurations from '@/modules/tenant/views/dashboard/routers/BackupConfigurations.vue'

import MonitoringLayout from '@/modules/tenant/views/dashboard/monitoring/MonitoringLayout.vue'
import LiveConnections from '@/modules/tenant/views/dashboard/monitoring/LiveConnectionsNew.vue'
import SystemLogs from '@/modules/tenant/views/dashboard/monitoring/SystemLogsNew.vue'
import TrafficGraphs from '@/modules/tenant/views/dashboard/monitoring/TrafficGraphsNew.vue'
import SessionLogs from '@/modules/tenant/views/dashboard/monitoring/SessionLogsNew.vue'
import LatencyPingTests from '@/modules/tenant/views/dashboard/monitoring/LatencyPingTests.vue'
import ReportsLayout from '@/modules/tenant/views/dashboard/reports/ReportsLayout.vue'
import DailyLoginReports from '@/modules/tenant/views/dashboard/reports/DailyLoginReportsNew.vue'
import PaymentReports from '@/modules/tenant/views/dashboard/reports/PaymentReportsNew.vue'

import SupportLayout from '@/modules/tenant/views/dashboard/support/SupportLayout.vue'
import AllTickets from '@/modules/tenant/views/dashboard/support/AllTicketsNew.vue'
import CreateTicket from '@/modules/tenant/views/dashboard/support/CreateTicketNew.vue'
import TicketCategories from '@/modules/tenant/views/dashboard/support/TicketCategories.vue'
import ResponseTemplates from '@/modules/tenant/views/dashboard/support/ResponseTemplates.vue'

import UserSessionHistory from '@/modules/tenant/views/dashboard/reports/UserSessionHistoryNew.vue'
import BandwidthUsageSummary from '@/modules/tenant/views/dashboard/reports/BandwidthUsageSummaryNew.vue'
import ExpiredAccounts from '@/modules/tenant/views/dashboard/reports/ExpiredAccounts.vue'

import SettingsLayout from '@/modules/tenant/views/dashboard/settings/SettingsLayout.vue'
import GeneralSettings from '@/modules/tenant/views/dashboard/settings/GeneralSettingsNew.vue'
import MikrotikApiCredentials from '@/modules/tenant/views/dashboard/settings/MikrotikApiCredentialsNew.vue'
import RadiusServerSettings from '@/modules/tenant/views/dashboard/settings/RadiusServerSettingsNew.vue'
import EmailSmsSettings from '@/modules/tenant/views/dashboard/settings/EmailSmsSettingsNew.vue'
import MpesaApiKeys from '@/modules/tenant/views/dashboard/settings/MpesaApiKeysNew.vue'
import TimezoneLocale from '@/modules/tenant/views/dashboard/settings/TimezoneLocaleNew.vue'

import AdminToolsLayout from '@/modules/tenant/views/dashboard/admin/AdminToolsLayout.vue'
import RolesPermissions from '@/modules/tenant/views/dashboard/admin/RolesPermissionsNew.vue'
import ActivityLogs from '@/modules/tenant/views/dashboard/admin/ActivityLogsNew.vue'
import BackupRestore from '@/modules/tenant/views/dashboard/admin/BackupRestoreNew.vue'
import CacheManagement from '@/modules/tenant/views/dashboard/admin/CacheManagement.vue'
import WebSocketTest from '@/modules/common/views/test/WebSocketTestView.vue'
import ComponentShowcase from '@/modules/common/views/test/ComponentShowcase.vue'
import SystemUpdates from '@/modules/tenant/views/dashboard/admin/SystemUpdates.vue'

// Todos Module
import TodosView from '@/modules/tenant/views/TodosView.vue'

// HR Module
import DepartmentsView from '@/modules/tenant/views/DepartmentsView.vue'
import PositionsView from '@/modules/tenant/views/PositionsView.vue'
import EmployeesView from '@/modules/tenant/views/EmployeesView.vue'

// Finance Module
import ExpensesView from '@/modules/tenant/views/ExpensesView.vue'
import RevenuesView from '@/modules/tenant/views/RevenuesView.vue'

const routes = [
  { path: '/', name: 'public', component: PackagesView },
  { path: '/login', name: 'login', component: LoginView },
  { path: '/register', name: 'register', component: () => import('@/modules/common/views/auth/TenantRegistrationView.vue'), alias: '/register/tenant' },
  { path: '/email/verify/:id/:hash', name: 'verify-email', component: VerifyEmailView },
  { path: '/register/verify/:token', name: 'verify-registration', component: VerifyEmailView },
  
  // Hotspot routes - redirect to packages view (hotspot login page)
  { path: '/hotspot', redirect: '/' },
  { path: '/hotspot/login', redirect: '/' },
  
  { path: '/websocket-test', name: 'websocket-test', component: WebSocketTest, meta: { requiresAuth: true } },
  { path: '/component-showcase', name: 'component-showcase', component: ComponentShowcase, meta: { requiresAuth: true } },

  // System Admin Dashboard (with layout)
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
    ]
  },

  {
    path: '/dashboard',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'overview', component: Dashboard },

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
          { path: 'users', name: 'hotspot.users', component: () => import('@/modules/tenant/views/dashboard/hotspot/HotspotUsers.vue') },
          { path: 'sessions', name: 'hotspot.sessions', component: () => import('@/modules/tenant/views/dashboard/hotspot/ActiveSessionsNew.vue') },
          {
            path: 'vouchers/generate',
            name: 'hotspot.vouchers.generate',
            component: VouchersGenerate,
          },
          { path: 'vouchers/bulk', name: 'hotspot.vouchers.bulk', component: VouchersBulk },
          {
            path: 'voucher-templates',
            name: 'hotspot.voucher-templates',
            component: VoucherTemplates,
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
          { path: 'sessions', name: 'pppoe.sessions', component: () => import('@/modules/tenant/views/dashboard/pppoe/PPPoESessionsNew.vue') },
          { path: 'add-user', name: 'pppoe.add-user', component: AddPPPoEUser },
          { path: 'radius-profiles', name: 'pppoe.radius-profiles', component: RadiusProfiles },
          { path: 'queues', name: 'pppoe.queues', component: QueuesBandwidthControl },
          {
            path: 'auto-disconnect',
            name: 'pppoe.auto-disconnect',
            component: AutoDisconnectRules,
          },
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

      {
        path: 'packages',
        component: PackagesLayout,
        children: [
          // { path: '', redirect: 'all' },
          { path: 'all', name: 'packages.all', component: AllPackages },
          //{ path: 'add', name: 'packages.add', component: AddPackage },
          { path: 'groups', name: 'packages.groups', component: PackageGroups },
/*           {
            path: 'bandwidth-limits',
            name: 'packages.bandwidth-limits',
            component: BandwidthLimitRules,
          }, */
        ],
      },

      {
        path: 'routers',
        component: RoutersLayout,
        children: [
          //{ path: '', redirect: 'mikrotik' },
          { path: 'mikrotik', name: 'routers.mikrotik', component: MikrotikList },
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
          { path: 'email-sms', name: 'settings.email-sms', component: EmailSmsSettings },
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
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

// Navigation guard - protect routes that require authentication
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('authToken')
  const role = localStorage.getItem('userRole')
  const dashboardRoute = localStorage.getItem('dashboardRoute') || '/dashboard'
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)
  const requiresRole = to.meta.requiresRole

  if (requiresAuth && !token) {
    // Redirect to login if trying to access protected route without token
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (requiresRole && role !== requiresRole) {
    // Redirect to appropriate dashboard if role doesn't match
    next({ path: dashboardRoute })
  } else if ((to.name === 'login' || to.name === 'register') && token) {
    // Redirect to dashboard if already logged in and trying to access login/register page
    next({ path: dashboardRoute })
  } else {
    next()
  }
})

export default router
