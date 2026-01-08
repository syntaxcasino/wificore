<template>
  <div class="min-h-screen bg-gray-50 -mx-6 -my-6 px-6 py-6">
    <!-- Simple Header -->
    <div class="mb-6">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
          <p class="text-sm text-gray-600 mt-1">Monitor your network performance</p>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="refreshStats"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors"
          >
            <svg class="w-4 h-4 inline-block mr-2" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            Refresh
          </button>
          <div class="flex items-center gap-2 px-3 py-2 rounded-lg" :class="isConnected ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'">
            <span class="relative flex h-2 w-2">
              <span v-if="isConnected" class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
              <span class="relative inline-flex rounded-full h-2 w-2" :class="isConnected ? 'bg-green-500' : 'bg-red-500'"></span>
            </span>
            <span class="text-xs font-semibold">{{ isConnected ? 'Live' : 'Offline' }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-32">
      <div class="text-center">
        <div class="w-12 h-12 border-3 border-gray-200 border-t-blue-600 rounded-full animate-spin mx-auto mb-3"></div>
        <p class="text-sm text-gray-600">Loading dashboard...</p>
      </div>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-6">
      
      <!-- KEY METRICS - Top Row -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Total Revenue -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center">
              <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <span v-if="revenueGrowth" class="text-xs font-semibold px-2 py-1 rounded-full" :class="revenueGrowth.isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
              {{ revenueGrowth.isPositive ? '+' : '' }}{{ revenueGrowth.value }}%
            </span>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Total Revenue</p>
          <h3 class="text-2xl font-bold text-gray-900">{{ formatCurrency(stats.totalRevenue) }}</h3>
        </div>

        <!-- Active Users -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center">
              <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Active Sessions</p>
          <h3 class="text-2xl font-bold text-gray-900">{{ stats.activeSessions || 0 }}</h3>
          <p class="text-xs text-gray-500 mt-1">{{ stats.totalUsers || 0 }} total users</p>
        </div>

        <!-- Network Health -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 bg-indigo-100 rounded-lg flex items-center justify-center">
              <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="routerHealthStatus?.bgColor + ' ' + routerHealthStatus?.color">
              {{ routerHealthStatus?.label }}
            </span>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Network Health</p>
          <h3 class="text-2xl font-bold text-gray-900">{{ stats.onlineRouters || 0 }}/{{ stats.totalRouters || 0 }}</h3>
          <p class="text-xs text-gray-500 mt-1">Routers online</p>
        </div>

        <!-- Data Usage -->
        <div class="bg-white rounded-lg border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
              <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
              </svg>
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Data Usage</p>
          <h3 class="text-2xl font-bold text-gray-900">{{ formatDataSize(stats.dataUsage) }}</h3>
          <p class="text-xs text-gray-500 mt-1">Total transferred</p>
        </div>
      </div>

      <!-- PAYMENT ANALYTICS -->
      <PaymentWidget :paymentData="paymentData" />

      <!-- TWO COLUMN LAYOUT -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- SMS Expenses -->
        <ExpensesWidget :expensesData="expensesData" />

        <!-- Business Analytics -->
        <BusinessAnalyticsWidget :analyticsData="analyticsData" />
      </div>

      <!-- QUICK ACTIONS -->
      <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Quick Actions</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <router-link 
            to="/dashboard/hotspot/users"
            class="flex flex-col items-center gap-3 p-4 rounded-lg border-2 border-gray-200 hover:border-blue-500 hover:bg-blue-50 transition-all group"
          >
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center group-hover:bg-blue-200 transition-colors">
              <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span class="text-sm font-semibold text-gray-900">Manage Users</span>
          </router-link>

          <router-link 
            to="/dashboard/routers/mikrotik"
            class="flex flex-col items-center gap-3 p-4 rounded-lg border-2 border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all group"
          >
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center group-hover:bg-green-200 transition-colors">
              <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-sm font-semibold text-gray-900">Routers</span>
          </router-link>

          <router-link 
            to="/dashboard/packages/all"
            class="flex flex-col items-center gap-3 p-4 rounded-lg border-2 border-gray-200 hover:border-purple-500 hover:bg-purple-50 transition-all group"
          >
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center group-hover:bg-purple-200 transition-colors">
              <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
            </div>
            <span class="text-sm font-semibold text-gray-900">Packages</span>
          </router-link>

          <router-link 
            to="/dashboard/reports/revenue"
            class="flex flex-col items-center gap-3 p-4 rounded-lg border-2 border-gray-200 hover:border-amber-500 hover:bg-amber-50 transition-all group"
          >
            <div class="w-12 h-12 bg-amber-100 rounded-lg flex items-center justify-center group-hover:bg-amber-200 transition-colors">
              <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
              </svg>
            </div>
            <span class="text-sm font-semibold text-gray-900">Reports</span>
          </router-link>
        </div>
      </div>

    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuth } from '@/modules/common/composables/auth/useAuth'
import { useDashboard } from '@/modules/tenant/composables/data/useDashboard'
import PaymentWidget from '@/modules/tenant/components/dashboard/PaymentWidgetClean.vue'
import ExpensesWidget from '@/modules/tenant/components/dashboard/ExpensesWidgetClean.vue'
import BusinessAnalyticsWidget from '@/modules/tenant/components/dashboard/BusinessAnalyticsWidgetClean.vue'

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

// Subscribe to WebSocket channels (tenant-specific for security)
onMounted(() => {
  fetchDashboardStats()
  
  const tenantId = user.value?.tenant_id
  if (!tenantId) {
    console.warn('No tenant_id available - cannot subscribe to tenant channels')
    return
  }

  subscribeToPrivateChannel(`tenant.${tenantId}.dashboard-stats`, {
    'DashboardStatsUpdated': (event) => {
      if (event.stats) updateStatsFromEvent(event.stats)
    },
  })

  subscribeToPrivateChannel(`tenant.${tenantId}.routers`, {
    'RouterStatusUpdated': (event) => {
      if (event.stats) updateStatsFromEvent(event.stats)
    },
  })
})
</script>
