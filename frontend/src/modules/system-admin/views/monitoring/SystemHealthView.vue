<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">System Health</h1>
        <p class="text-sm text-gray-500 mt-1">Real-time health monitoring of platform infrastructure</p>
      </div>
      <button
        @click="fetchAll"
        :disabled="loading"
        class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50"
      >
        <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
        Refresh
      </button>
    </div>

    <div v-if="loading && !health" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading health data...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Overall Status -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <div class="flex items-center gap-3">
          <div
            class="w-4 h-4 rounded-full"
            :class="overallStatus === 'healthy' ? 'bg-green-500' : overallStatus === 'degraded' ? 'bg-yellow-500' : 'bg-red-500'"
          ></div>
          <span class="text-lg font-semibold capitalize" :class="overallStatus === 'healthy' ? 'text-green-700' : overallStatus === 'degraded' ? 'text-yellow-700' : 'text-red-700'">
            System {{ overallStatus }}
          </span>
          <span v-if="lastUpdated" class="text-xs text-gray-400 ml-auto">Updated: {{ formatTime(lastUpdated) }}</span>
        </div>
      </div>

      <!-- Health Cards -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <!-- Database -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900">Database</h3>
            <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass(database.status)">{{ database.status || 'unknown' }}</span>
          </div>
          <div class="space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><span>Connections</span><span class="font-mono">{{ database.connections ?? '-' }} / {{ database.max_connections ?? '-' }}</span></div>
            <div class="flex justify-between"><span>Response Time</span><span class="font-mono">{{ database.response_time ?? '-' }}ms</span></div>
          </div>
        </div>

        <!-- Redis -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900">Redis Cache</h3>
            <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass(cache.status)">{{ cache.status || 'unknown' }}</span>
          </div>
          <div class="space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><span>Hit Rate</span><span class="font-mono">{{ cache.hit_rate ?? '-' }}%</span></div>
            <div class="flex justify-between"><span>Memory Used</span><span class="font-mono">{{ cache.memory_used ?? '-' }}</span></div>
          </div>
        </div>

        <!-- Performance -->
        <div class="bg-white rounded-xl border border-gray-200 p-5">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-semibold text-gray-900">Performance</h3>
            <span class="text-xs px-2 py-0.5 rounded-full" :class="statusClass(performance.status)">{{ performance.status || 'unknown' }}</span>
          </div>
          <div class="space-y-2 text-sm text-gray-600">
            <div class="flex justify-between"><span>Avg Response</span><span class="font-mono">{{ performance.avg_response_time ?? '-' }}ms</span></div>
            <div class="flex justify-between"><span>CPU</span><span class="font-mono">{{ performance.cpu ?? '-' }}%</span></div>
            <div class="flex justify-between"><span>Memory</span><span class="font-mono">{{ performance.memory ?? '-' }}%</span></div>
          </div>
        </div>
      </div>

      <!-- Raw Health Data -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Raw Health Data</h2>
        <pre class="text-xs bg-gray-50 p-4 rounded-lg overflow-auto max-h-96 text-gray-700">{{ JSON.stringify(health, null, 2) }}</pre>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { RefreshCw } from 'lucide-vue-next'

const health = ref(null)
const database = ref({})
const cache = ref({})
const performance = ref({})
const loading = ref(true)
const error = ref(null)
const lastUpdated = ref(null)

const overallStatus = computed(() => {
  if (!health.value) return 'unknown'
  const statuses = [database.value.status, cache.value.status, performance.value.status].filter(Boolean)
  if (statuses.every(s => s === 'healthy' || s === 'ok')) return 'healthy'
  if (statuses.some(s => s === 'critical' || s === 'down')) return 'critical'
  return 'degraded'
})

const statusClass = (status) => {
  if (status === 'healthy' || status === 'ok') return 'bg-green-100 text-green-700'
  if (status === 'degraded' || status === 'warning') return 'bg-yellow-100 text-yellow-700'
  return 'bg-red-100 text-red-700'
}

const formatTime = (d) => d ? new Date(d).toLocaleTimeString() : ''

const fetchAll = async () => {
  try {
    loading.value = true
    error.value = null
    const [statusRes, dbRes, perfRes, cacheRes] = await Promise.allSettled([
      axios.get('/system/health/status'),
      axios.get('/system/health/database'),
      axios.get('/system/health/performance'),
      axios.get('/system/health/cache'),
    ])
    health.value = statusRes.status === 'fulfilled' ? statusRes.value.data : null
    database.value = dbRes.status === 'fulfilled' ? (dbRes.value.data.data || dbRes.value.data) : {}
    performance.value = perfRes.status === 'fulfilled' ? (perfRes.value.data.data || perfRes.value.data) : {}
    cache.value = cacheRes.status === 'fulfilled' ? (cacheRes.value.data.data || cacheRes.value.data) : {}
    lastUpdated.value = new Date().toISOString()
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load health data'
  } finally {
    loading.value = false
  }
}

let interval = null
onMounted(() => {
  fetchAll()
  interval = setInterval(() => fetchAll(), 30000)
})
onUnmounted(() => { if (interval) clearInterval(interval) })
</script>
