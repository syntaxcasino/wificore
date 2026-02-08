<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Metrics & Queues</h1>
        <p class="text-sm text-gray-500 mt-1">System performance metrics and queue processing statistics</p>
      </div>
      <div class="flex gap-2">
        <button
          @click="retryFailed"
          :disabled="retrying"
          class="inline-flex items-center gap-2 px-4 py-2 bg-yellow-100 text-yellow-700 text-sm font-medium rounded-lg hover:bg-yellow-200 transition-colors disabled:opacity-50"
        >
          <RotateCcw class="w-4 h-4" />
          {{ retrying ? 'Retrying...' : 'Retry Failed Jobs' }}
        </button>
        <button
          @click="fetchAll"
          :disabled="loading"
          class="inline-flex items-center gap-2 px-4 py-2 bg-gray-100 text-gray-700 text-sm font-medium rounded-lg hover:bg-gray-200 transition-colors disabled:opacity-50"
        >
          <RefreshCw class="w-4 h-4" :class="loading ? 'animate-spin' : ''" />
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
      <!-- Queue Stats -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Queue Statistics</h2>
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
          <div class="p-4 bg-blue-50 rounded-lg text-center">
            <div class="text-2xl font-bold text-blue-700">{{ queueStats.pending ?? 0 }}</div>
            <div class="text-xs text-blue-600 mt-1">Pending</div>
          </div>
          <div class="p-4 bg-yellow-50 rounded-lg text-center">
            <div class="text-2xl font-bold text-yellow-700">{{ queueStats.processing ?? 0 }}</div>
            <div class="text-xs text-yellow-600 mt-1">Processing</div>
          </div>
          <div class="p-4 bg-green-50 rounded-lg text-center">
            <div class="text-2xl font-bold text-green-700">{{ queueStats.completed ?? 0 }}</div>
            <div class="text-xs text-green-600 mt-1">Completed</div>
          </div>
          <div class="p-4 bg-red-50 rounded-lg text-center">
            <div class="text-2xl font-bold text-red-700">{{ queueStats.failed ?? 0 }}</div>
            <div class="text-xs text-red-600 mt-1">Failed</div>
          </div>
          <div class="p-4 bg-purple-50 rounded-lg text-center">
            <div class="text-2xl font-bold text-purple-700">{{ queueStats.workers ?? 0 }}</div>
            <div class="text-xs text-purple-600 mt-1">Workers</div>
          </div>
        </div>

        <!-- Workers by Queue -->
        <div v-if="queueStats.workersByQueue && Object.keys(queueStats.workersByQueue).length" class="mt-4">
          <h3 class="text-sm font-medium text-gray-700 mb-2">Workers by Queue</h3>
          <div class="flex flex-wrap gap-2">
            <span
              v-for="(count, queue) in queueStats.workersByQueue"
              :key="queue"
              class="inline-flex items-center gap-1 px-3 py-1 bg-gray-100 rounded-full text-xs text-gray-700"
            >
              <span class="font-medium">{{ queue }}:</span> {{ count }}
            </span>
          </div>
        </div>
      </div>

      <!-- System Metrics -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">System Metrics</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div v-for="(value, key) in flatMetrics" :key="key" class="p-4 bg-gray-50 rounded-lg">
            <div class="text-sm text-gray-500 capitalize">{{ key.replace(/_/g, ' ') }}</div>
            <div class="text-xl font-bold text-gray-900 mt-1">{{ formatMetric(value) }}</div>
          </div>
        </div>
      </div>

      <!-- Raw Metrics -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-3">Raw Data</h2>
        <pre class="text-xs bg-gray-50 p-4 rounded-lg overflow-auto max-h-96 text-gray-700">{{ JSON.stringify({ metrics, queueStats }, null, 2) }}</pre>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import { RefreshCw, RotateCcw } from 'lucide-vue-next'

const metrics = ref(null)
const queueStats = ref({})
const loading = ref(true)
const error = ref(null)
const retrying = ref(false)

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
