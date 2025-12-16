<template>
  <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
        </svg>
        System Health
      </h2>
      <span class="px-3 py-1 rounded-full text-xs font-semibold" :class="overallHealthClass">
        {{ overallHealthStatus }}
      </span>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !healthData" class="flex items-center justify-center py-12">
      <div class="w-12 h-12 border-4 border-green-200 border-t-green-600 rounded-full animate-spin"></div>
    </div>

    <!-- Health Metrics -->
    <div v-else class="space-y-4">
      <!-- Database Health -->
      <div class="p-4 bg-gray-50 rounded-xl">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-full" :class="healthData.database?.status === 'healthy' ? 'bg-green-500' : 'bg-red-500'"></div>
            <span class="text-sm font-semibold text-gray-700">Database</span>
          </div>
          <span class="text-xs font-medium" :class="healthData.database?.status === 'healthy' ? 'text-green-600' : 'text-red-600'">
            {{ healthData.database?.status || 'Unknown' }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div>
            <p class="text-gray-600">Connections</p>
            <p class="font-bold text-gray-900">{{ healthData.database?.connections || 0 }}/{{ healthData.database?.maxConnections || 100 }}</p>
          </div>
          <div>
            <p class="text-gray-600">Response Time</p>
            <p class="font-bold text-gray-900">{{ healthData.database?.responseTime || '0' }}ms</p>
          </div>
        </div>
        <div class="mt-2">
          <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
            <div 
              class="h-full rounded-full transition-all duration-300"
              :class="healthData.database?.status === 'healthy' ? 'bg-green-500' : 'bg-red-500'"
              :style="{ width: `${healthData.database?.healthPercentage || 95}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- Redis Cache -->
      <div class="p-4 bg-gray-50 rounded-xl">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-full" :class="healthData.redis?.status === 'healthy' ? 'bg-green-500' : 'bg-yellow-500'"></div>
            <span class="text-sm font-semibold text-gray-700">Redis Cache</span>
          </div>
          <span class="text-xs font-medium" :class="healthData.redis?.status === 'healthy' ? 'text-green-600' : 'text-yellow-600'">
            {{ healthData.redis?.status || 'Unknown' }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div>
            <p class="text-gray-600">Hit Rate</p>
            <p class="font-bold text-gray-900">{{ healthData.redis?.hitRate || 0 }}%</p>
          </div>
          <div>
            <p class="text-gray-600">Memory Used</p>
            <p class="font-bold text-gray-900">{{ healthData.redis?.memoryUsed || '0' }}MB</p>
          </div>
        </div>
        <div class="mt-2">
          <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
            <div 
              class="h-full bg-green-500 rounded-full transition-all duration-300"
              :style="{ width: `${healthData.redis?.healthPercentage || 98}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- Queue Workers -->
      <div class="p-4 bg-gray-50 rounded-xl">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-full" :class="getQueueHealthColor()"></div>
            <span class="text-sm font-semibold text-gray-700">Queue Workers</span>
          </div>
          <span class="text-xs font-medium" :class="getQueueHealthTextColor()">
            {{ getQueueHealthStatus() }}
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div>
            <p class="text-gray-600">Active Workers</p>
            <p class="font-bold text-gray-900">{{ healthData.queue?.activeWorkers || 0 }}</p>
          </div>
          <div>
            <p class="text-gray-600">Failed Jobs</p>
            <p class="font-bold text-gray-900">{{ healthData.queue?.failedJobs || 0 }}</p>
          </div>
        </div>
        <div class="mt-2">
          <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
            <div 
              class="h-full rounded-full transition-all duration-300"
              :class="healthData.queue?.status === 'healthy' ? 'bg-green-500' : 'bg-red-500'"
              :style="{ width: `${healthData.queue?.healthPercentage || 100}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- Disk Space -->
      <div class="p-4 bg-gray-50 rounded-xl">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <div class="w-3 h-3 rounded-full" :class="getDiskHealthColor(healthData.disk?.usedPercentage)"></div>
            <span class="text-sm font-semibold text-gray-700">Disk Space</span>
          </div>
          <span class="text-xs font-medium" :class="getDiskHealthTextColor(healthData.disk?.usedPercentage)">
            {{ healthData.disk?.usedPercentage || 0 }}% Used
          </span>
        </div>
        <div class="grid grid-cols-2 gap-3 text-xs">
          <div>
            <p class="text-gray-600">Total</p>
            <p class="font-bold text-gray-900">{{ healthData.disk?.total || '0' }}GB</p>
          </div>
          <div>
            <p class="text-gray-600">Available</p>
            <p class="font-bold text-gray-900">{{ healthData.disk?.available || '0' }}GB</p>
          </div>
        </div>
        <div class="mt-2">
          <div class="w-full h-2 bg-gray-200 rounded-full overflow-hidden">
            <div 
              class="h-full rounded-full transition-all duration-300"
              :class="getDiskHealthBgColor(healthData.disk?.usedPercentage)"
              :style="{ width: `${healthData.disk?.usedPercentage || 75}%` }"
            ></div>
          </div>
        </div>
      </div>

      <!-- System Uptime -->
      <div class="mt-4 p-4 bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl border border-green-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-green-700 mb-1">System Uptime</p>
            <p class="text-2xl font-bold text-green-900">{{ healthData.uptime?.percentage || '0.0' }}%</p>
          </div>
          <div class="text-right">
            <p class="text-xs text-green-600">{{ healthData.uptime?.duration || 'Loading...' }}</p>
            <p class="text-xs text-green-600 mt-1">Last restart: {{ healthData.uptime?.lastRestart || 'Loading...' }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

// Add auth token to requests
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('authToken')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

const loading = ref(false)
const healthData = ref({
  database: { status: 'loading', connections: 0, maxConnections: 100, responseTime: 0, healthPercentage: 0 },
  redis: { status: 'loading', hitRate: 0, memoryUsed: 0, healthPercentage: 0 },
  queue: { status: 'loading', activeWorkers: 0, failedJobs: 0, healthPercentage: 0 },
  disk: { total: 0, available: 0, usedPercentage: 0 },
  uptime: { percentage: 0, duration: 'Loading...', lastRestart: 'Loading...' }
})

let refreshInterval = null

const overallHealthStatus = computed(() => {
  const statuses = [
    healthData.value.database?.status,
    healthData.value.redis?.status,
    healthData.value.queue?.status
  ]
  
  if (statuses.includes('critical')) return 'Critical'
  if (statuses.includes('warning')) return 'Warning'
  if (statuses.every(s => s === 'healthy')) return 'Healthy'
  return 'Degraded'
})

const overallHealthClass = computed(() => {
  const status = overallHealthStatus.value
  if (status === 'Healthy') return 'bg-green-100 text-green-800'
  if (status === 'Warning') return 'bg-yellow-100 text-yellow-800'
  if (status === 'Degraded') return 'bg-orange-100 text-orange-800'
  return 'bg-red-100 text-red-800'
})

const getDiskHealthColor = (percentage) => {
  if (percentage >= 90) return 'bg-red-500'
  if (percentage >= 75) return 'bg-yellow-500'
  return 'bg-green-500'
}

const getDiskHealthTextColor = (percentage) => {
  if (percentage >= 90) return 'text-red-600'
  if (percentage >= 75) return 'text-yellow-600'
  return 'text-green-600'
}

const getDiskHealthBgColor = (percentage) => {
  if (percentage >= 90) return 'bg-red-500'
  if (percentage >= 75) return 'bg-yellow-500'
  return 'bg-green-500'
}

const getQueueHealthColor = () => {
  const workers = healthData.value.queue?.activeWorkers || 0
  if (workers === 0) return 'bg-red-500'
  if (workers < 5) return 'bg-yellow-500'
  return 'bg-green-500'
}

const getQueueHealthTextColor = () => {
  const workers = healthData.value.queue?.activeWorkers || 0
  if (workers === 0) return 'text-red-600'
  if (workers < 5) return 'text-yellow-600'
  return 'text-green-600'
}

const getQueueHealthStatus = () => {
  const workers = healthData.value.queue?.activeWorkers || 0
  if (workers === 0) return 'No Workers'
  if (workers < 5) return 'Limited'
  return 'Healthy'
}

const fetchHealthData = async (showLoading = false) => {
  try {
    if (showLoading) {
      loading.value = true
    }
    
    const response = await api.get('/system/health')
    if (response.data) {
      // Use requestAnimationFrame for smooth updates
      requestAnimationFrame(() => {
        healthData.value = response.data
      })
    }
  } catch (error) {
    console.error('Failed to fetch health data:', error)
    // Keep existing data on error
  } finally {
    if (showLoading) {
      loading.value = false
    }
  }
}

onMounted(() => {
  fetchHealthData(true) // Show loading on initial load
  // Refresh every 15 seconds in background
  refreshInterval = setInterval(() => fetchHealthData(false), 15000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
