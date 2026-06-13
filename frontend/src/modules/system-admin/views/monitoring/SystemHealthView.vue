<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">System Health</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">Real-time health monitoring of platform infrastructure</p>
      </div>
      <div class="flex items-center gap-2 sm:gap-3">
        <span v-if="lastUpdated" class="text-xs text-gray-400 dark:text-slate-500 hidden sm:inline">Updated: {{ formatTime(lastUpdated) }}</span>
        <button @click="fetchAll" :disabled="loading" class="inline-flex items-center gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 dark:bg-slate-700 text-gray-700 dark:text-slate-300 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 dark:hover:bg-slate-600 transition-colors disabled:opacity-50">
          <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
          Refresh
        </button>
      </div>
    </div>

    <div v-if="loading && !health" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-gray-500 dark:text-slate-400">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading health data...
    </div>
    <div v-else-if="error" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Overall Status Banner -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-4">
        <div class="flex items-center gap-3">
          <div class="w-3 h-3 rounded-full" :class="overallStatus === 'healthy' ? 'bg-green-500' : overallStatus === 'degraded' ? 'bg-yellow-500' : 'bg-red-500'"></div>
          <span class="text-lg font-semibold capitalize" :class="overallStatus === 'healthy' ? 'text-green-700' : overallStatus === 'degraded' ? 'text-yellow-700' : 'text-red-700'">
            System {{ overallStatus }}
          </span>
        </div>
      </div>

      <!-- Services Health Table -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-slate-100">Service Status</h2>
        </div>
        <table class="w-full min-w-[520px]">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Service</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Status</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Key Metric</th>
              <th class="text-left px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase hidden sm:table-cell">Secondary</th>
              <th class="text-right px-3 sm:px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors cursor-pointer" @click="openServiceDetail('database')">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Database</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(database.status)">{{ database.status || 'unknown' }}</span></td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ database.connections ?? '-' }} / {{ database.max_connections ?? '-' }} conn</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ database.response_time ?? '-' }}ms</td>
              <td class="px-6 py-4 text-right"><button @click.stop="openServiceDetail('database')" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors cursor-pointer" @click="openServiceDetail('cache')">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Redis Cache</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(cache.status)">{{ cache.status || 'unknown' }}</span></td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ cache.hit_rate ?? '-' }}% hit rate</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ cache.memory_used ?? '-' }} used</td>
              <td class="px-6 py-4 text-right"><button @click.stop="openServiceDetail('cache')" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors cursor-pointer" @click="openServiceDetail('performance')">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Performance</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusClass(performance.status)">{{ performance.status || 'unknown' }}</span></td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ performance.cpu ?? '-' }}% CPU</td>
              <td class="px-6 py-4 text-sm text-gray-600 dark:text-slate-400 font-mono">{{ performance.memory ?? '-' }}% mem</td>
              <td class="px-6 py-4 text-right"><button @click.stop="openServiceDetail('performance')" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Raw Health Data -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Raw Health Data</h2>
          <button @click="showRawOverlay = true" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="Expand"><Eye class="w-4 h-4" /></button>
        </div>
        <div class="px-6 py-4">
          <pre class="text-xs bg-gray-50 dark:bg-slate-700/50 p-4 rounded-lg overflow-auto max-h-48 text-gray-700 dark:text-slate-300">{{ JSON.stringify(health, null, 2) }}</pre>
        </div>
      </div>
    </template>

    <!-- Service Detail Overlay -->
    <SlideOverlay v-model="showServiceOverlay" :title="serviceOverlayTitle" subtitle="Detailed service health information" icon="Activity" width="50%" @close="showServiceOverlay = false">
      <div v-if="selectedService" class="space-y-3">
        <div v-for="(val, key) in selectedServiceData" :key="key" class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400 capitalize">{{ String(key).replace(/_/g, ' ') }}</span>
          <span class="text-sm font-semibold text-gray-900 dark:text-slate-100">{{ val }}</span>
        </div>
        <div v-if="!Object.keys(selectedServiceData).length" class="p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg text-sm text-gray-400 dark:text-slate-500 text-center">No data available</div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showServiceOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Raw Data Overlay -->
    <SlideOverlay v-model="showRawOverlay" title="Raw Health Data" subtitle="Complete health check response" icon="FileText" width="50%" @close="showRawOverlay = false">
      <pre class="text-xs bg-gray-50 dark:bg-slate-700/50 p-4 rounded-lg overflow-auto text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ JSON.stringify(health, null, 2) }}</pre>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showRawOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { RefreshCw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useSSE } from '@/modules/common/composables/websocket/useSSE'

const health = ref(null)
const database = ref({})
const cache = ref({})
const performance = ref({})
const loading = ref(true)
const error = ref(null)
const lastUpdated = ref(null)
const showServiceOverlay = ref(false)
const showRawOverlay = ref(false)
const selectedService = ref(null)
const serviceOverlayTitle = ref('')

const selectedServiceData = computed(() => {
  if (!selectedService.value) return {}
  const svc = selectedService.value
  if (svc === 'database') return flattenObj(database.value)
  if (svc === 'cache') return flattenObj(cache.value)
  if (svc === 'performance') return flattenObj(performance.value)
  return {}
})

const flattenObj = (obj) => {
  const result = {}
  for (const [k, v] of Object.entries(obj || {})) {
    if (v && typeof v === 'object' && !Array.isArray(v)) {
      for (const [k2, v2] of Object.entries(v)) {
        result[`${k}_${k2}`] = v2
      }
    } else {
      result[k] = v
    }
  }
  return result
}

const openServiceDetail = (service) => {
  selectedService.value = service
  const titles = { database: 'Database Health', cache: 'Redis Cache Health', performance: 'Performance Metrics' }
  serviceOverlayTitle.value = titles[service] || 'Service Details'
  showServiceOverlay.value = true
}

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

onMounted(() => {
  fetchAll()
})

// SSE: receive SystemMetricsUpdated event pushed by CollectSystemMetricsJob every minute
// useSSE auto-closes on onUnmounted
const { subscribeMany } = useSSE('/system/sse', {
  channels: 'system.admin',
})

subscribeMany({
  SystemMetricsUpdated: (data) => {
    if (data.health)      health.value      = { ...(health.value || {}),      ...data.health }
    if (data.performance) performance.value = { ...(performance.value || {}), ...data.performance }
    lastUpdated.value = new Date().toISOString()
    loading.value = false
  },
})
</script>
