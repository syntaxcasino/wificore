<template>
  <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
        </svg>
        Performance Metrics
      </h2>
      <button @click="refreshMetrics" :disabled="loading" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
        <svg class="w-5 h-5 text-gray-600" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !metrics" class="flex items-center justify-center py-12">
      <div class="w-12 h-12 border-4 border-indigo-200 border-t-indigo-600 rounded-full animate-spin"></div>
    </div>

    <!-- Metrics Grid -->
    <div v-else class="space-y-4">
      <!-- TPS (Transactions Per Second) -->
      <div class="p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl border border-blue-200">
        <div class="flex items-center justify-between mb-2">
          <div>
            <p class="text-xs font-medium text-blue-700 mb-1">Transactions Per Second (TPS)</p>
            <p class="text-3xl font-bold text-blue-900">{{ metrics.tps?.current || 0 }}</p>
          </div>
          <div class="w-12 h-12 bg-blue-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
          </div>
        </div>
        <div class="grid grid-cols-3 gap-2 text-xs mt-3">
          <div>
            <p class="text-blue-600">Avg</p>
            <p class="font-bold text-blue-900">{{ metrics.tps?.average || 0 }}</p>
          </div>
          <div>
            <p class="text-blue-600">Max</p>
            <p class="font-bold text-blue-900">{{ metrics.tps?.max || 0 }}</p>
          </div>
          <div>
            <p class="text-blue-600">Min</p>
            <p class="font-bold text-blue-900">{{ metrics.tps?.min || 0 }}</p>
          </div>
        </div>
      </div>

      <!-- OPS (Operations Per Second) - Redis -->
      <div class="p-4 bg-gradient-to-r from-purple-50 to-purple-100 rounded-xl border border-purple-200">
        <div class="flex items-center justify-between mb-2">
          <div>
            <p class="text-xs font-medium text-purple-700 mb-1">Cache Operations Per Second (OPS)</p>
            <p class="text-3xl font-bold text-purple-900">{{ metrics.ops?.current || 0 }}</p>
          </div>
          <div class="w-12 h-12 bg-purple-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
          </div>
        </div>
        <div class="mt-3 text-xs text-purple-600">
          <p>Redis cache operations throughput</p>
        </div>
      </div>

      <!-- Database Performance -->
      <div class="p-4 bg-gradient-to-r from-green-50 to-emerald-100 rounded-xl border border-green-200">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-green-700">Database Performance</h3>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div class="p-3 bg-white rounded-lg">
            <p class="text-xs text-green-600 mb-1">Active Connections</p>
            <p class="text-2xl font-bold text-green-900">{{ metrics.database?.active_connections || 0 }}</p>
          </div>
          <div class="p-3 bg-white rounded-lg">
            <p class="text-xs text-green-600 mb-1">Slow Queries</p>
            <p class="text-2xl font-bold text-green-900">{{ metrics.database?.slow_queries || 0 }}</p>
          </div>
        </div>
        <div class="mt-3 text-xs text-green-600">
          <p>Total Queries: {{ formatNumber(metrics.database?.total_queries || 0) }}</p>
        </div>
      </div>

      <!-- Response Time -->
      <div class="p-4 bg-gradient-to-r from-orange-50 to-orange-100 rounded-xl border border-orange-200">
        <div class="flex items-center justify-between mb-2">
          <div>
            <p class="text-xs font-medium text-orange-700 mb-1">Average Response Time</p>
            <p class="text-3xl font-bold text-orange-900">{{ metrics.responseTime?.average || 0 }}ms</p>
          </div>
          <div class="w-12 h-12 bg-orange-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <div class="grid grid-cols-2 gap-2 text-xs mt-3">
          <div>
            <p class="text-orange-600">P95</p>
            <p class="font-bold text-orange-900">{{ metrics.responseTime?.p95 || 0 }}ms</p>
          </div>
          <div>
            <p class="text-orange-600">P99</p>
            <p class="font-bold text-orange-900">{{ metrics.responseTime?.p99 || 0 }}ms</p>
          </div>
        </div>
      </div>

      <!-- System Load -->
      <div class="p-4 bg-gradient-to-r from-pink-50 to-pink-100 rounded-xl border border-pink-200">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-pink-700">System Load</h3>
        </div>
        <div class="space-y-3">
          <div>
            <div class="flex items-center justify-between text-xs mb-1">
              <span class="text-pink-600">CPU Usage</span>
              <span class="font-bold text-pink-900">{{ metrics.system?.cpu || 0 }}%</span>
            </div>
            <div class="w-full h-2 bg-pink-200 rounded-full overflow-hidden">
              <div 
                class="h-full bg-pink-500 rounded-full transition-all duration-300"
                :style="{ width: `${metrics.system?.cpu || 0}%` }"
              ></div>
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between text-xs mb-1">
              <span class="text-pink-600">Memory Usage</span>
              <span class="font-bold text-pink-900">{{ metrics.system?.memory || 0 }}%</span>
            </div>
            <div class="w-full h-2 bg-pink-200 rounded-full overflow-hidden">
              <div 
                class="h-full bg-pink-500 rounded-full transition-all duration-300"
                :style="{ width: `${metrics.system?.memory || 0}%` }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- Last Updated -->
      <div class="mt-4 text-center text-xs text-gray-500">
        Last updated: {{ formatTimestamp(metrics.timestamp) }}
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
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
const metrics = ref({
  tps: { current: 0, average: 0, max: 0, min: 0 },
  ops: { current: 0 },
  database: { active_connections: 0, slow_queries: 0, total_queries: 0 },
  responseTime: { average: 0, p95: 0, p99: 0 },
  system: { cpu: 0, memory: 0 },
  timestamp: new Date().toISOString()
})

let refreshInterval = null

const fetchMetrics = async (showLoading = false) => {
  try {
    if (showLoading) {
      loading.value = true
    }
    
    const response = await api.get('/system/metrics')
    if (response.data) {
      // Use requestAnimationFrame for smooth updates
      requestAnimationFrame(() => {
        metrics.value = response.data
      })
    }
  } catch (error) {
    console.error('Failed to fetch performance metrics:', error)
    // Keep existing data on error
  } finally {
    if (showLoading) {
      loading.value = false
    }
  }
}

const refreshMetrics = () => {
  fetchMetrics()
}

const formatNumber = (num) => {
  return new Intl.NumberFormat().format(num)
}

const formatTimestamp = (timestamp) => {
  if (!timestamp) return 'Never'
  const date = new Date(timestamp)
  return date.toLocaleTimeString()
}

onMounted(() => {
  fetchMetrics(true) // Show loading on initial load
  // Refresh every 10 seconds in background (reduced from 5s to prevent jank)
  refreshInterval = setInterval(() => fetchMetrics(false), 10000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
