import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

import PublicView from '@/views/public/HomeView.vue'
import LoginView from '@/views/auth/LoginView.vue'
import DashboardLayout from '@/components/layout/AppLayout.vue'

import Overview from '@/views/dashboard/Overview.vue'
import DailyWeeklyStatistics from '@/views/dashboard/DailyWeeklyStatistics.vue'
import SystemHealth from '@/views/dashboard/SystemHealth.vue'

import UsersLayout from '@/views/dashboard/users/UsersLayout.vue'
import UserList from '@/views/dashboard/users/UserList.vue'
import CreateUser from '@/views/dashboard/users/CreateUser.vue'
import OnlineUsers from '@/views/dashboard/users/OnlineUsers.vue'
import BlockedUsers from '@/views/dashboard/users/BlockedUsers.vue'
import UserGroups from '@/views/dashboard/users/UserGroups.vue'

import HotspotLayout from '@/views/dashboard/hotspot/HotspotLayout.vue'
import ActiveSessions from '@/views/dashboard/hotspot/ActiveSessions.vue'
import VouchersGenerate from '@/views/dashboard/hotspot/VouchersGenerate.vue'
import VouchersBulk from '@/views/dashboard/hotspot/VouchersBulk.vue'
import VoucherTemplates from '@/views/dashboard/hotspot/VoucherTemplates.vue'
import HotspotProfiles from '@/views/dashboard/hotspot/HotspotProfiles.vue'
import LoginPageCustomization from '@/views/dashboard/hotspot/LoginPageCustomization.vue'

import PPPoELayout from '@/views/dashboard/pppoe/PPPoELayout.vue'
import PPPoESessions from '@/views/dashboard/pppoe/PPPoESessions.vue'
import AddPPPoEUser from '@/views/dashboard/pppoe/AddPPPoEUser.vue'
import RadiusProfiles from '@/views/dashboard/pppoe/RadiusProfiles.vue'
import QueuesBandwidthControl from '@/views/dashboard/pppoe/QueuesBandwidthControl.vue'
import AutoDisconnectRules from '@/views/dashboard/pppoe/AutoDisconnectRules.vue'

import BillingLayout from '@/views/dashboard/billing/BillingLayout.vue'
import Invoices from '@/views/dashboard/billing/Invoices.vue'
import Payments from '@/views/dashboard/billing/Payments.vue'
import MpesaTransactions from '@/views/dashboard/billing/MpesaTransactions.vue'
import WalletAccountBalance from '@/views/dashboard/billing/WalletAccountBalance.vue'
import PaymentMethods from '@/views/dashboard/billing/PaymentMethods.vue'

import PackagesLayout from '@/views/dashboard/packages/PackagesLayout.vue'
import AllPackages from '@/views/dashboard/packages/AllPackages.vue'
import AddPackage from '@/views/dashboard/packages/AddPackage.vue'
import PackageGroups from '@/views/dashboard/packages/PackageGroups.vue'
import BandwidthLimitRules from '@/views/dashboard/packages/BandwidthLimitRules.vue'

import RoutersLayout from '@/views/dashboard/routers/RoutersLayout.vue'
import MikrotikList from '@/views/dashboard/routers/MikrotikList.vue'
import AddRouter from '@/views/dashboard/routers/AddRouter.vue'
import ApiConnectionStatus from '@/views/dashboard/routers/ApiConnectionStatus.vue'
import BackupConfigurations from '@/views/dashboard/routers/BackupConfigurations.vue'

import MonitoringLayout from '@/views/dashboard/monitoring/MonitoringLayout.vue'
import LiveConnections from '@/views/dashboard/monitoring/LiveConnections.vue'
import TrafficGraphs from '@/views/dashboard/monitoring/TrafficGraphs.vue'
import SessionLogs from '@/views/dashboard/monitoring/SessionLogs.vue'
import LatencyPingTests from '@/views/dashboard/monitoring/LatencyPingTests.vue'
import SystemLogs from '@/views/dashboard/monitoring/SystemLogs.vue'

import SupportLayout from '@/views/dashboard/support/SupportLayout.vue'
import CreateTicket from '@/views/dashboard/support/CreateTicket.vue'
import AllTickets from '@/views/dashboard/support/AllTickets.vue'
import TicketCategories from '@/views/dashboard/support/TicketCategories.vue'
import ResponseTemplates from '@/views/dashboard/support/ResponseTemplates.vue'

import ReportsLayout from '@/views/dashboard/reports/ReportsLayout.vue'
import DailyLoginReports from '@/views/dashboard/reports/DailyLoginReports.vue'
import PaymentReports from '@/views/dashboard/reports/PaymentReports.vue'
import ExpiredAccounts from '@/views/dashboard/reports/ExpiredAccounts.vue'
import UserSessionHistory from '@/views/dashboard/reports/UserSessionHistory.vue'
import BandwidthUsageSummary from '@/views/dashboard/reports/BandwidthUsageSummary.vue'

import SettingsLayout from '@/views/dashboard/settings/SettingsLayout.vue'
import GeneralSettings from '@/views/dashboard/settings/GeneralSettings.vue'
import MikrotikApiCredentials from '@/views/dashboard/settings/MikrotikApiCredentials.vue'
import RadiusServerSettings from '@/views/dashboard/settings/RadiusServerSettings.vue'
import EmailSmsSettings from '@/views/dashboard/settings/EmailSmsSettings.vue'
import MpesaApiKeys from '@/views/dashboard/settings/MpesaApiKeys.vue'
import TimezoneLocale from '@/views/dashboard/settings/TimezoneLocale.vue'

import AdminToolsLayout from '@/views/dashboard/admin/AdminToolsLayout.vue'
import RolesPermissions from '@/views/dashboard/admin/RolesPermissions.vue'
import ActivityLogs from '@/views/dashboard/admin/ActivityLogs.vue'
import BackupRestore from '@/views/dashboard/admin/BackupRestore.vue'
import CacheManagement from '@/views/dashboard/admin/CacheManagement.vue'
import SystemUpdates from '@/views/dashboard/admin/SystemUpdates.vue'

const routes = [
  { path: '/', name: 'public', component: PublicView },
  { path: '/login', name: 'login', component: LoginView },

  {
    path: '/dashboard',
    component: DashboardLayout,
    meta: { requiresAuth: true },
    children: [
      { path: '', name: 'overview', component: Overview },
      { path: 'statistics', name: 'dashboard.statistics', component: DailyWeeklyStatistics },
      { path: 'health', name: 'dashboard.health', component: SystemHealth },

      {
        path: '/dashboard/users',
        component: UsersLayout,
        children: [
          // { path: '', redirect: 'all' },
          { path: 'all', name: 'users.all', component: UserList },
          { path: 'create', name: 'users.create', component: CreateUser },
          { path: 'online', name: 'users.online', component: OnlineUsers },
          { path: 'blocked', name: 'users.blocked', component: BlockedUsers },
          { path: 'groups', name: 'users.groups', component: UserGroups },
        ],
      },

      {
        path: 'hotspot',
        component: HotspotLayout,
        children: [
          //  { path: '', redirect: 'sessions' },
          { path: 'sessions', name: 'hotspot.sessions', component: ActiveSessions },
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
          // { path: '', redirect: 'sessions' },
          { path: 'sessions', name: 'pppoe.sessions', component: PPPoESessions },
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
          { path: 'add', name: 'packages.add', component: AddPackage },
          { path: 'groups', name: 'packages.groups', component: PackageGroups },
          {
            path: 'bandwidth-limits',
            name: 'packages.bandwidth-limits',
            component: BandwidthLimitRules,
          },
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
    ],
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

/* router.beforeEach((to) => {
  const auth = useAuthStore();
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' };
  }
}); */

export default router
