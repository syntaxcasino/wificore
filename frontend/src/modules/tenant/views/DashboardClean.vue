<template>
  <div class="min-h-screen bg-slate-50 dark:bg-slate-900 transition-colors duration-200">

    <!-- ── HEADER ── -->
    <div class="sticky top-0 z-10 px-4 md:px-6 py-3 border-b border-slate-200 dark:border-slate-700 bg-white/95 dark:bg-slate-800/95 backdrop-blur-sm">
      <div class="flex items-center justify-between gap-2">
        <div class="flex items-center gap-2.5 min-w-0">
          <div class="w-8 h-8 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-lg flex items-center justify-center shadow flex-shrink-0">
            <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
            </svg>
          </div>
          <div class="min-w-0">
            <h1 class="text-base sm:text-lg font-bold text-slate-900 dark:text-slate-100 leading-tight truncate">Dashboard</h1>
            <p class="text-[11px] text-slate-500 dark:text-slate-400 hidden sm:block">Network &amp; billing overview</p>
          </div>
        </div>
        <div class="flex items-center gap-1.5 flex-shrink-0">
          <button
            @click="refreshStats"
            :disabled="loading"
            class="flex items-center gap-1 px-2.5 py-1.5 text-xs font-medium text-slate-600 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-200 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600 transition-colors shadow-sm disabled:opacity-50 active:scale-95"
          >
            <svg class="w-3.5 h-3.5" :class="loading ? 'animate-spin text-blue-500' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span class="hidden sm:inline">Refresh</span>
          </button>
          <div class="flex items-center gap-1 px-2 py-1.5 rounded-lg text-xs font-semibold"
            :class="isConnected ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400' : 'bg-red-500/15 text-red-600 dark:text-red-400'">
            <span class="relative flex h-1.5 w-1.5">
              <span v-if="isConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-500 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-1.5 w-1.5" :class="isConnected ? 'bg-emerald-500' : 'bg-red-500'"></span>
            </span>
            <span class="hidden sm:inline">{{ isConnected ? 'Live' : 'Offline' }}</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="loading && !hasCachedSnapshot" class="px-4 md:px-6 pt-3">
      <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-slate-200 dark:border-slate-700 bg-white/90 dark:bg-slate-800/90 text-xs text-slate-500 dark:text-slate-400 shadow-sm">
        <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
        Loading live metrics
      </div>
    </div>

    <!-- ── DASHBOARD CONTENT ── -->
    <div class="p-4 md:p-6 space-y-4">

      <!-- ROW 1: 4 KPI CARDS -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">

        <!-- Total Revenue -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all min-w-0">
          <div class="flex items-center justify-between mb-2 sm:mb-3">
            <div class="w-7 h-7 sm:w-8 sm:h-8 bg-green-100 dark:bg-green-900/40 rounded-lg flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <span v-if="revenueGrowth" class="text-[10px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0 ml-1"
              :class="revenueGrowth.isPositive ? 'bg-green-100 dark:bg-green-900/40 text-green-700 dark:text-green-400' : 'bg-red-100 dark:bg-red-900/40 text-red-700 dark:text-red-400'">
              {{ revenueGrowth.isPositive ? '+' : '' }}{{ revenueGrowth.value }}%
            </span>
          </div>
          <div class="text-base sm:text-xl lg:text-2xl font-bold text-slate-900 dark:text-slate-100 truncate">{{ formatCurrency(stats.totalRevenue) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">Total Revenue</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 truncate">{{ formatCurrency(stats.monthlyRevenue) }} this month</div>
        </div>

        <!-- Active Sessions -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all min-w-0">
          <div class="flex items-center justify-between mb-2 sm:mb-3">
            <div class="w-7 h-7 sm:w-8 sm:h-8 bg-blue-100 dark:bg-blue-900/40 rounded-lg flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse flex-shrink-0"></span>
          </div>
          <div class="text-base sm:text-xl lg:text-2xl font-bold text-slate-900 dark:text-slate-100 truncate">{{ stats.activeSessions || 0 }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">Active Sessions</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 truncate">{{ stats.totalUsers || 0 }} total users</div>
        </div>

        <!-- Network Health -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all min-w-0">
          <div class="flex items-center justify-between mb-2 sm:mb-3">
            <div class="w-7 h-7 sm:w-8 sm:h-8 bg-indigo-100 dark:bg-indigo-900/40 rounded-lg flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-[10px] font-bold px-1.5 py-0.5 rounded-full flex-shrink-0 ml-1" :class="routerHealthStatus?.bgColor + ' ' + routerHealthStatus?.color">
              {{ routerHealthStatus?.label }}
            </span>
          </div>
          <div class="text-base sm:text-xl lg:text-2xl font-bold text-slate-900 dark:text-slate-100 truncate">{{ stats.onlineRouters || 0 }}<span class="text-xs sm:text-sm font-normal text-slate-400">/{{ stats.totalRouters || 0 }}</span></div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">Network Health</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 truncate">{{ stats.offlineRouters || 0 }} offline</div>
        </div>

        <!-- Data Usage -->
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-4 hover:shadow-md dark:hover:shadow-slate-900/50 hover:-translate-y-0.5 transition-all min-w-0">
          <div class="flex items-center justify-between mb-2 sm:mb-3">
            <div class="w-7 h-7 sm:w-8 sm:h-8 bg-purple-100 dark:bg-purple-900/40 rounded-lg flex items-center justify-center flex-shrink-0">
              <svg class="w-3.5 h-3.5 sm:w-4 sm:h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
              </svg>
            </div>
          </div>
          <div class="text-base sm:text-xl lg:text-2xl font-bold text-slate-900 dark:text-slate-100 truncate">{{ formatDataSize(stats.dataUsage) }}</div>
          <div class="text-xs text-slate-500 dark:text-slate-400 mt-0.5 truncate">Data Usage</div>
          <div class="text-[10px] text-slate-400 dark:text-slate-500 mt-1 truncate">Total transferred</div>
        </div>
      </div>

      <!-- ROW 2: SECONDARY METRICS -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-2.5 sm:px-4 sm:py-3 min-w-0">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 truncate">Daily</div>
          <div class="text-sm sm:text-base font-bold text-slate-900 dark:text-slate-100 truncate">{{ formatCurrency(stats.dailyIncome) }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-2.5 sm:px-4 sm:py-3 min-w-0">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 truncate">Weekly</div>
          <div class="text-sm sm:text-base font-bold text-slate-900 dark:text-slate-100 truncate">{{ formatCurrency(stats.weeklyIncome) }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-2.5 sm:px-4 sm:py-3 min-w-0">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 truncate">Hotspot</div>
          <div class="text-sm sm:text-base font-bold text-slate-900 dark:text-slate-100 truncate">{{ stats.hotspotUsers || 0 }}</div>
        </div>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 px-3 py-2.5 sm:px-4 sm:py-3 min-w-0">
          <div class="text-[10px] font-semibold text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-1 truncate">PPPoE</div>
          <div class="text-sm sm:text-base font-bold text-slate-900 dark:text-slate-100 truncate">{{ stats.pppoeUsers || 0 }}</div>
        </div>
      </div>

      <!-- ROW 3: WIDGETS — stack on mobile, side-by-side on lg -->
      <template v-if="widgetsReady">
        <HealthScoreWidget :health-score="healthScore" :format-time-ago="formatTimeAgo" />
        <PaymentWidget :paymentData="paymentData" />

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <ExpensesWidget :expensesData="expensesData" />
          <BusinessAnalyticsWidget :analyticsData="analyticsData" />
        </div>

        <RevenueAssuranceWidget :revenue-assurance="revenueAssurance" :format-currency="formatCurrency" />
      </template>

      <template v-else>
        <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 animate-pulse">
          <div class="h-5 bg-slate-200 dark:bg-slate-700 rounded w-48 mb-2"></div>
          <div class="h-3 bg-slate-100 dark:bg-slate-700/50 rounded w-64 mb-5"></div>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="h-32 bg-slate-100 dark:bg-slate-700/50 rounded-xl"></div>
            <div class="h-32 bg-slate-100 dark:bg-slate-700/50 rounded-xl"></div>
            <div class="h-32 bg-slate-100 dark:bg-slate-700/50 rounded-xl"></div>
          </div>
        </div>

        <div class="grid grid-cols-1 xl:grid-cols-2 gap-4">
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 animate-pulse h-80"></div>
          <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4 sm:p-6 animate-pulse h-80"></div>
        </div>
      </template>

      <!-- ROW 4: QUICK ACTIONS -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-3 sm:p-4 md:p-5">
        <h3 class="text-sm font-semibold text-slate-800 dark:text-slate-200 mb-3">Quick Actions</h3>
        <div class="grid grid-cols-4 sm:grid-cols-4 md:grid-cols-8 gap-2">
          <router-link
            v-for="action in quickActions"
            :key="action.to"
            :to="action.to"
            class="flex flex-col items-center gap-1.5 p-2.5 sm:p-3 rounded-xl border border-slate-200 dark:border-slate-700 hover:border-current hover:bg-current/5 dark:hover:bg-current/10 active:scale-95 transition-all group min-w-0"
            :class="action.borderClass"
          >
            <div class="w-8 h-8 sm:w-10 sm:h-10 rounded-lg flex items-center justify-center transition-colors flex-shrink-0" :class="action.iconBg">
              <component :is="action.icon" class="w-4 h-4 sm:w-5 sm:h-5" :class="action.iconColor" />
            </div>
            <span class="text-[10px] sm:text-[11px] font-semibold text-slate-700 dark:text-slate-300 text-center leading-tight w-full line-clamp-2">{{ action.label }}</span>
          </router-link>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { computed, defineAsyncComponent, onMounted, onUnmounted, ref } from 'vue'
import { useDashboard } from '@/modules/tenant/composables/data/useDashboard'
import { Users, Wifi, Package, BarChart3, Radio, CreditCard, Settings, Activity } from 'lucide-vue-next'

const PaymentWidget = defineAsyncComponent(() => import('@/modules/tenant/components/dashboard/PaymentWidgetClean.vue'))
const ExpensesWidget = defineAsyncComponent(() => import('@/modules/tenant/components/dashboard/ExpensesWidgetClean.vue'))
const BusinessAnalyticsWidget = defineAsyncComponent(() => import('@/modules/tenant/components/dashboard/BusinessAnalyticsWidgetClean.vue'))
const RevenueAssuranceWidget = defineAsyncComponent(() => import('@/modules/tenant/components/dashboard/RevenueAssuranceWidgetClean.vue'))
const HealthScoreWidget = defineAsyncComponent(() => import('@/modules/tenant/components/dashboard/HealthScoreWidgetClean.vue'))

const {
  stats,
  paymentData,
  expensesData,
  analyticsData,
  healthScore,
  loading,
  hasCachedSnapshot,
  fetchDashboardStats,
  fetchHealthScore,
  refreshStats,
  updateStatsFromEvent,
  formatCurrency,
  formatDataSize,
  formatTimeAgo,
  routerHealthStatus,
  revenueGrowth,
  revenueAssurance,
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

const isConnected = ref(false)
const widgetsReady = ref(false)
let connection = null
let handleWsConnected = null
let handleWsDisconnected = null

const scheduleNonCriticalWork = (callback) => {
  if (typeof window === 'undefined') {
    callback()
    return
  }

  if (window.requestIdleCallback) {
    window.requestIdleCallback(callback, { timeout: 1200 })
    return
  }

  window.setTimeout(callback, 0)
}

onMounted(() => {
  scheduleNonCriticalWork(() => {
    widgetsReady.value = true
  })

  requestAnimationFrame(() => {
    fetchDashboardStats()
    fetchHealthScore()
  })

  connection = window.Echo?.connector?.pusher?.connection || null
  if (connection) {
    isConnected.value = connection.state === 'connected'
    handleWsConnected = () => { isConnected.value = true }
    handleWsDisconnected = () => { isConnected.value = false }
    connection.bind('connected', handleWsConnected)
    connection.bind('disconnected', handleWsDisconnected)
  }

  window.addEventListener('dashboard-stats-updated', handleDashboardStatsUpdated)
  window.addEventListener('router-status-updated', debouncedRefetch)
  window.addEventListener('package-created', debouncedRefetch)
  window.addEventListener('package-deleted', debouncedRefetch)
  window.addEventListener('user-created', debouncedRefetch)
})

let _debounceTimer = null
const debouncedRefetch = () => {
  clearTimeout(_debounceTimer)
  _debounceTimer = setTimeout(fetchDashboardStats, 2000)
}

const handleDashboardStatsUpdated = (event) => {
  const data = event?.detail
  if (data?.stats) {
    updateStatsFromEvent(data.stats)
    return
  }
  debouncedRefetch()
}

onUnmounted(() => {
  clearTimeout(_debounceTimer)
  window.removeEventListener('dashboard-stats-updated', handleDashboardStatsUpdated)
  window.removeEventListener('router-status-updated', debouncedRefetch)
  window.removeEventListener('package-created', debouncedRefetch)
  window.removeEventListener('package-deleted', debouncedRefetch)
  window.removeEventListener('user-created', debouncedRefetch)

  if (connection && handleWsConnected && handleWsDisconnected) {
    connection.unbind('connected', handleWsConnected)
    connection.unbind('disconnected', handleWsDisconnected)
  }
})
</script>
