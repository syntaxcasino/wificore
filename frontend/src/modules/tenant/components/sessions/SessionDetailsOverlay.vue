<template>
  <SlideOverlay
    v-model="isOpen"
    title="Session Details"
    :subtitle="session?.user?.name || session?.username || 'Session information'"
    icon="Activity"
    width="50%"
    @close="$emit('close')"
  >
    <div v-if="session" class="space-y-4">
      <!-- User Information -->
      <div class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-lg p-4 border border-slate-200">
        <div class="flex items-center gap-2 mb-3">
          <Users class="w-4 h-4 text-slate-600" />
          <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">User Information</h4>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <div class="text-xs text-slate-500 mb-0.5">{{ session.type === 'pppoe' ? 'Username' : 'Name' }}</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.user?.name || session.username }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-0.5">Phone</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.user?.phone || session.phone || 'N/A' }}</div>
          </div>
          <div v-if="session.package">
            <div class="text-xs text-slate-500 mb-0.5">Package</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.package.name }}</div>
          </div>
          <div v-if="session.package?.speed || session.profile?.speed">
            <div class="text-xs text-slate-500 mb-0.5">Speed</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.package?.speed || session.profile?.speed }}</div>
          </div>
        </div>
      </div>

      <!-- Connection Details -->
      <div class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-lg p-4 border border-slate-200">
        <div class="flex items-center gap-2 mb-3">
          <Network class="w-4 h-4 text-slate-600" />
          <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-100">Connection Details</h4>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <div class="text-xs text-slate-500 mb-0.5">IP Address</div>
            <div class="text-sm font-medium text-slate-900 font-mono">{{ session.ip_address || session.framed_ip }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-0.5">{{ session.type === 'pppoe' ? 'Calling Station' : 'MAC Address' }}</div>
            <div class="text-sm font-medium text-slate-900 font-mono">{{ session.mac_address || session.calling_station_id }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-0.5">Session ID</div>
            <div class="text-sm font-medium text-slate-900 font-mono text-xs">{{ session.acct_session_id || session.session_id || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-0.5">NAS IP</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ session.nas_ip_address || session.nas_ip || 'N/A' }}</div>
          </div>
        </div>
      </div>

      <!-- Session Statistics -->
      <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
        <div class="flex items-center gap-2 mb-3">
          <Activity class="w-4 h-4 text-blue-600" />
          <h4 class="text-sm font-semibold text-blue-800">Session Statistics</h4>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <div class="text-xs text-blue-600 mb-0.5">Duration</div>
            <div class="text-sm font-semibold text-blue-900">{{ formatDuration(session.duration || session.session_duration) }}</div>
          </div>
          <div>
            <div class="text-xs text-blue-600 mb-0.5">Started</div>
            <div class="text-sm font-semibold text-blue-900">{{ formatDateTime(session.start_time || session.login_time) }}</div>
          </div>
          <div>
            <div class="text-xs text-green-600 mb-0.5">Download</div>
            <div class="text-sm font-semibold text-green-700">{{ formatBytes(session.bytes_in || session.input_octets) }}</div>
          </div>
          <div>
            <div class="text-xs text-blue-600 mb-0.5">Upload</div>
            <div class="text-sm font-semibold text-blue-700">{{ formatBytes(session.bytes_out || session.output_octets) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-600 mb-0.5">Total Data</div>
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ formatBytes((session.bytes_in || session.input_octets || 0) + (session.bytes_out || session.output_octets || 0)) }}</div>
          </div>
          <div v-if="session.current_bandwidth || session.download_speed">
            <div class="text-xs text-slate-600 mb-0.5">Current Speed</div>
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ formatBytes(session.current_bandwidth || session.download_speed) }}/s</div>
          </div>
        </div>
      </div>

      <!-- PPPoE Specific: Speed Breakdown -->
      <div v-if="session.type === 'pppoe' && (session.download_speed || session.upload_speed)" class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
        <div class="flex items-center gap-2 mb-3">
          <Gauge class="w-4 h-4 text-purple-600" />
          <h4 class="text-sm font-semibold text-purple-800">Current Speeds</h4>
        </div>
        <div class="space-y-3">
          <div>
            <div class="flex items-center justify-between mb-1">
              <span class="text-xs text-green-600 font-medium">Download</span>
              <span class="text-xs font-semibold text-green-700">{{ formatBytes(session.download_speed) }}/s</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-2">
              <div 
                class="bg-gradient-to-r from-green-500 to-emerald-500 h-2 rounded-full transition-all duration-300"
                :style="{ width: getSpeedPercentage(session.download_speed, session.profile?.max_download) + '%' }"
              ></div>
            </div>
          </div>
          <div>
            <div class="flex items-center justify-between mb-1">
              <span class="text-xs text-blue-600 font-medium">Upload</span>
              <span class="text-xs font-semibold text-blue-700">{{ formatBytes(session.upload_speed) }}/s</span>
            </div>
            <div class="w-full bg-slate-200 rounded-full h-2">
              <div 
                class="bg-gradient-to-r from-blue-500 to-cyan-500 h-2 rounded-full transition-all duration-300"
                :style="{ width: getSpeedPercentage(session.upload_speed, session.profile?.max_upload) + '%' }"
              ></div>
            </div>
          </div>
        </div>
      </div>

      <!-- PPPoE Traffic History Chart -->
      <div v-if="session.type === 'pppoe'" class="bg-gradient-to-br from-slate-50 to-gray-50 rounded-lg p-4 border border-slate-200">
        <div class="flex items-center justify-between mb-3">
          <div class="flex items-center gap-2">
            <TrendingUp class="w-4 h-4 text-slate-600" />
            <h4 class="text-sm font-semibold text-slate-800">Traffic History</h4>
          </div>
          <div class="flex items-center gap-2">
            <select
              v-model="selectedRange"
              class="text-xs border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
              @change="fetchChart"
            >
              <option v-for="r in ranges" :key="r.value" :value="r.value">{{ r.label }}</option>
            </select>
            <select
              v-model="selectedChartType"
              class="text-xs border-gray-300 rounded-md shadow-sm focus:border-blue-500 focus:ring-blue-500 py-1 px-2 bg-white"
            >
              <option value="line">Line</option>
              <option value="area">Area</option>
            </select>
          </div>
        </div>

        <div v-if="chartLoading" class="flex items-center justify-center h-32 text-slate-400 text-xs">
          Loading chart...
        </div>
        <div v-else-if="chartError" class="flex items-center justify-center h-32 text-slate-400 text-xs">
          {{ chartError }}
        </div>
        <div v-else-if="!hasChartData" class="flex items-center justify-center h-32">
          <div class="text-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-10 w-10 text-gray-300 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            <p class="text-gray-500 text-sm">No historical data available</p>
            <p class="text-gray-400 text-xs mt-1">Traffic metrics may not be collected</p>
          </div>
        </div>
        <div v-else>
          <!-- Chart: Y-axis + graph + X-axis (mirrors RouterDetailsModal) -->
          <div class="flex" style="height:200px">
            <!-- Y-Axis -->
            <div class="relative w-14 mr-2 border-r border-gray-100 flex-shrink-0">
              <div v-for="tick in yAxisTicks" :key="tick.label"
                class="absolute right-1 text-[10px] text-black font-medium transform translate-y-1/2"
                :style="{ bottom: tick.percent + '%' }">
                {{ tick.label }}
              </div>
            </div>
            <!-- Graph area -->
            <div class="relative flex-1 flex flex-col min-w-0">
              <div class="relative flex-1 overflow-hidden cursor-crosshair"
                @mousemove="handleHover"
                @mouseleave="handleLeave"
                ref="graphContainer">
                <!-- Grid lines -->
                <div v-for="tick in yAxisTicks" :key="'g'+tick.label"
                  class="absolute w-full border-t border-gray-100"
                  :style="{ bottom: tick.percent + '%' }" />
                <!-- SVG -->
                <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none">
                  <defs>
                    <linearGradient id="gradDL" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%" stop-color="#22c55e" stop-opacity="0.2"/>
                      <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
                    </linearGradient>
                    <linearGradient id="gradUL" x1="0" x2="0" y1="0" y2="1">
                      <stop offset="0%" stop-color="#6366f1" stop-opacity="0.2"/>
                      <stop offset="100%" stop-color="#6366f1" stop-opacity="0"/>
                    </linearGradient>
                  </defs>
                  <template v-if="selectedChartType === 'area'">
                    <path :d="svgDownloadPath" fill="url(#gradDL)" stroke="none" />
                    <path :d="svgUploadPath" fill="url(#gradUL)" stroke="none" />
                  </template>
                  <path :d="svgDownloadPath" fill="none" stroke="#22c55e" stroke-width="2" vector-effect="non-scaling-stroke" />
                  <path :d="svgUploadPath" fill="none" stroke="#6366f1" stroke-width="2" vector-effect="non-scaling-stroke" />
                </svg>
                <!-- Hover line -->
                <div v-if="hoveredIndex >= 0"
                  class="absolute top-0 bottom-0 border-l border-gray-400 border-dashed pointer-events-none z-10"
                  :style="{ left: hoverX + '%' }">
                  <div class="absolute w-2 h-2 bg-green-500 rounded-full -ml-1 border border-white"
                    :style="{ bottom: (hoveredData.dl / chartMax * 100) + '%' }"/>
                  <div class="absolute w-2 h-2 bg-indigo-500 rounded-full -ml-1 border border-white"
                    :style="{ bottom: (hoveredData.ul / chartMax * 100) + '%' }"/>
                  <div class="absolute top-0 left-2 bg-white/90 backdrop-blur-sm shadow-lg border border-gray-200 rounded p-2 text-xs whitespace-nowrap z-20 pointer-events-none"
                    :class="{ '-translate-x-full -left-2': hoverX > 75 }">
                    <div class="font-mono text-gray-500 mb-1">{{ formatTime(hoveredData.t) }}</div>
                    <div class="flex items-center gap-2 mb-0.5">
                      <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span>
                      <span class="font-medium text-gray-700">DL: {{ formatBytes(hoveredData.dl) }}/s</span>
                    </div>
                    <div class="flex items-center gap-2">
                      <span class="w-1.5 h-1.5 rounded-full bg-indigo-500"></span>
                      <span class="font-medium text-gray-700">UL: {{ formatBytes(hoveredData.ul) }}/s</span>
                    </div>
                  </div>
                </div>
              </div>
              <!-- X-Axis -->
              <div class="h-8 relative mt-1 flex-shrink-0 border-t border-gray-200 pt-1">
                <div v-for="tick in xAxisTicks" :key="tick.x"
                  class="absolute text-[11px] text-black font-medium transform -translate-x-1/2 whitespace-nowrap"
                  :style="{ left: tick.x + '%' }">
                  {{ tick.label }}
                </div>
              </div>
            </div>
          </div>
          <!-- Legend -->
          <div class="flex items-center justify-center gap-6 mt-2">
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 bg-green-500 rounded-full"></div>
              <span class="text-xs text-gray-600">Download</span>
            </div>
            <div class="flex items-center gap-2">
              <div class="w-3 h-3 bg-indigo-500 rounded-full"></div>
              <span class="text-xs text-gray-600">Upload</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Hotspot Specific: Bandwidth -->
      <div v-if="session.type === 'hotspot' && session.current_bandwidth" class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-200">
        <div class="flex items-center gap-2 mb-3">
          <Gauge class="w-4 h-4 text-cyan-600" />
          <h4 class="text-sm font-semibold text-cyan-800">Current Bandwidth</h4>
        </div>
        <div>
          <div class="flex items-center justify-between mb-1">
            <span class="text-xs text-cyan-600 font-medium">Bandwidth Usage</span>
            <span class="text-xs font-semibold text-cyan-700">{{ formatBytes(session.current_bandwidth) }}/s</span>
          </div>
          <div class="w-full bg-slate-200 rounded-full h-2">
            <div 
              class="bg-gradient-to-r from-blue-500 to-cyan-500 h-2 rounded-full transition-all duration-300"
              :style="{ width: getBandwidthPercentage(session.current_bandwidth) + '%' }"
            ></div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-700/40 transition-colors"
        >
          Close
        </button>
        <button
          @click="$emit('disconnect', session)"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-red-600 hover:bg-red-700 rounded-lg transition-colors flex items-center justify-center gap-1.5"
        >
          <Power class="w-4 h-4" />
          Disconnect
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Users, Network, Activity, Power, Gauge, TrendingUp } from 'lucide-vue-next'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import axios from '@/modules/common/services/api/axios'

const props = defineProps({
  modelValue: Boolean,
  show: Boolean,
  session: Object,
  icon: {
    type: Object,
    default: () => Activity
  }
})

const emit = defineEmits(['update:modelValue', 'close', 'disconnect'])

const isOpen = computed({
  get: () => props.modelValue ?? props.show,
  set: (val) => {
    emit('update:modelValue', val)
    if (!val) emit('close')
  }
})

// ── Chart state ──────────────────────────────────────────────
const graphContainer = ref(null)
const hoveredIndex   = ref(-1)
const hoveredData    = ref(null)

const ranges = [
  { label: 'Last 15 min',   value: '15m' },
  { label: 'Last 30 min',   value: '30m' },
  { label: 'Last 1 hour',   value: '1h'  },
  { label: 'Last 6 hours',  value: '6h'  },
  { label: 'Last 24 hours', value: '24h' },
  { label: 'Last 7 days',   value: '7d'  },
]
const selectedRange    = ref('1h')
const selectedChartType = ref('line')
const chartLoading     = ref(false)
const chartError       = ref(null)
const downloadSeries   = ref([])  // [{t, v}]
const uploadSeries     = ref([])

const hasChartData = computed(() =>
  downloadSeries.value.some(p => p.v > 0) || uploadSeries.value.some(p => p.v > 0)
)


// ── Fetch ─────────────────────────────────────────────────────
const fetchChart = async () => {
  const username = props.session?.username
  if (!username) return
  chartLoading.value = true
  chartError.value   = null
  try {
    const step = selectedRange.value === '7d'  ? '1h'
               : selectedRange.value === '24h' ? '5m'
               : selectedRange.value === '6h'  ? '2m'
               : '30s'
    const resp = await axios.get(`pppoe/metrics/user/${encodeURIComponent(username)}`, {
      params: { range: selectedRange.value, step },
    })
    const dl = resp.data?.download?.data?.result?.[0]?.values ?? []
    const ul = resp.data?.upload?.data?.result?.[0]?.values ?? []
    downloadSeries.value = dl.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
    uploadSeries.value   = ul.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
  } catch (e) {
    chartError.value = 'Could not load traffic data'
  } finally {
    chartLoading.value = false
  }
}

// Re-fetch when the overlay opens or session changes
watch(() => [props.modelValue ?? props.show, props.session?.username], ([show]) => {
  if (show && props.session?.type === 'pppoe') fetchChart()
}, { immediate: true })

// ── Chart computeds (mirror RouterDetailsModal) ───────────────
const chartMax = computed(() => {
  const all = [...downloadSeries.value.map(p => p.v), ...uploadSeries.value.map(p => p.v)]
  return Math.max(...all, 1)
})

const yAxisTicks = computed(() => {
  const max  = chartMax.value
  const ticks = 4
  return Array.from({ length: ticks + 1 }, (_, i) => ({
    value:   (max / ticks) * i,
    label:   formatBytes((max / ticks) * i) + '/s',
    percent: (i / ticks) * 100,
  }))
})

const xAxisTicks = computed(() => {
  const data = downloadSeries.value
  if (!data.length) return []
  const count   = 5
  const step    = Math.floor((data.length - 1) / (count - 1)) || 1
  const padding = 5
  const usable  = 100 - padding * 2
  const ticks   = []
  const seen    = new Set()
  let ti        = 0
  for (let i = 0; i < data.length && ticks.length < count; i += step) {
    const label = new Date(data[i].t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    if (seen.has(label) && i > 0 && i < data.length - 1) continue
    seen.add(label)
    ticks.push({ x: padding + (ti / (count - 1)) * usable, label })
    ti++
  }
  const last = data[data.length - 1]
  const lastLabel = new Date(last.t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (ticks.length && ticks[ticks.length - 1].label !== lastLabel) {
    ticks.push({ x: padding + usable, label: lastLabel })
  }
  return ticks
})

function buildSvgPath(series) {
  if (!series.length) return ''
  const max  = chartMax.value
  const minT = series[0].t
  const maxT = series[series.length - 1].t || minT + 1
  const cx   = (t) => ((t - minT) / (maxT - minT)) * 1000
  const cy   = (v) => 200 - (v / max) * 200
  return 'M' + series.map(p => `${cx(p.t).toFixed(1)},${cy(p.v).toFixed(1)}`).join('L')
}

const svgDownloadPath = computed(() => buildSvgPath(downloadSeries.value))
const svgUploadPath   = computed(() => buildSvgPath(uploadSeries.value))

const hoverX = computed(() => {
  if (hoveredIndex.value < 0 || !downloadSeries.value.length) return 0
  return (hoveredIndex.value / (downloadSeries.value.length - 1)) * 100
})

const handleHover = (event) => {
  const data = downloadSeries.value
  if (!data.length) return
  const el   = graphContainer.value
  if (!el) return
  const rect  = el.getBoundingClientRect()
  const count = data.length
  let idx = Math.round(((event.clientX - rect.left) / rect.width) * (count - 1))
  idx = Math.max(0, Math.min(idx, count - 1))
  hoveredIndex.value = idx
  hoveredData.value  = {
    t:  data[idx].t,
    dl: data[idx].v,
    ul: (uploadSeries.value[idx]?.v ?? 0),
  }
}
const handleLeave = () => {
  hoveredIndex.value = -1
  hoveredData.value  = null
}

// ── Formatters ────────────────────────────────────────────────
const formatTime = (ts) => {
  if (!ts) return ''
  return new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(Math.max(bytes, 1)) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDuration = (seconds) => {
  if (!seconds) return '0s'
  const hours   = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  const secs    = seconds % 60
  if (hours > 0)   return `${hours}h ${minutes}m ${secs}s`
  if (minutes > 0) return `${minutes}m ${secs}s`
  return `${secs}s`
}

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString()
}

const getSpeedPercentage = (current, max) => {
  if (!max) return 0
  return Math.min((current / max) * 100, 100)
}

const getBandwidthPercentage = (current) => {
  const maxBandwidth = 10485760
  return Math.min((current / maxBandwidth) * 100, 100)
}
</script>
