<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Metrics & Queues</h1>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">System performance metrics and queue processing statistics</p>
      </div>
      <div class="flex gap-2">
        <button @click="retryFailed" :disabled="retrying" class="inline-flex items-center gap-1.5 sm:gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-yellow-100 text-yellow-700 text-xs sm:text-sm font-medium rounded-lg hover:bg-yellow-200 transition-colors disabled:opacity-50">
          <RotateCcw class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
          <span class="hidden sm:inline">{{ retrying ? 'Retrying...' : 'Retry Failed Jobs' }}</span>
          <span class="sm:hidden">{{ retrying ? '...' : 'Retry' }}</span>
        </button>
        <button @click="fetchAll" :disabled="loading" class="inline-flex items-center gap-1.5 sm:gap-2 px-3 py-1.5 sm:px-4 sm:py-2 bg-gray-100 text-gray-700 text-xs sm:text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50">
          <RefreshCw class="w-3.5 h-3.5 sm:w-4 sm:h-4" :class="loading ? 'animate-spin' : ''" />
          Refresh
        </button>
      </div>
    </div>

    <div v-if="loading && !metrics" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading metrics...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Queue Statistics Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden overflow-x-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900">Queue Statistics</h2>
        </div>
        <table class="w-full min-w-[400px]">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Metric</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Count</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Pending Jobs</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ queueStats.pending ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.pending ?? 0) > 100 ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700'">{{ (queueStats.pending ?? 0) > 100 ? 'High' : 'Normal' }}</span></td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Processing</td>
              <td class="px-6 py-4 text-sm font-semibold text-green-600 text-right font-mono">{{ queueStats.processing ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span></td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Completed</td>
              <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right font-mono">{{ queueStats.completed ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Done</span></td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Failed Jobs</td>
              <td class="px-6 py-4 text-sm font-semibold text-right font-mono" :class="(queueStats.failed ?? 0) > 0 ? 'text-red-600' : 'text-gray-900'">{{ queueStats.failed ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.failed ?? 0) > 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'">{{ (queueStats.failed ?? 0) > 0 ? 'Attention' : 'Clear' }}</span></td>
            </tr>
            <tr class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">Active Workers</td>
              <td class="px-6 py-4 text-sm font-semibold text-purple-600 text-right font-mono">{{ queueStats.workers ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.workers ?? 0) > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">{{ (queueStats.workers ?? 0) > 0 ? 'Running' : 'Stopped' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Workers by Queue Table -->
      <div v-if="queueStats.workersByQueue && Object.keys(queueStats.workersByQueue).length" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Workers by Queue</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Queue</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Workers</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(count, queue) in queueStats.workersByQueue" :key="queue" class="hover:bg-gray-50 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ queue }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ count }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- System Metrics Table -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">System Metrics</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 uppercase">Metric</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Value</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 uppercase">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="(value, key) in flatMetrics" :key="key" class="hover:bg-gray-50 transition-colors cursor-pointer" @click="openMetricDetail(key, value)">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ key.replace(/_/g, ' ') }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ formatMetric(value) }}</td>
              <td class="px-6 py-4 text-right">
                <button @click.stop="openMetricDetail(key, value)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button>
              </td>
            </tr>
            <tr v-if="!Object.keys(flatMetrics).length">
              <td colspan="3" class="px-6 py-8 text-center text-gray-400 text-sm">No metrics available</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Raw Data -->
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">Raw Data</h2>
          <button @click="showRawOverlay = true" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="Expand"><Eye class="w-4 h-4" /></button>
        </div>
        <div class="px-6 py-4">
          <pre class="text-xs bg-gray-50 p-4 rounded-lg overflow-auto max-h-48 text-gray-700">{{ JSON.stringify({ metrics, queueStats }, null, 2) }}</pre>
        </div>
      </div>
    </template>

    <!-- Metric Detail Overlay -->
    <SlideOverlay v-model="showMetricOverlay" :title="selectedMetricKey" subtitle="Metric details and context" icon="BarChart3" width="40%" @close="showMetricOverlay = false">
      <div class="space-y-4">
        <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
          <span class="text-sm font-medium text-gray-700">Current Value</span>
          <span class="text-2xl font-bold text-blue-700">{{ formatMetric(selectedMetricValue) }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Metric Name</span>
          <span class="text-sm font-mono text-gray-900">{{ selectedMetricRawKey }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
          <span class="text-sm font-medium text-gray-600">Type</span>
          <span class="text-sm text-gray-900">{{ typeof selectedMetricValue === 'number' ? 'Numeric' : 'String' }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showMetricOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Raw Data Overlay -->
    <SlideOverlay v-model="showRawOverlay" title="Raw Metrics Data" subtitle="Complete metrics and queue statistics" icon="FileText" width="50%" @close="showRawOverlay = false">
      <pre class="text-xs bg-gray-50 p-4 rounded-lg overflow-auto text-gray-700 whitespace-pre-wrap">{{ JSON.stringify({ metrics, queueStats }, null, 2) }}</pre>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showRawOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { RefreshCw, RotateCcw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const metrics = ref(null)
const queueStats = ref({})
const loading = ref(true)
const error = ref(null)
const retrying = ref(false)
const showMetricOverlay = ref(false)
const showRawOverlay = ref(false)
const selectedMetricKey = ref('')
const selectedMetricRawKey = ref('')
const selectedMetricValue = ref(null)

const openMetricDetail = (key, value) => {
  selectedMetricRawKey.value = key
  selectedMetricKey.value = key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase())
  selectedMetricValue.value = value
  showMetricOverlay.value = true
}

const flatMetrics = computed(() => {
  if (!metrics.value) return {}
  const flat = {}
  const walk = (obj, prefix = '') => {
    for (const [k, v] of Object.entries(obj)) {
      const key = prefix ? `${prefix}_${k}` : k
      if (v && typeof v === 'object' && !Array.isArray(v)) walk(v, key)
      else flat[key] = v
    }
  }
  walk(metrics.value)
  return flat
})

const formatMetric = (val) => {
  if (typeof val === 'number') return val.toLocaleString(undefined, { maximumFractionDigits: 2 })
  return val
}

const fetchAll = async () => {
  try {
    loading.value = true
    error.value = null
    const [metricsRes, queueRes] = await Promise.allSettled([
      axios.get('/system/metrics'),
      axios.get('/system/queue/stats'),
    ])
    metrics.value = metricsRes.status === 'fulfilled' ? (metricsRes.value.data.data || metricsRes.value.data) : null
    queueStats.value = queueRes.status === 'fulfilled' ? (queueRes.value.data.data || queueRes.value.data) : {}
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load metrics'
  } finally {
    loading.value = false
  }
}

const retryFailed = async () => {
  try {
    retrying.value = true
    await axios.post('/system/queue/retry-failed')
    await fetchAll()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to retry jobs')
  } finally {
    retrying.value = false
  }
}

let interval = null
onMounted(() => {
  fetchAll()
  interval = setInterval(() => fetchAll(), 30000)
})
onUnmounted(() => { if (interval) clearInterval(interval) })
</script>
