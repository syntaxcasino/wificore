<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Professional Header -->
    <div class="bg-white border-b border-gray-200 px-6 py-4">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
          <p class="text-sm text-gray-600 mt-0.5">Welcome back! Here's what's happening with your network today.</p>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="refreshStats"
            class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors flex items-center gap-2"
          >
            <svg class="w-4 h-4" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
    <div v-else class="p-6 space-y-6">
      
      <!-- Payment Analytics Widget -->
      <section>
        <PaymentWidget :paymentData="paymentData" />
      </section>

      <!-- Key Metrics Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Users -->
        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-blue-50 rounded-lg flex items-center justify-center">
              <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span v-if="userGrowth" class="text-xs font-semibold px-2 py-1 rounded-full" :class="userGrowth.isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
              {{ userGrowth.isPositive ? '+' : '' }}{{ userGrowth.value }}%
            </span>
          </div>
          <h3 class="text-sm font-medium text-gray-600 mb-1">Total Users</h3>
          <p class="text-3xl font-bold text-gray-900">{{ stats.totalUsers || 0 }}</p>
          <div class="mt-3 flex items-center gap-4 text-xs text-gray-600">
            <span class="flex items-center gap-1">
              <span class="w-2 h-2 bg-blue-500 rounded-full"></span>
              {{ stats.hotspotUsers || 0 }} Hotspot
            </span>
            <span class="flex items-center gap-1">
              <span class="w-2 h-2 bg-purple-500 rounded-full"></span>
              {{ stats.pppoeUsers || 0 }} PPPoE
            </span>
          </div>
        </div>

        <!-- Active Sessions -->
        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-green-50 rounded-lg flex items-center justify-center">
              <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
          </div>
          <h3 class="text-sm font-medium text-gray-600 mb-1">Active Sessions</h3>
          <p class="text-3xl font-bold text-gray-900">{{ stats.activeSessions || 0 }}</p>
          <p class="mt-3 text-xs text-gray-600">Currently online</p>
        </div>

        <!-- Total Routers -->
        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-indigo-50 rounded-lg flex items-center justify-center">
              <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-xs font-semibold px-2 py-1 rounded-full" :class="routerHealthStatus?.bgColor + ' ' + routerHealthStatus?.color">
              {{ routerHealthStatus?.label }}
            </span>
          </div>
          <h3 class="text-sm font-medium text-gray-600 mb-1">Total Routers</h3>
          <p class="text-3xl font-bold text-gray-900">{{ stats.totalRouters || 0 }}</p>
          <div class="mt-3 flex items-center gap-4 text-xs text-gray-600">
            <span class="flex items-center gap-1">
              <span class="w-2 h-2 bg-green-500 rounded-full"></span>
              {{ stats.onlineRouters || 0 }} online
            </span>
            <span class="flex items-center gap-1">
              <span class="w-2 h-2 bg-gray-400 rounded-full"></span>
              {{ stats.offlineRouters || 0 }} offline
            </span>
          </div>
        </div>

        <!-- Revenue -->
        <div class="bg-white rounded-lg border border-gray-200 p-6 hover:shadow-md transition-shadow">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-amber-50 rounded-lg flex items-center justify-center">
              <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <span v-if="revenueGrowth" class="text-xs font-semibold px-2 py-1 rounded-full" :class="revenueGrowth.isPositive ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">
              {{ revenueGrowth.isPositive ? '+' : '' }}{{ revenueGrowth.value }}%
            </span>
          </div>
          <h3 class="text-sm font-medium text-gray-600 mb-1">Total Revenue</h3>
          <p class="text-3xl font-bold text-gray-900">{{ formatCurrency(stats.totalRevenue) }}</p>
          <p class="mt-3 text-xs text-gray-600">This month</p>
        </div>
      </div>

      <!-- Charts Section -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Users Trend Chart -->
        <div class="bg-white rounded-lg border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-base font-semibold text-gray-900">Users Trend</h3>
            <select class="text-sm border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              <option>Last 7 days</option>
              <option>Last 30 days</option>
              <option>Last 90 days</option>
            </select>
          </div>
          <div class="h-64 flex items-end justify-between gap-2">
            <div v-for="(item, index) in chartData.users" :key="index" class="flex-1 flex flex-col items-center gap-2 group">
              <div class="relative w-full">
                <div 
                  class="w-full bg-gradient-to-t from-blue-600 to-blue-400 rounded-t hover:from-blue-700 hover:to-blue-500 transition-all cursor-pointer" 
                  :style="{ height: item.percentage + '%' }"
                >
                  <div class="absolute -top-8 left-1/2 transform -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-gray-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap">
                    {{ item.value }} users
                  </div>
                </div>
              </div>
              <span class="text-xs text-gray-500 font-medium">{{ chartData.labels[index] }}</span>
            </div>
          </div>
        </div>

        <!-- Revenue Chart -->
        <div class="bg-white rounded-lg border border-gray-200 p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-base font-semibold text-gray-900">Revenue Overview</h3>
            <select class="text-sm border-gray-300 rounded-lg focus:ring-blue-500 focus:border-blue-500">
              <option>This month</option>
              <option>Last month</option>
              <option>This year</option>
            </select>
          </div>
          <div class="h-64 flex items-end justify-between gap-2">
            <div v-for="(item, index) in chartData.revenue" :key="index" class="flex-1 flex flex-col items-center gap-2 group">
              <div class="relative w-full">
                <div 
                  class="w-full bg-gradient-to-t from-amber-600 to-amber-400 rounded-t hover:from-amber-700 hover:to-amber-500 transition-all cursor-pointer" 
                  :style="{ height: item.percentage + '%' }"
                >
                  <div class="absolute -top-8 left-1/2 transform -translate-x-1/2 opacity-0 group-hover:opacity-100 transition-opacity bg-gray-900 text-white text-xs px-2 py-1 rounded whitespace-nowrap">
                    {{ formatCurrency(item.value) }}
                  </div>
                </div>
              </div>
              <span class="text-xs text-gray-500 font-medium">{{ chartData.labels[index] }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- System Health & Recent Activity -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- System Health -->
        <div class="bg-white rounded-lg border border-gray-200 p-6">
          <h3 class="text-base font-semibold text-gray-900 mb-6">System Health</h3>
          <div class="space-y-5">
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-700">Router Network</span>
                <span class="text-sm font-bold text-gray-900">{{ routerHealthPercentage }}%</span>
              </div>
              <div class="w-full bg-gray-100 rounded-full h-2">
                <div 
                  class="h-full rounded-full transition-all"
                  :class="routerHealthPercentage >= 70 ? 'bg-green-500' : routerHealthPercentage >= 50 ? 'bg-yellow-500' : 'bg-red-500'"
                  :style="{ width: routerHealthPercentage + '%' }"
                ></div>
              </div>
            </div>
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-700">Active Sessions</span>
                <span class="text-sm font-bold text-gray-900">{{ stats.activeSessions }} / {{ stats.totalUsers }}</span>
              </div>
              <div class="w-full bg-gray-100 rounded-full h-2">
                <div 
                  class="h-full bg-blue-500 rounded-full transition-all"
                  :style="{ width: stats.totalUsers > 0 ? (stats.activeSessions / stats.totalUsers * 100) + '%' : '0%' }"
                ></div>
              </div>
            </div>
            <div>
              <div class="flex items-center justify-between mb-2">
                <span class="text-sm font-medium text-gray-700">Data Usage</span>
                <span class="text-sm font-bold text-gray-900">{{ formatDataSize(stats.dataUsage) }}</span>
              </div>
              <div class="w-full bg-gray-100 rounded-full h-2">
                <div class="h-full bg-gradient-to-r from-purple-500 to-purple-600 rounded-full" style="width: 65%"></div>
              </div>
            </div>
          </div>
        </div>

        <!-- Recent Activity -->
        <div class="lg:col-span-2 bg-white rounded-lg border border-gray-200 p-6">
          <h3 class="text-base font-semibold text-gray-900 mb-6">Recent Activity</h3>
          <div class="space-y-4 max-h-64 overflow-y-auto">
            <div v-for="(activity, index) in recentActivities.slice(0, 6)" :key="index" class="flex items-start gap-3 pb-4 border-b border-gray-100 last:border-0">
              <div class="w-8 h-8 bg-blue-50 rounded-lg flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div class="flex-1 min-w-0">
                <p class="text-sm text-gray-900">{{ activity.message }}</p>
                <p class="text-xs text-gray-500 mt-1">{{ activity.timestamp }}</p>
              </div>
            </div>
            <div v-if="recentActivities.length === 0" class="text-center py-12 text-gray-400 text-sm">
              No recent activity
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="bg-white rounded-lg border border-gray-200 p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Quick Actions</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <router-link 
            to="/dashboard/hotspot/users"
            class="flex flex-col items-center gap-3 p-4 rounded-lg border border-gray-200 hover:border-blue-500 hover:bg-blue-50 transition-all group"
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
            class="flex flex-col items-center gap-3 p-4 rounded-lg border border-gray-200 hover:border-green-500 hover:bg-green-50 transition-all group"
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
            class="flex flex-col items-center gap-3 p-4 rounded-lg border border-gray-200 hover:border-purple-500 hover:bg-purple-50 transition-all group"
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
            class="flex flex-col items-center gap-3 p-4 rounded-lg border border-gray-200 hover:border-amber-500 hover:bg-amber-50 transition-all group"
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

const { user } = useAuth()
const { isConnected, subscribeToPrivateChannel, subscribeToPresenceChannel } = useBroadcasting()

const {
  stats,
  paymentData,
  chartData,
  recentActivities,
  loading,
  fetchDashboardStats,
  refreshStats,
  updateStatsFromEvent,
  formatCurrency,
  formatDataSize,
  routerHealthPercentage,
  routerHealthStatus,
  revenueGrowth,
  userGrowth,
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
      recentActivities.value.unshift({
        timestamp: new Date().toLocaleTimeString(),
        message: event.message || `Router ${event.router_id} status changed`,
      })
    },
  })
})
</script>

<style scoped>
.overflow-y-auto::-webkit-scrollbar {
  width: 4px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: #f1f5f9;
  border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 4px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
</style>
