<template>
  <div class="bg-gradient-to-br from-green-50 via-emerald-50/50 to-teal-50/30 -mx-2 -my-2 px-2 py-4 pb-10 sm:-mx-6 sm:-my-6 sm:px-6 sm:py-8 sm:pb-16">
    <!-- Enhanced Header with Welcome Message -->
    <div class="mb-6 sm:mb-10">
      <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 sm:gap-6">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg flex-shrink-0">
              <svg class="w-5 h-5 sm:w-7 sm:h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
            <div>
              <h1 class="text-2xl sm:text-4xl font-bold bg-gradient-to-r from-green-900 to-emerald-700 bg-clip-text text-transparent">System Administration</h1>
              <p class="text-xs sm:text-sm text-gray-600 mt-1 font-medium">Platform-wide monitoring and management</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-2 sm:gap-3">
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

      <!-- System Stats Grid — Clickable Cards -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-6">
        <!-- Total Tenants -->
        <div class="bg-white rounded-xl sm:rounded-2xl shadow-lg border border-gray-100 p-4 sm:p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 cursor-pointer" @click="openStatDetail('tenants')">
          <div class="flex items-center justify-between mb-3 sm:mb-4">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-blue-500 to-blue-600 rounded-xl flex items-center justify-center shadow-lg">
              <Building2 class="w-5 h-5 sm:w-6 sm:h-6 text-white" />
            </div>
            <span class="text-[10px] sm:text-xs font-semibold text-blue-600 bg-blue-50 px-2 sm:px-3 py-1 rounded-full">Total</span>
          </div>
          <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1">{{ stats.totalTenants || 0 }}</h3>
          <p class="text-xs sm:text-sm text-gray-600 font-medium">Registered Tenants</p>
        </div>

        <!-- Active Tenants -->
        <div class="bg-white rounded-xl sm:rounded-2xl shadow-lg border border-gray-100 p-4 sm:p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 cursor-pointer" @click="openStatDetail('active')">
          <div class="flex items-center justify-between mb-3 sm:mb-4">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-green-500 to-emerald-600 rounded-xl flex items-center justify-center shadow-lg">
              <CheckCircle class="w-5 h-5 sm:w-6 sm:h-6 text-white" />
            </div>
            <span class="text-[10px] sm:text-xs font-semibold text-green-600 bg-green-50 px-2 sm:px-3 py-1 rounded-full">Active</span>
          </div>
          <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1">{{ stats.activeTenants || 0 }}</h3>
          <p class="text-xs sm:text-sm text-gray-600 font-medium">Active Tenants</p>
        </div>

        <!-- Total Users -->
        <div class="bg-white rounded-xl sm:rounded-2xl shadow-lg border border-gray-100 p-4 sm:p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 cursor-pointer" @click="openStatDetail('users')">
          <div class="flex items-center justify-between mb-3 sm:mb-4">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-purple-500 to-purple-600 rounded-xl flex items-center justify-center shadow-lg">
              <Users class="w-5 h-5 sm:w-6 sm:h-6 text-white" />
            </div>
            <span class="text-[10px] sm:text-xs font-semibold text-purple-600 bg-purple-50 px-2 sm:px-3 py-1 rounded-full">Platform</span>
          </div>
          <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1">{{ stats.totalUsers || 0 }}</h3>
          <p class="text-xs sm:text-sm text-gray-600 font-medium">Total Users</p>
        </div>

        <!-- Total Routers -->
        <div class="bg-white rounded-xl sm:rounded-2xl shadow-lg border border-gray-100 p-4 sm:p-6 hover:shadow-xl transition-all duration-300 transform hover:-translate-y-1 cursor-pointer" @click="openStatDetail('routers')">
          <div class="flex items-center justify-between mb-3 sm:mb-4">
            <div class="w-10 h-10 sm:w-12 sm:h-12 bg-gradient-to-br from-indigo-500 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <Wifi class="w-5 h-5 sm:w-6 sm:h-6 text-white" />
            </div>
            <span class="text-[10px] sm:text-xs font-semibold text-indigo-600 bg-indigo-50 px-2 sm:px-3 py-1 rounded-full">Network</span>
          </div>
          <h3 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-1">{{ stats.totalRouters || 0 }}</h3>
          <p class="text-xs sm:text-sm text-gray-600 font-medium">Total Routers</p>
        </div>
      </div>

      <!-- System Monitoring Widgets -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 sm:gap-6">
        <!-- System Health Widget -->
        <SystemHealthWidget />
        
        <!-- Queue Statistics Widget -->
        <QueueStatsWidget />
        
        <!-- Performance Metrics Widget -->
        <PerformanceMetricsWidget />
      </div>

      <!-- Recent Activity Table -->
      <div class="bg-white rounded-xl sm:rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="flex items-center justify-between px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-xl font-bold text-gray-900">Recent Platform Activity</h2>
          <button @click="fetchActivities" :disabled="activitiesLoading" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
            <RefreshCw class="w-4 h-4 text-gray-600" :class="activitiesLoading ? 'animate-spin' : ''" />
          </button>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full min-w-[600px]">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">Time</th>
              <th class="text-left px-4 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">User</th>
              <th class="text-left px-4 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">Action</th>
              <th class="text-left px-4 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">Details</th>
              <th class="text-right px-4 sm:px-6 py-3 text-xs font-medium text-gray-500 uppercase">View</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="log in recentActivities" :key="log.id" class="hover:bg-gray-50 transition-colors cursor-pointer" @click="openActivityDetail(log)">
              <td class="px-4 sm:px-6 py-3 sm:py-4 text-xs text-gray-500 whitespace-nowrap">{{ formatActivityDate(log.created_at) }}</td>
              <td class="px-4 sm:px-6 py-3 sm:py-4 text-sm font-medium text-gray-900">{{ log.user?.name || log.causer?.name || log.username || '-' }}</td>
              <td class="px-4 sm:px-6 py-3 sm:py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="activityActionClass(log.action || log.event || log.description)">
                  {{ log.action || log.event || log.description || '-' }}
                </span>
              </td>
              <td class="px-4 sm:px-6 py-3 sm:py-4 text-xs text-gray-500 max-w-xs truncate hidden sm:table-cell">{{ log.description || '-' }}</td>
              <td class="px-4 sm:px-6 py-3 sm:py-4 text-right">
                <button @click.stop="openActivityDetail(log)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button>
              </td>
            </tr>
            <tr v-if="recentActivities.length === 0">
              <td colspan="5" class="px-4 sm:px-6 py-8 text-center text-gray-400 text-sm">{{ activitiesLoading ? 'Loading...' : 'No recent activity' }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- Stat Detail Overlay -->
    <SlideOverlay v-model="showStatOverlay" :title="statOverlayTitle" :subtitle="statOverlaySubtitle" :icon="statOverlayIcon" width="40%" @close="showStatOverlay = false">
      <div class="space-y-4">
        <div class="flex items-center justify-between p-4 rounded-lg" :class="statOverlayBg">
          <span class="text-sm font-medium text-gray-700">Current Count</span>
          <span class="text-3xl font-bold" :class="statOverlayColor">{{ statOverlayValue }}</span>
        </div>
        <div v-if="statOverlayDetails.length" class="space-y-2">
          <div v-for="(item, idx) in statOverlayDetails" :key="idx" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
            <span class="text-sm font-medium text-gray-600">{{ item.label }}</span>
            <span class="text-sm font-semibold text-gray-900">{{ item.value }}</span>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showStatOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Activity Detail Overlay -->
    <SlideOverlay v-model="showActivityOverlay" title="Activity Detail" subtitle="Full activity log entry" icon="FileText" width="40%" @close="showActivityOverlay = false">
      <div v-if="selectedActivity" class="space-y-3">
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Time</span>
          <span class="text-sm text-gray-900">{{ formatActivityDate(selectedActivity.created_at) }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">User</span>
          <span class="text-sm font-semibold text-gray-900">{{ selectedActivity.user?.name || selectedActivity.causer?.name || selectedActivity.username || '-' }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Action</span>
          <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="activityActionClass(selectedActivity.action || selectedActivity.event || selectedActivity.description)">
            {{ selectedActivity.action || selectedActivity.event || selectedActivity.description || '-' }}
          </span>
        </div>
        <div v-if="selectedActivity.description" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Description</span>
          <span class="text-sm text-gray-900 text-right max-w-[60%]">{{ selectedActivity.description }}</span>
        </div>
        <div v-if="selectedActivity.ip_address" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">IP Address</span>
          <span class="text-sm font-mono text-gray-900">{{ selectedActivity.ip_address }}</span>
        </div>
        <div v-if="selectedActivity.properties || selectedActivity.details">
          <h3 class="text-sm font-semibold text-gray-900 mb-2 mt-4">Properties</h3>
          <div class="space-y-2">
            <div v-for="(val, prop) in (selectedActivity.properties || selectedActivity.details)" :key="prop" class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
              <span class="text-sm font-medium text-gray-700 capitalize">{{ String(prop).replace(/_/g, ' ') }}</span>
              <span class="text-sm text-blue-700 text-right max-w-[60%] break-all">{{ typeof val === 'object' ? JSON.stringify(val) : val }}</span>
            </div>
          </div>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showActivityOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'
import { Building2, CheckCircle, Users, Wifi, RefreshCw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import SystemHealthWidget from '@/modules/system-admin/components/dashboard/SystemHealthWidget.vue'
import QueueStatsWidget from '@/modules/system-admin/components/dashboard/QueueStatsWidget.vue'
import PerformanceMetricsWidget from '@/modules/system-admin/components/dashboard/PerformanceMetricsWidget.vue'

// Use global axios instance (has auth interceptor with Bearer token)
// Do NOT create a standalone instance — it won't have the Authorization header
const api = axios

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

// Activity logs (real data)
const recentActivities = ref([])
const activitiesLoading = ref(false)

// Stat detail overlay
const showStatOverlay = ref(false)
const statOverlayTitle = ref('')
const statOverlaySubtitle = ref('')
const statOverlayIcon = ref('BarChart3')
const statOverlayValue = ref(0)
const statOverlayBg = ref('bg-blue-50')
const statOverlayColor = ref('text-blue-700')
const statOverlayDetails = ref([])

// Activity detail overlay
const showActivityOverlay = ref(false)
const selectedActivity = ref(null)

const formatTimeAgo = (date) => {
  if (!date) return ''
  const seconds = Math.floor((new Date() - new Date(date)) / 1000)
  if (seconds < 60) return `${seconds}s ago`
  const minutes = Math.floor(seconds / 60)
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  return `${hours}h ago`
}

const formatActivityDate = (dateStr) => {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const activityActionClass = (action) => {
  if (!action) return 'bg-gray-100 text-gray-700'
  const a = action.toLowerCase()
  if (a.includes('create') || a.includes('add')) return 'bg-green-100 text-green-700'
  if (a.includes('delete') || a.includes('remove') || a.includes('suspend')) return 'bg-red-100 text-red-700'
  if (a.includes('update') || a.includes('edit') || a.includes('change')) return 'bg-blue-100 text-blue-700'
  if (a.includes('login') || a.includes('auth')) return 'bg-purple-100 text-purple-700'
  return 'bg-gray-100 text-gray-700'
}

const openStatDetail = (type) => {
  const configs = {
    tenants: {
      title: 'Registered Tenants',
      subtitle: 'Total tenants on the platform',
      icon: 'Building2',
      value: stats.value.totalTenants || 0,
      bg: 'bg-blue-50',
      color: 'text-blue-700',
      details: [
        { label: 'Total Tenants', value: stats.value.totalTenants || 0 },
        { label: 'Active Tenants', value: stats.value.activeTenants || 0 },
        { label: 'Inactive', value: (stats.value.totalTenants || 0) - (stats.value.activeTenants || 0) },
      ]
    },
    active: {
      title: 'Active Tenants',
      subtitle: 'Currently active tenant accounts',
      icon: 'CheckCircle',
      value: stats.value.activeTenants || 0,
      bg: 'bg-green-50',
      color: 'text-green-700',
      details: [
        { label: 'Active', value: stats.value.activeTenants || 0 },
        { label: 'Total', value: stats.value.totalTenants || 0 },
        { label: 'Active Rate', value: stats.value.totalTenants ? Math.round((stats.value.activeTenants / stats.value.totalTenants) * 100) + '%' : 'N/A' },
      ]
    },
    users: {
      title: 'Platform Users',
      subtitle: 'Total users across all tenants',
      icon: 'Users',
      value: stats.value.totalUsers || 0,
      bg: 'bg-purple-50',
      color: 'text-purple-700',
      details: [
        { label: 'Total Users', value: stats.value.totalUsers || 0 },
        { label: 'Avg per Tenant', value: stats.value.totalTenants ? Math.round((stats.value.totalUsers || 0) / stats.value.totalTenants) : 'N/A' },
      ]
    },
    routers: {
      title: 'Network Routers',
      subtitle: 'Total routers across all tenants',
      icon: 'Wifi',
      value: stats.value.totalRouters || 0,
      bg: 'bg-indigo-50',
      color: 'text-indigo-700',
      details: [
        { label: 'Total Routers', value: stats.value.totalRouters || 0 },
        { label: 'Avg per Tenant', value: stats.value.totalTenants ? Math.round((stats.value.totalRouters || 0) / stats.value.totalTenants) : 'N/A' },
      ]
    }
  }
  const cfg = configs[type]
  if (!cfg) return
  statOverlayTitle.value = cfg.title
  statOverlaySubtitle.value = cfg.subtitle
  statOverlayIcon.value = cfg.icon
  statOverlayValue.value = cfg.value
  statOverlayBg.value = cfg.bg
  statOverlayColor.value = cfg.color
  statOverlayDetails.value = cfg.details
  showStatOverlay.value = true
}

const openActivityDetail = (log) => {
  selectedActivity.value = log
  showActivityOverlay.value = true
}

const fetchActivities = async () => {
  try {
    activitiesLoading.value = true
    const res = await api.get('/system/activity-logs', { params: { per_page: 10 } })
    const data = res.data.data || res.data.logs || res.data
    if (Array.isArray(data)) {
      recentActivities.value = data.slice(0, 10)
    } else if (data.data) {
      recentActivities.value = data.data.slice(0, 10)
    } else {
      recentActivities.value = []
    }
  } catch (err) {
    if (err.response?.status === 401) return
    console.error('Failed to fetch activities:', err)
  } finally {
    activitiesLoading.value = false
  }
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
    
    // 401 is handled by the global axios interceptor in main.js (auto-logout + redirect)
    // Do NOT duplicate logout logic here — it causes redirect loops
    if (err.response?.status === 401) {
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
  fetchActivities()
  // Refresh stats every 30 seconds (without showing loading spinner)
  refreshInterval = setInterval(() => fetchStats(false), 30000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
