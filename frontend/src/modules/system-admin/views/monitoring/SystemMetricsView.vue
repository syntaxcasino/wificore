<template>
  <div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
      <div>
        <h1 class="text-xl sm:text-2xl font-bold text-gray-900 dark:text-slate-100">Metrics & Queues</h1>
        <p class="text-xs sm:text-sm text-gray-500 dark:text-slate-400 mt-1">System performance metrics and queue processing statistics</p>
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

    <div
      v-if="callbackGuardTrendAlert.visible"
      class="rounded-xl border px-4 py-3"
      :class="callbackGuardTrendAlert.level === 'critical'
        ? 'bg-red-50 border-red-200 text-red-800 dark:bg-red-900/20 dark:border-red-800/40 dark:text-red-200'
        : 'bg-amber-50 border-amber-200 text-amber-800 dark:bg-amber-900/20 dark:border-amber-800/40 dark:text-amber-200'"
    >
      <div class="flex items-start justify-between gap-3">
        <div>
          <p class="text-sm font-semibold">
            Provisioning callback guard {{ callbackGuardTrendAlert.level === 'critical' ? 'critical' : 'warning' }} trend detected
          </p>
          <p class="text-xs mt-1">
            Last 10 minutes: {{ callbackGuard.last_10m_total_delta || 0 }} outcomes
            (warn ≥ {{ callbackGuardDeltaWarnThreshold }}, critical ≥ {{ callbackGuardDeltaCriticalThreshold }}).
          </p>
        </div>
      </div>
    </div>

    <div v-if="loading && !metrics" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading metrics...
    </div>
    <div v-else-if="error" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Queue Statistics Table -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden overflow-x-auto">
        <div class="px-4 sm:px-6 py-3 sm:py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-base sm:text-lg font-semibold text-gray-900 dark:text-slate-100">Queue Statistics</h2>
        </div>
        <table class="w-full min-w-[400px]">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Metric</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Count</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Pending Jobs</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ queueStats.pending ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.pending ?? 0) > 100 ? 'bg-yellow-100 text-yellow-700' : 'bg-blue-100 text-blue-700'">{{ (queueStats.pending ?? 0) > 100 ? 'High' : 'Normal' }}</span></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Processing</td>
              <td class="px-6 py-4 text-sm font-semibold text-green-600 text-right font-mono">{{ queueStats.processing ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700">Active</span></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Completed</td>
              <td class="px-6 py-4 text-sm font-semibold text-gray-900 text-right font-mono">{{ queueStats.completed ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700">Done</span></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Failed Jobs</td>
              <td class="px-6 py-4 text-sm font-semibold text-right font-mono" :class="(queueStats.failed ?? 0) > 0 ? 'text-red-600' : 'text-gray-900'">{{ queueStats.failed ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.failed ?? 0) > 0 ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700'">{{ (queueStats.failed ?? 0) > 0 ? 'Attention' : 'Clear' }}</span></td>
            </tr>
            <tr class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">Active Workers</td>
              <td class="px-6 py-4 text-sm font-semibold text-purple-600 text-right font-mono">{{ queueStats.workers ?? 0 }}</td>
              <td class="px-6 py-4"><span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="(queueStats.workers ?? 0) > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'">{{ (queueStats.workers ?? 0) > 0 ? 'Running' : 'Stopped' }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Workers by Queue Table -->
      <div v-if="queueStats.workersByQueue && Object.keys(queueStats.workersByQueue).length" class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Workers by Queue</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Queue</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Workers</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-for="(count, queue) in queueStats.workersByQueue" :key="queue" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">{{ queue }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ count }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- System Metrics Table -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">System Metrics</h2>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Metric</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Value</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Details</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-for="(value, key) in flatMetrics" :key="key" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors cursor-pointer" @click="openMetricDetail(key, value)">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 capitalize">{{ key.replace(/_/g, ' ') }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-blue-600 text-right font-mono">{{ formatMetric(value) }}</td>
              <td class="px-6 py-4 text-right">
                <button @click.stop="openMetricDetail(key, value)" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors"><Eye class="w-4 h-4" /></button>
              </td>
            </tr>
            <tr v-if="!Object.keys(flatMetrics).length">
              <td colspan="3" class="px-6 py-8 text-center text-gray-400 dark:text-slate-500 text-sm">No metrics available</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Provisioning Callback Guard -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="px-6 py-4 border-b border-gray-200 dark:border-slate-700 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Provisioning Callback Guard</h2>
            <p class="text-xs text-gray-500 dark:text-slate-400 mt-1">Rollout counters for callback validation and guard outcomes</p>
          </div>
          <div class="flex items-center gap-2">
            <button
              @click="resetCallbackGuardCounters"
              :disabled="resettingCallbackGuard"
              class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-gray-100 text-gray-700 hover:bg-gray-200 transition-colors disabled:opacity-50"
            >
              {{ resettingCallbackGuard ? 'Resetting...' : 'Reset Counters' }}
            </button>
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold" :class="callbackGuardStatusClass">
              {{ callbackGuardStatusLabel }}
            </span>
          </div>
        </div>
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-slate-700/50 border-b border-gray-200 dark:border-slate-700">
            <tr>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Outcome</th>
              <th class="text-right px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Count</th>
              <th class="text-left px-6 py-3 text-xs font-medium text-gray-500 dark:text-slate-400 uppercase">Status</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-slate-700">
            <tr v-for="item in callbackGuardRows" :key="item.key" class="hover:bg-gray-50 dark:hover:bg-slate-700/40 transition-colors">
              <td class="px-6 py-4 text-sm font-medium text-gray-900 dark:text-slate-100">{{ item.label }}</td>
              <td class="px-6 py-4 text-sm font-semibold text-right font-mono" :class="item.count > 0 ? 'text-amber-600' : 'text-gray-900 dark:text-slate-100'">
                {{ item.count }}
                <span v-if="item.delta10m > 0" class="ml-1 text-[11px] text-blue-600 dark:text-blue-300">(+{{ item.delta10m }}/10m)</span>
              </td>
              <td class="px-6 py-4">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="item.statusClass">{{ item.statusLabel }}</span>
              </td>
            </tr>
          </tbody>
        </table>
        <div class="px-6 py-3 border-t border-gray-200 dark:border-slate-700 bg-gray-50 dark:bg-slate-700/30 text-xs text-gray-600 dark:text-slate-300 flex items-center justify-between">
          <span>Total outcomes: <strong>{{ callbackGuard.total || 0 }}</strong> <span v-if="(callbackGuard.last_10m_total_delta || 0) > 0" class="text-blue-600 dark:text-blue-300">(+{{ callbackGuard.last_10m_total_delta }}/10m)</span></span>
          <span>Last updated: {{ callbackGuard.last_updated_at || 'N/A' }}</span>
        </div>
      </div>

      <!-- Raw Data -->
      <div class="bg-white dark:bg-slate-800 rounded-xl border border-gray-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-slate-700">
          <h2 class="text-lg font-semibold text-gray-900 dark:text-slate-100">Raw Data</h2>
          <button @click="showRawOverlay = true" class="p-1.5 text-blue-500 hover:bg-blue-50 rounded-md transition-colors" title="Expand"><Eye class="w-4 h-4" /></button>
        </div>
        <div class="px-6 py-4">
          <pre class="text-xs bg-gray-50 dark:bg-slate-700/50 p-4 rounded-lg overflow-auto max-h-48 text-gray-700 dark:text-slate-300">{{ JSON.stringify({ metrics, queueStats, callbackGuard }, null, 2) }}</pre>
        </div>
      </div>
    </template>

    <!-- Metric Detail Overlay -->
    <SlideOverlay v-model="showMetricOverlay" :title="selectedMetricKey" subtitle="Metric details and context" icon="BarChart3" width="50%" @close="showMetricOverlay = false">
      <div class="space-y-4">
        <div class="flex items-center justify-between p-4 bg-blue-50 rounded-lg">
          <span class="text-sm font-medium text-gray-700">Current Value</span>
          <span class="text-2xl font-bold text-blue-700">{{ formatMetric(selectedMetricValue) }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Metric Name</span>
          <span class="text-sm font-mono text-gray-900 dark:text-slate-100">{{ selectedMetricRawKey }}</span>
        </div>
        <div class="flex items-center justify-between p-3 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
          <span class="text-sm font-medium text-gray-600 dark:text-slate-400">Type</span>
          <span class="text-sm text-gray-900 dark:text-slate-100">{{ typeof selectedMetricValue === 'number' ? 'Numeric' : 'String' }}</span>
        </div>
      </div>
      <template #footer>
        <div class="flex justify-end">
          <button type="button" @click="showMetricOverlay = false" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-gray-300 dark:border-slate-600 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-600 transition-colors">Close</button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Raw Data Overlay -->
    <SlideOverlay v-model="showRawOverlay" title="Raw Metrics Data" subtitle="Complete metrics and queue statistics" icon="FileText" width="50%" @close="showRawOverlay = false">
      <pre class="text-xs bg-gray-50 dark:bg-slate-700/50 p-4 rounded-lg overflow-auto text-gray-700 dark:text-slate-300 whitespace-pre-wrap">{{ JSON.stringify({ metrics, queueStats, callbackGuard }, null, 2) }}</pre>
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
import { RefreshCw, RotateCcw, Eye } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useSSE } from '@/modules/common/composables/websocket/useSSE'

const { error: showError, warning: showWarning } = useToast()

const metrics = ref(null)
const queueStats = ref({})
const callbackGuard = ref({ counters: {}, total: 0, last_updated_at: null, last_10m_delta: {}, last_10m_total_delta: 0 })
const loading = ref(true)
const error = ref(null)
const retrying = ref(false)
const resettingCallbackGuard = ref(false)
const showMetricOverlay = ref(false)
const showRawOverlay = ref(false)
const selectedMetricKey = ref('')
const selectedMetricRawKey = ref('')
const selectedMetricValue = ref(null)

const callbackGuardDeltaWarnThreshold = Number(import.meta.env.VITE_CALLBACK_GUARD_DELTA_WARN || 5)
const callbackGuardDeltaCriticalThreshold = Number(import.meta.env.VITE_CALLBACK_GUARD_DELTA_CRITICAL || 20)

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


const getCallbackCounterStatus = (count) => {
  if (count >= 20) return { label: 'Critical', className: 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-300' }
  if (count >= 5) return { label: 'Warning', className: 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300' }
  return { label: 'Normal', className: 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-300' }
}

const callbackGuardRows = computed(() => {
  const counters = callbackGuard.value?.counters || {}
  const deltas = callbackGuard.value?.last_10m_delta || {}
  const items = [
    { key: 'identity_validation_failed', label: 'Identity validation failed', count: Number(counters.identity_validation_failed || 0), delta10m: Number(deltas.identity_validation_failed || 0) },
    { key: 'freshness_validation_failed', label: 'Freshness validation failed', count: Number(counters.freshness_validation_failed || 0), delta10m: Number(deltas.freshness_validation_failed || 0) },
    { key: 'terminal_status_mutation_ignored', label: 'Terminal status mutation ignored', count: Number(counters.terminal_status_mutation_ignored || 0), delta10m: Number(deltas.terminal_status_mutation_ignored || 0) },
    { key: 'regressive_stage_ignored', label: 'Regressive stage ignored', count: Number(counters.regressive_stage_ignored || 0), delta10m: Number(deltas.regressive_stage_ignored || 0) },
  ]

  return items.map((item) => {
    const status = getCallbackCounterStatus(item.count)
    return {
      ...item,
      statusLabel: status.label,
      statusClass: status.className,
    }
  })
})

const callbackGuardStatus = computed(() => {
  const maxCount = Math.max(0, ...callbackGuardRows.value.map((item) => item.count || 0))
  return getCallbackCounterStatus(maxCount)
})

const callbackGuardStatusLabel = computed(() => callbackGuardStatus.value.label)
const callbackGuardStatusClass = computed(() => callbackGuardStatus.value.className)

const callbackGuardTrendAlert = computed(() => {
  const delta = Number(callbackGuard.value?.last_10m_total_delta || 0)

  if (delta >= callbackGuardDeltaCriticalThreshold) {
    return { visible: true, level: 'critical' }
  }

  if (delta >= callbackGuardDeltaWarnThreshold) {
    return { visible: true, level: 'warning' }
  }

  return { visible: false, level: 'normal' }
})

const formatMetric = (val) => {
  if (typeof val === 'number') return val.toLocaleString(undefined, { maximumFractionDigits: 2 })
  return val
}

const fetchAll = async () => {
  try {
    loading.value = true
    error.value = null
    const [metricsRes, queueRes, callbackGuardRes] = await Promise.allSettled([
      axios.get('/system/metrics'),
      axios.get('/system/queue/stats'),
      axios.get('/system/metrics/provisioning/callback-guard'),
    ])
    metrics.value = metricsRes.status === 'fulfilled' ? (metricsRes.value.data.data || metricsRes.value.data) : null
    queueStats.value = queueRes.status === 'fulfilled' ? (queueRes.value.data.data || queueRes.value.data) : {}
    callbackGuard.value = callbackGuardRes.status === 'fulfilled'
      ? (callbackGuardRes.value.data.data || callbackGuardRes.value.data || { counters: {}, total: 0, last_updated_at: null, last_10m_delta: {}, last_10m_total_delta: 0 })
      : { counters: {}, total: 0, last_updated_at: null, last_10m_delta: {}, last_10m_total_delta: 0 }
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
    showError(err.response?.data?.message || 'Failed to retry jobs')
  } finally {
    retrying.value = false
  }
}


const resetCallbackGuardCounters = async () => {
  const confirmed = window.confirm('Reset provisioning callback guard counters? This clears rollout diagnostics counters.')
  if (!confirmed) return

  try {
    resettingCallbackGuard.value = true
    await axios.post('/system/metrics/provisioning/callback-guard/reset')
    await fetchAll()
  } catch (err) {
    showError(err.response?.data?.message || 'Failed to reset callback guard counters')
  } finally {
    resettingCallbackGuard.value = false
  }
}

onMounted(() => {
  fetchAll()
})

// SSE: receive SystemMetricsUpdated event pushed by CollectSystemMetricsJob every minute
// useSSE auto-closes on onUnmounted
const { subscribeMany } = useSSE('/api/system/sse', {
  channels: 'system.admin',
})

subscribeMany({
  SystemMetricsUpdated: (data) => {
    if (data.queue)       queueStats.value = { ...queueStats.value, ...data.queue }
    if (data.performance) metrics.value   = { ...(metrics.value || {}), ...data.performance }
    loading.value = false
  },
  'provisioning.callback_guard.alert': (data) => {
    fetchAll()

    const level = data?.level || 'warning'
    const totalDelta = data?.total_delta || 0
    const windowMinutes = data?.window_minutes || 10
    const message = level === 'critical'
      ? 'Critical provisioning callback guard alert: ' + totalDelta + ' outcomes in the last ' + windowMinutes + ' minute(s).'
      : 'Provisioning callback guard alert: ' + totalDelta + ' outcomes in the last ' + windowMinutes + ' minute(s).'

    if (level === 'critical') showWarning(message, 6000)
    else showWarning(message, 4000)
  },
})
</script>
