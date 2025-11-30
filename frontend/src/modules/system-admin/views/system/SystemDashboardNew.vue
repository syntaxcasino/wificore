<template>
  <div class="bg-gradient-to-br from-green-50 via-emerald-50/50 to-teal-50/30 -mx-6 -my-6 px-6 py-8 pb-16">
    <!-- Enhanced Header with Welcome Message -->
    <div class="mb-10">
      <div class="flex items-center justify-between flex-wrap gap-6">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
            <div>
              <h1 class="text-4xl font-bold bg-gradient-to-r from-green-900 to-emerald-700 bg-clip-text text-transparent">System Administration</h1>
              <p class="text-sm text-gray-600 mt-1 font-medium">Platform-wide monitoring and management</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <div v-if="lastUpdated" class="px-4 py-2.5 rounded-xl bg-white shadow-md border border-gray-200/50 backdrop-blur-sm">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <span class="text-xs font-semibold text-gray-700">Updated {{ formatTimeAgo(lastUpdated) }}</span>
            </div>
          </div>
          <div class="px-5 py-2.5 rounded-xl shadow-lg bg-gradient-to-r from-green-500 to-emerald-500 text-white">
            <div class="flex items-center gap-2">
              <span class="relative flex h-2.5 w-2.5">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-white"></span>
              </span>
              <span class="text-sm font-bold">System Admin</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 border-4 border-purple-200 border-t-purple-600 rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-600">Loading system statistics...</p>
      </div>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-8">
      <!-- Error Alert -->
      <div v-if="error" class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
        <div class="flex items-center">
          <svg class="w-5 h-5 text-red-500 mr-3" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
          </svg>
          <p class="text-red-700 font-medium">{{ error }}</p>
        </div>
      </div>

      <!-- Refreshing Indicator -->
      <div v-if="refreshing" class="bg-blue-50 border-l-4 border-blue-500 p-3 rounded-lg">
        <div class="flex items-center">
          <div class="w-4 h-4 border-2 border-blue-200 border-t-blue-600 rounded-full animate-spin mr-3"></div>
          <p class="text-blue-700 text-sm">Refreshing data...</p>
        </div>
      </div>

      <!-- System Stats Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        <!-- Total Tenants -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
            </div>
            <span class="text-xs font-semibold text-blue-600 bg-blue-50 px-3 py-1 rounded-full">Total</span>
          </div>
          <h3 class="text-3xl font-bold text-gray-900 mb-1">{{ stats.totalTenants || 0 }}</h3>
          <p class="text-sm text-gray-600 font-medium">Registered Tenants</p>
        </div>

        <!-- Active Tenants -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <span class="text-xs font-semibold text-green-600 bg-green-50 px-3 py-1 rounded-full">Active</span>
          </div>
          <h3 class="text-3xl font-bold text-gray-900 mb-1">{{ stats.activeTenants || 0 }}</h3>
          <p class="text-sm text-gray-600 font-medium">Active Tenants</p>
        </div>

        <!-- Total Users -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <span class="text-xs font-semibold text-purple-600 bg-purple-50 px-3 py-1 rounded-full">Platform</span>
          </div>
          <h3 class="text-3xl font-bold text-gray-900 mb-1">{{ stats.totalUsers || 0 }}</h3>
          <p class="text-sm text-gray-600 font-medium">Total Users</p>
        </div>

        <!-- Total Routers -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
              </svg>
            </div>
            <span class="text-xs font-semibold text-indigo-600 bg-indigo-50 px-3 py-1 rounded-full">Network</span>
          </div>
          <h3 class="text-3xl font-bold text-gray-900 mb-1">{{ stats.totalRouters || 0 }}</h3>
          <p class="text-sm text-gray-600 font-medium">Total Routers</p>
        </div>
      </div>

      <!-- System Monitoring Widgets -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- System Health Widget -->
        <SystemHealthWidget />
        
        <!-- Queue Statistics Widget -->
        <QueueStatsWidget />
        
        <!-- Performance Metrics Widget -->
        <PerformanceMetricsWidget />
      </div>

      <!-- System Health & Performance -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- System Health -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
          <div class="flex items-center justify-between mb-6">
            <h2 class="text-xl font-bold text-gray-900">System Health</h2>
            <span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-800">
              Healthy
            </span>
          </div>
          <div class="space-y-4">
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Database</span>
              <div class="flex items-center gap-2">
                <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div class="h-full bg-green-500 rounded-full" :style="{ width: '95%' }"></div>
                </div>
                <span class="text-xs font-semibold text-green-600">Healthy</span>
              </div>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Redis Cache</span>
              <div class="flex items-center gap-2">
                <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div class="h-full bg-green-500 rounded-full" :style="{ width: '98%' }"></div>
                </div>
                <span class="text-xs font-semibold text-green-600">Healthy</span>
              </div>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Queue Workers</span>
              <div class="flex items-center gap-2">
                <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div class="h-full bg-green-500 rounded-full" :style="{ width: '100%' }"></div>
                </div>
                <span class="text-xs font-semibold text-green-600">Running</span>
              </div>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-sm text-gray-600">Disk Space</span>
              <div class="flex items-center gap-2">
                <div class="w-24 h-2 bg-gray-200 rounded-full overflow-hidden">
                  <div class="h-full bg-yellow-500 rounded-full" :style="{ width: '75%' }"></div>
                </div>
                <span class="text-xs font-semibold text-yellow-600">75% Used</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Performance Metrics -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
          <h2 class="text-xl font-bold text-gray-900 mb-6">Performance Metrics</h2>
          <div class="space-y-4">
            <div class="flex items-center justify-between p-4 bg-blue-50 rounded-xl">
              <div>
                <p class="text-sm text-gray-600 mb-1">Avg Response Time</p>
                <p class="text-2xl font-bold text-blue-600">{{ stats.avgResponseTime || '0.03' }}s</p>
              </div>
              <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
            </div>
            <div class="flex items-center justify-between p-4 bg-green-50 rounded-xl">
              <div>
                <p class="text-sm text-gray-600 mb-1">Uptime</p>
                <p class="text-2xl font-bold text-green-600">{{ stats.uptime || '99.9' }}%</p>
              </div>
              <div class="w-12 h-12 bg-green-500 rounded-xl flex items-center justify-center">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
        <h2 class="text-xl font-bold text-gray-900 mb-6">Recent Platform Activity</h2>
        <div class="space-y-4">
          <div v-for="(activity, index) in recentActivities" :key="index" class="flex items-start gap-4 p-4 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors">
            <div class="w-10 h-10 rounded-full flex items-center justify-center flex-shrink-0" :class="activity.iconBg">
              <svg class="w-5 h-5" :class="activity.iconColor" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="activity.iconPath" />
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <p class="text-sm font-semibold text-gray-900">{{ activity.title }}</p>
              <p class="text-xs text-gray-600 mt-1">{{ activity.description }}</p>
              <p class="text-xs text-gray-500 mt-1">{{ activity.time }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'
import SystemHealthWidget from '@/modules/system-admin/components/dashboard/SystemHealthWidget.vue'
import QueueStatsWidget from '@/modules/system-admin/components/dashboard/QueueStatsWidget.vue'
import PerformanceMetricsWidget from '@/modules/system-admin/components/dashboard/PerformanceMetricsWidget.vue'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || 'http://localhost/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

const authStore = useAuthStore()
const user = computed(() => authStore.user)

const loading = ref(true)
const refreshing = ref(false)
const error = ref(null)
const stats = ref({
  totalTenants: 0,
  activeTenants: 0,
  totalUsers: 0,
  totalRouters: 0,
  avgResponseTime: '0.00',
  uptime: '0.0'
})

const lastUpdated = ref(null)

const systemHealthStatus = computed(() => 'Healthy')
const systemHealthClass = computed(() => 'bg-green-100 text-green-800')

const recentActivities = ref([
  {
    title: 'New Tenant Registered',
    description: 'Acme Corporation joined the platform',
    time: '5 minutes ago',
    iconBg: 'bg-blue-100',
    iconColor: 'text-blue-600',
    iconPath: 'M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4'
  },
  {
    title: 'System Update',
    description: 'Platform updated to version 2.1.0',
    time: '1 hour ago',
    iconBg: 'bg-green-100',
    iconColor: 'text-green-600',
    iconPath: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'
  },
  {
    title: 'Performance Alert',
    description: 'High CPU usage detected on server-02',
    time: '2 hours ago',
    iconBg: 'bg-yellow-100',
    iconColor: 'text-yellow-600',
    iconPath: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z'
  }
])

const formatTimeAgo = (date) => {
  if (!date) return ''
  const seconds = Math.floor((new Date() - new Date(date)) / 1000)
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  return `${hours}h ago`
}

const fetchStats = async (isInitial = false) => {
  try {
    // Only show loading spinner on initial load, not on background refresh
    if (isInitial) {
      loading.value = true
    }
    // Don't set refreshing.value - causes visual jank
    error.value = null
    
    const response = await api.get('/system/dashboard/stats')
    if (response.data.success) {
      // Use requestAnimationFrame for smooth updates
      requestAnimationFrame(() => {
        stats.value = response.data.data
        lastUpdated.value = new Date().toISOString()
      })
    }
  } catch (err) {
    console.error('Failed to fetch system stats:', err)
    
    // Check if it's an authentication error (401, 403) or server unreachable
    if (err.response?.status === 401 || err.response?.status === 403) {
      // SECURITY: Auto-logout on authentication failure
      console.warn('Authentication failed - logging out user')
      authStore.logout()
      window.location.href = '/login'
      return
    }
    
    // Check if server is completely unreachable
    if (!err.response || err.code === 'ERR_NETWORK' || err.code === 'ECONNREFUSED') {
      console.error('Server unreachable - logging out for security')
      authStore.logout()
      window.location.href = '/login'
      return
    }
    
    error.value = err.response?.data?.message || 'Failed to load dashboard statistics'
    
    // Keep existing data on refresh errors (only for non-critical errors)
    if (isInitial) {
      // Set default values on initial load error
      stats.value = {
        totalTenants: 0,
        activeTenants: 0,
        totalUsers: 0,
        totalRouters: 0,
        avgResponseTime: '0.00',
        uptime: '0.0'
      }
    }
  } finally {
    if (isInitial) {
      loading.value = false
    }
  }
}

let refreshInterval = null

onMounted(() => {
  fetchStats(true)
  // Refresh stats every 30 seconds (without showing loading spinner)
  refreshInterval = setInterval(() => fetchStats(false), 30000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
