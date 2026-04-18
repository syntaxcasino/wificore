<template>
  <div class="min-h-screen bg-gray-50 dark:bg-slate-900 transition-colors duration-200">
    <!-- Header -->
    <div class="px-4 md:px-6 py-3 md:py-5 border-b border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="flex items-center gap-3">
          <div class="w-9 h-9 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
          </div>
          <div>
            <h1 class="text-lg sm:text-xl font-bold text-slate-900 dark:text-slate-100 leading-tight">Dashboard</h1>
            <p class="text-xs text-slate-500 dark:text-slate-400">Network performance &amp; billing overview</p>
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button
            @click="refreshStats"
            :disabled="loading"
            class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700 transition-colors shadow-sm disabled:opacity-50"
          >
            <svg class="w-3.5 h-3.5" :class="loading ? 'animate-spin text-blue-500' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Refresh
          </button>
          <div class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold shadow"
            :class="isConnected ? 'bg-emerald-600 text-white' : 'bg-red-500 text-white'">
            <span class="relative flex h-2 w-2">
              <span v-if="isConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-60"></span>
              <span class="relative inline-flex rounded-full h-2 w-2 bg-white"></span>
            </span>
            {{ isConnected ? 'Live' : 'Offline' }}
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-24">
      <div class="text-center">
        <div class="w-10 h-10 border-2 border-blue-100 dark:border-blue-900 border-t-blue-600 rounded-full animate-spin mx-auto mb-3"></div>
        <p class="text-sm text-slate-500 dark:text-slate-400">Loading dashboard...</p>
      </div>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-5 p-4 md:p-6">

      <!-- ── ROW 1: 4 KPI CARDS ── -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center">
              <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <span v-if="revenueGrowth" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full"
              :class="revenueGrowth.isPositive ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400'">
              {{ revenueGrowth.isPositive ? '+' : '' }}{{ revenueGrowth.value }}%
            </span>
          </div>
          <div class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(stats.totalRevenue) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Total Revenue</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">{{ formatCurrency(stats.monthlyRevenue) }} this month</div>
        </div>

        <!-- Active Sessions -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center">
              <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
          </div>
          <div class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.activeSessions || 0 }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Active Sessions</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">{{ stats.totalUsers || 0 }} total users</div>
        </div>

        <!-- Network Health -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center">
              <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full" :class="routerHealthStatus?.bgColor + ' ' + routerHealthStatus?.color">
              {{ routerHealthStatus?.label }}
            </span>
          </div>
          <div class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-slate-100">{{ stats.onlineRouters || 0 }}<span class="text-sm font-normal text-slate-400">/{{ stats.totalRouters || 0 }}</span></div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Network Health</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">{{ stats.offlineRouters || 0 }} offline</div>
        </div>

        <!-- Data Usage -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all">
          <div class="flex items-center justify-between mb-3">
            <div class="w-8 h-8 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center">
              <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
              </svg>
            </div>
          </div>
          <div class="text-xl sm:text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatDataSize(stats.dataUsage) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5">Data Usage</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1">Total transferred</div>
        </div>

      </div>

      <!-- ── ROW 2: SECONDARY METRICS ── -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Daily Income</div>
          <div class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(stats.dailyIncome) }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Weekly</div>
          <div class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatCurrency(stats.weeklyIncome) }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">Hotspot Users</div>
          <div class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ stats.hotspotUsers || 0 }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-4 py-3">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1">PPPoE Users</div>
          <div class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ stats.pppoeUsers || 0 }}</div>
        </div>
      </div>

      <!-- ── ROW 3: WIDGETS ── -->
      <PaymentWidget :paymentData="paymentData" />

      <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
        <ExpensesWidget :expensesData="expensesData" />
        <BusinessAnalyticsWidget :analyticsData="analyticsData" />
      </div>

      <!-- ── ROW 4: QUICK ACTIONS ── -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-5">
        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-4">Quick Actions</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
          <router-link
            v-for="action in quickActions"
            :key="action.to"
            :to="action.to"
            class="flex flex-col items-center gap-2.5 p-4 rounded-xl border-2 border-slate-200 dark:border-slate-700 hover:border-current hover:bg-current/5 dark:hover:bg-current/10 transition-all group"
            :class="action.borderClass"
          >
            <div class="w-11 h-11 rounded-lg flex items-center justify-center transition-colors" :class="action.iconBg">
              <component :is="action.icon" class="w-5 h-5" :class="action.iconColor" />
            </div>
            <span class="text-xs sm:text-sm font-semibold text-slate-800 dark:text-slate-200 text-center">{{ action.label }}</span>
          </router-link>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuth } from '@/modules/common/composables/auth/useAuth'
import { useDashboard } from '@/modules/tenant/composables/data/useDashboard'
import PaymentWidget from '@/modules/tenant/components/dashboard/PaymentWidgetClean.vue'
import ExpensesWidget from '@/modules/tenant/components/dashboard/ExpensesWidgetClean.vue'
import BusinessAnalyticsWidget from '@/modules/tenant/components/dashboard/BusinessAnalyticsWidgetClean.vue'
import { Users, Wifi, Package, BarChart3, Radio, CreditCard, Settings, Activity } from 'lucide-vue-next'

const { user } = useAuth()
const { isConnected, subscribeToPrivateChannel } = useBroadcasting()

const {
  stats,
  paymentData,
  expensesData,
  analyticsData,
  loading,
  fetchDashboardStats,
  refreshStats,
  updateStatsFromEvent,
  formatCurrency,
  formatDataSize,
  routerHealthStatus,
  revenueGrowth,
} = useDashboard()

const quickActions = computed(() => [
  { to: '/dashboard/hotspot/users', label: 'Hotspot Users', icon: Radio, iconBg: 'bg-blue-100 dark:bg-blue-900/40', iconColor: 'text-blue-600 dark:text-blue-400', borderClass: 'hover:border-blue-500' },
  { to: '/dashboard/routers/mikrotik', label: 'Routers', icon: Wifi, iconBg: 'bg-green-100 dark:bg-green-900/40', iconColor: 'text-green-600 dark:text-green-400', borderClass: 'hover:border-green-500' },
  { to: '/dashboard/packages/all', label: 'Packages', icon: Package, iconBg: 'bg-purple-100 dark:bg-purple-900/40', iconColor: 'text-purple-600 dark:text-purple-400', borderClass: 'hover:border-purple-500' },
  { to: '/dashboard/billing/payments', label: 'Payments', icon: CreditCard, iconBg: 'bg-emerald-100 dark:bg-emerald-900/40', iconColor: 'text-emerald-600 dark:text-emerald-400', borderClass: 'hover:border-emerald-500' },
  { to: '/dashboard/users/all', label: 'All Users', icon: Users, iconBg: 'bg-indigo-100 dark:bg-indigo-900/40', iconColor: 'text-indigo-600 dark:text-indigo-400', borderClass: 'hover:border-indigo-500' },
  { to: '/dashboard/reports/revenue', label: 'Reports', icon: BarChart3, iconBg: 'bg-amber-100 dark:bg-amber-900/40', iconColor: 'text-amber-600 dark:text-amber-400', borderClass: 'hover:border-amber-500' },
  { to: '/dashboard/monitoring/system-logs', label: 'Monitoring', icon: Activity, iconBg: 'bg-rose-100 dark:bg-rose-900/40', iconColor: 'text-rose-600 dark:text-rose-400', borderClass: 'hover:border-rose-500' },
  { to: '/dashboard/settings/general', label: 'Settings', icon: Settings, iconBg: 'bg-slate-100 dark:bg-slate-700', iconColor: 'text-slate-600 dark:text-slate-300', borderClass: 'hover:border-slate-400' },
])

onMounted(() => {
  fetchDashboardStats()

  const tenantId = user.value?.tenant_id
  if (!tenantId) {
    console.warn('No tenant_id available - cannot subscribe to tenant channels')
    return
  }

  subscribeToPrivateChannel(`tenant.${tenantId}.dashboard-stats`, {
    'DashboardStatsUpdated': (event) => { if (event.stats) updateStatsFromEvent(event.stats) },
    '.DashboardStatsUpdated': (event) => { if (event.stats) updateStatsFromEvent(event.stats) },
    'PackageCreated': () => fetchDashboardStats(),
    'PackageDeleted': () => fetchDashboardStats(),
    'PppoeUserCreated': () => fetchDashboardStats(),
    'PppoeUserDeleted': () => fetchDashboardStats(),
    'PppoeSessionStarted': () => fetchDashboardStats(),
    'PppoeSessionEnded': () => fetchDashboardStats(),
    'HotspotUserCreated': () => fetchDashboardStats(),
    'RouterCreated': () => fetchDashboardStats(),
    'PaymentCompleted': () => fetchDashboardStats(),
    'UserCreated': () => fetchDashboardStats(),
    '.PackageCreated': () => fetchDashboardStats(),
    '.PppoeUserCreated': () => fetchDashboardStats(),
    '.PppoeSessionStarted': () => fetchDashboardStats(),
    '.PppoeSessionEnded': () => fetchDashboardStats(),
    '.HotspotUserCreated': () => fetchDashboardStats(),
    '.RouterCreated': () => fetchDashboardStats(),
    '.PaymentCompleted': () => fetchDashboardStats(),
    '.UserCreated': () => fetchDashboardStats(),
  })

  subscribeToPrivateChannel(`tenant.${tenantId}.routers`, {
    'RouterStatusUpdated': (event) => { if (event.stats) updateStatsFromEvent(event.stats) },
    '.RouterStatusUpdated': (event) => { if (event.stats) updateStatsFromEvent(event.stats) },
    '.RouterCreated': () => fetchDashboardStats(),
  })
})
</script>
