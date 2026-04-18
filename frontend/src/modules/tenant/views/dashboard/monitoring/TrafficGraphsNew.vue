<template>
  <DataViewContainer
    title="Traffic Monitoring"
    subtitle="Comprehensive network traffic, performance, and revenue analytics"
    color-theme="blue"
    :stats="containerStats"
    :total="usageMetrics.activeUsers"
    :loading="loading"
    @refresh="refreshAllData"
  >
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
      </svg>
    </template>

    <template #actions>
      <BaseButton @click="refreshAllData" variant="ghost" :loading="loading || refreshing">
        <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': loading || refreshing }" />
        Refresh
      </BaseButton>
      <BaseButton @click="exportData" variant="ghost">
        <Download class="w-4 h-4 mr-1" />
        Export
      </BaseButton>
    </template>

    <template #filters>
      <div class="flex items-center gap-3 flex-wrap">
        <BaseSelect v-model="filters.timeRange" class="w-36 sm:w-40">
          <option value="1h">Last Hour</option>
          <option value="6h">Last 6 Hours</option>
          <option value="24h">Last 24 Hours</option>
          <option value="7d">Last 7 Days</option>
        </BaseSelect>
        <BaseSelect v-model="filters.router" class="w-40">
          <option value="">All Routers</option>
          <option v-for="r in routerOptions" :key="r.id" :value="r.id">{{ r.name }}</option>
        </BaseSelect>
      </div>
    </template>

    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="refreshAllData" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <DataSkeleton v-else-if="loading && !trafficData.length" :count="5" />

    <div v-else class="space-y-6">
        <!-- KPI Cards Grid -->
        <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
          <!-- Traffic Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">Current Traffic</span>
              <Activity class="w-4 h-4 text-blue-500" />
            </div>
            <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatSpeed(computedStats.current) }}</div>
            <div class="flex items-center gap-2 mt-1 text-xs">
              <span class="text-green-600">↓ {{ formatSpeed(computedStats.download) }}</span>
              <span class="text-purple-600">↑ {{ formatSpeed(computedStats.upload) }}</span>
            </div>
          </div>

          <!-- Active Users Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">Active Users</span>
              <Users class="w-4 h-4 text-emerald-500" />
            </div>
            <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ usageMetrics.activeUsers.toLocaleString() }}</div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Peak: {{ usageMetrics.peakConcurrent.toLocaleString() }}</div>
          </div>

          <!-- Total Data Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">Total Data</span>
              <Database class="w-4 h-4 text-amber-500" />
            </div>
            <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">{{ formatBytes(usageMetrics.totalDataConsumed) }}</div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">Across {{ usageMetrics.totalSessions }} sessions</div>
          </div>

          <!-- Revenue Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">Revenue</span>
              <DollarSign class="w-4 h-4 text-purple-500" />
            </div>
            <div class="text-2xl font-bold text-slate-900 dark:text-slate-100">₱{{ (revenueMetrics.totalRevenue / 1000).toFixed(1) }}k</div>
            <div class="text-xs text-slate-500 dark:text-slate-400 mt-1">₱{{ revenueMetrics.revenuePerUser.toFixed(0) }}/user</div>
          </div>

          <!-- Performance Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">Latency</span>
              <Gauge class="w-4 h-4 text-cyan-500" />
            </div>
            <div class="text-2xl font-bold" :class="performanceMetrics.latency > 100 ? 'text-red-600' : 'text-slate-900'">
              {{ performanceMetrics.latency.toFixed(0) }}ms
            </div>
            <div class="text-xs mt-1" :class="performanceMetrics.packetLoss > 1 ? 'text-red-500' : 'text-slate-500'">
              Loss: {{ performanceMetrics.packetLoss.toFixed(2) }}%
            </div>
          </div>

          <!-- System Health Card -->
          <div class="bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-4">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs text-slate-500 uppercase tracking-wider">System Health</span>
              <Server class="w-4 h-4 text-indigo-500" />
            </div>
            <div class="text-2xl font-bold" :class="systemHealth.uptime < 99 ? 'text-red-600' : 'text-slate-900'">
              {{ systemHealth.uptime.toFixed(1) }}%
            </div>
            <div class="text-xs mt-1" :class="systemHealth.offlineRouters > 0 ? 'text-red-500' : 'text-slate-500'">
              {{ systemHealth.offlineRouters }} offline routers
            </div>
          </div>
        </div>

        <!-- Traffic Chart -->
        <BaseCard>
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Real-time Traffic</h3>
            <div class="h-64 bg-slate-50 rounded-lg flex items-end justify-between p-4 gap-1">
              <div v-for="(point, i) in trafficData" :key="i" class="flex-1 flex flex-col items-center justify-end gap-1">
                <div class="w-full bg-green-500 rounded-t transition-all" :style="{ height: (point.download / maxTraffic * 100) + '%' }"></div>
                <div class="w-full bg-purple-500 rounded-t transition-all" :style="{ height: (point.upload / maxTraffic * 100) + '%' }"></div>
              </div>
            </div>
            <div class="flex items-center justify-center gap-6 mt-4">
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-green-500 rounded"></div>
                <span class="text-sm text-slate-600 dark:text-slate-400">Download</span>
              </div>
              <div class="flex items-center gap-2">
                <div class="w-4 h-4 bg-purple-500 rounded"></div>
                <span class="text-sm text-slate-600 dark:text-slate-400">Upload</span>
              </div>
            </div>
          </div>
        </BaseCard>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Top Consumers</h3>
              <div class="space-y-3">
                <div v-for="user in topConsumers" :key="user.id" class="flex items-center justify-between">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-xs font-semibold">
                      {{ user.username.slice(0, 2).toUpperCase() }}
                    </div>
                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.username }}</span>
                  </div>
                  <span class="text-sm font-bold text-blue-600">{{ formatBytes(user.bandwidth) }}/s</span>
                </div>
              </div>
            </div>
          </BaseCard>

          <BaseCard>
            <div class="p-6">
              <h3 class="text-lg font-semibold text-slate-900 mb-4">Router Distribution</h3>
              <div class="space-y-4">
                <div v-for="router in displayedRouters" :key="router.id">
                  <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-slate-700 dark:text-slate-300">{{ router.name }}</span>
                    <span class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ formatBytes(router.traffic) }}/s</span>
                  </div>
                  <div class="w-full bg-slate-200 rounded-full h-2">
                    <div class="h-2 rounded-full bg-gradient-to-r from-blue-500 to-indigo-500 transition-all" :style="{ width: router.percentage + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>
          </BaseCard>
        </div>
      </div>
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import {
  RefreshCw, Download, Activity, Users, Database, DollarSign,
  Gauge, Server
} from 'lucide-vue-next'
import { useTrafficMonitoring } from '@/modules/tenant/composables/useTrafficMonitoring'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'

// Use the traffic monitoring composable for comprehensive KPIs
const {
  loading,
  error,
  trafficData: monitoringTrafficData,
  usageMetrics,
  performanceMetrics,
  systemHealth,
  revenueMetrics,
  capacityMetrics,
  userBehavior,
  alerts,
  alertThresholds,
  stats,
  alertSummary,
  routers,
  routerTraffic,
  fetchAllMetrics,
  fetchRouters,
  fetchRouterTraffic,
  acknowledgeAlert,
  updateThresholds,
  formatBytes: formatBytesUtil,
  formatSpeed: formatSpeedUtil,
  formatDuration,
  formatPercentage,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useTrafficMonitoring()

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Monitoring', to: '/dashboard/monitoring' },
  { label: 'Traffic Monitoring' }
]

const refreshing = ref(false)
const filters = ref({ timeRange: '1h', router: '' })

// Local stats for traffic chart (from Prometheus/VictoriaMetrics)
const localStats = ref({
  current: 0,
  download: 0,
  upload: 0,
  peak: 0,
})

const trafficData = ref([])
const topConsumers = ref([])

// Computed stats for header (combine local traffic + monitoring composable)
const containerStats = computed(() => [
  { color: 'bg-blue-500', value: formatSpeed(computedStats.value.current), tooltip: 'Current Traffic' },
  { color: 'bg-emerald-500', value: usageMetrics.value.activeUsers.toLocaleString(), tooltip: 'Active Users' },
  { color: 'bg-amber-500', value: formatBytes(usageMetrics.value.totalDataConsumed), tooltip: 'Total Data' },
  { color: 'bg-purple-500', value: `₱${(revenueMetrics.value.totalRevenue / 1000).toFixed(1)}k`, tooltip: 'Revenue' }
])

// Combined stats (prioritize local traffic data if available)
const computedStats = computed(() => ({
  current: localStats.value.current || monitoringTrafficData.value.current || 0,
  download: localStats.value.download || monitoringTrafficData.value.download || 0,
  upload: localStats.value.upload || monitoringTrafficData.value.upload || 0,
  peak: localStats.value.peak || monitoringTrafficData.value.peak || 0,
}))

const routerOptions = computed(() => {
  const list = routers.value.map((r) => ({
    id: String(r?.id ?? ''),
    name: r?.name ?? String(r?.id ?? ''),
  }))
  return list.filter((r) => r.id !== '')
})

const displayedRouters = computed(() => {
  const rows = routerOptions.value.map((r) => {
    const t = routerTraffic.value?.[r.id] ?? 0
    return {
      id: r.id,
      name: r.name,
      traffic: t,
    }
  })

  const total = rows.reduce((sum, r) => sum + (r.traffic || 0), 0)
  return rows
    .sort((a, b) => (b.traffic || 0) - (a.traffic || 0))
    .map((r) => ({
      ...r,
      percentage: total > 0 ? Math.round((r.traffic / total) * 100) : 0,
    }))
})

const maxTraffic = computed(() => {
  if (!trafficData.value.length) return 1
  return Math.max(...trafficData.value.map(d => (d.download || 0) + (d.upload || 0)), 1)
})

// Use composable formatters if available, otherwise local
const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  return formatBytesUtil(bytes)
}

const formatSpeed = (bps) => {
  if (!bps || bps === 0) return '0 Mbps'
  return formatSpeedUtil(bps)
}

const refreshAllData = async () => {
  refreshing.value = true
  await Promise.allSettled([
    loadTraffic(),
    fetchRouters(),
    fetchAllMetrics()
  ])
  refreshing.value = false
}

const exportData = () => {
  const data = {
    traffic: { ...computedStats.value },
    usage: usageMetrics.value,
    performance: performanceMetrics.value,
    revenue: revenueMetrics.value,
    system: systemHealth.value,
    timestamp: new Date().toISOString()
  }
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `traffic-monitoring-${new Date().toISOString().slice(0, 10)}.json`
  a.click()
  URL.revokeObjectURL(url)
}

const loadTraffic = async () => {
  const range = filters.value.timeRange
  const routerId = String(filters.value.router ?? '')
  const result = await fetchRouterTraffic(routerId, range)
  if (!result) return
  trafficData.value = result.points
  if (result.currentIn !== undefined && result.currentOut !== undefined) {
    localStats.value.download = result.currentIn
    localStats.value.upload = result.currentOut
    localStats.value.current = result.currentIn + result.currentOut
    localStats.value.peak = result.points.reduce((m, p) => Math.max(m, (p.download || 0) + (p.upload || 0)), 0)
  } else {
    const currentIn = result.points.length ? result.points[result.points.length - 1].download : 0
    const currentOut = result.points.length ? result.points[result.points.length - 1].upload : 0
    localStats.value.download = currentIn
    localStats.value.upload = currentOut
    localStats.value.current = currentIn + currentOut
    localStats.value.peak = result.points.reduce((m, p) => Math.max(m, (p.download || 0) + (p.upload || 0)), 0)
  }
}

onMounted(async () => {
  await Promise.allSettled([
    fetchRouters(),
    loadTraffic(),
    fetchAllMetrics()
  ])
  setupWebSocketListeners()
})

onUnmounted(() => {
  cleanupWebSocketListeners()
})

watch(
  () => [filters.value.timeRange, filters.value.router],
  async () => {
    await loadTraffic()
  }
)
</script>
