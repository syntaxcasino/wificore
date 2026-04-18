/**
 * Traffic Monitoring Composable
 * Comprehensive network traffic, performance, and revenue monitoring
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useToast } from '@/modules/common/composables/useToast'

export function useTrafficMonitoring() {
  const loading = ref(false)
  const error = ref(null)
  const { toast } = useToast()

  // Time range filter
  const timeRange = ref('1h') // 1h, 6h, 24h, 7d, 30d

  // Core Traffic Data
  const trafficData = ref({
    current: 0,
    download: 0,
    upload: 0,
    peak: 0,
    historical: []
  })

  // Usage Metrics
  const usageMetrics = ref({
    totalDataConsumed: 0,
    activeUsers: 0,
    peakConcurrent: 0,
    totalSessions: 0,
    avgSessionDuration: 0
  })

  // Network Performance
  const performanceMetrics = ref({
    latency: 0, // ms
    packetLoss: 0, // percentage
    jitter: 0, // ms
    rssi: null // dBm
  })

  // System Health
  const systemHealth = ref({
    uptime: 100, // percentage
    onlineRouters: 0,
    offlineRouters: 0,
    totalRouters: 0,
    avgCpuUsage: 0,
    avgMemoryUsage: 0
  })

  // Revenue Metrics
  const revenueMetrics = ref({
    revenuePerGb: 0,
    revenuePerUser: 0,
    totalRevenue: 0,
    dataRevenueCorrelation: []
  })

  // Capacity & Congestion
  const capacityMetrics = ref({
    linkUtilization: 0, // percentage
    peakHourTraffic: null,
    loadDistribution: [],
    congestedLinks: []
  })

  // User Behavior
  const userBehavior = ref({
    avgSessionDuration: 0,
    reconnectRate: 0,
    newUsers: 0,
    returningUsers: 0,
    topConsumers: []
  })

  // Alerts & Thresholds
  const alerts = ref([])
  const alertThresholds = ref({
    latency: 100, // ms
    packetLoss: 1, // %
    bandwidthSaturation: 80, // %
    cpuUsage: 80, // %
    memoryUsage: 85 // %
  })

  // Router list and traffic data for TrafficGraphsNew
  const routers = ref([])
  const rawTrafficPoints = ref([])
  const routerTraffic = ref({})

  // Computed Stats for DataViewContainer
  const stats = computed(() => [
    { color: 'bg-blue-500', value: formatSpeed(trafficData.value.current), tooltip: 'Current Bandwidth' },
    { color: 'bg-emerald-500', value: usageMetrics.value.activeUsers.toLocaleString(), tooltip: 'Active Users' },
    { color: 'bg-amber-500', value: formatBytes(usageMetrics.value.totalDataConsumed), tooltip: 'Total Data' },
    { color: 'bg-purple-500', value: `₱${(revenueMetrics.value.totalRevenue / 1000).toFixed(1)}k`, tooltip: 'Revenue' }
  ])

  // Computed Alerts Count by Severity
  const alertSummary = computed(() => {
    const critical = alerts.value.filter(a => a.severity === 'critical').length
    const warning = alerts.value.filter(a => a.severity === 'warning').length
    const info = alerts.value.filter(a => a.severity === 'info').length
    return { critical, warning, info, total: alerts.value.length }
  })

  // API Functions
  const fetchTrafficOverview = async () => {
    try {
      const response = await axios.get('/monitoring/traffic/overview', {
        params: { timeRange: timeRange.value }
      })
      if (response.data?.success) {
        const data = response.data?.data || {}
        trafficData.value = {
          current: data.current || 0,
          download: data.download || 0,
          upload: data.upload || 0,
          peak: data.peak || 0,
          historical: data.historical || []
        }
        usageMetrics.value = {
          totalDataConsumed: data.totalDataConsumed || 0,
          activeUsers: data.activeUsers || 0,
          peakConcurrent: data.peakConcurrent || 0,
          totalSessions: data.totalSessions || 0,
          avgSessionDuration: data.avgSessionDuration || 0
        }
      }
      return trafficData.value
    } catch (err) {
      error.value = err?.response?.data?.message || 'Failed to load traffic overview'
      console.error('Traffic overview error:', err)
      throw err
    }
  }

  const fetchPerformanceMetrics = async () => {
    try {
      const response = await axios.get('/monitoring/network/performance')
      if (response.data?.success) {
        const data = response.data?.data || {}
        performanceMetrics.value = {
          latency: data.latency || 0,
          packetLoss: data.packetLoss || 0,
          jitter: data.jitter || 0,
          rssi: data.rssi || null
        }
      }
      return performanceMetrics.value
    } catch (err) {
      console.warn('Performance metrics error:', err)
      return performanceMetrics.value
    }
  }

  const fetchSystemHealth = async () => {
    try {
      const response = await axios.get('/monitoring/system/health')
      if (response.data?.success) {
        const data = response.data?.data || {}
        systemHealth.value = {
          uptime: data.uptime || 100,
          onlineRouters: data.onlineRouters || 0,
          offlineRouters: data.offlineRouters || 0,
          totalRouters: data.totalRouters || 0,
          avgCpuUsage: data.avgCpuUsage || 0,
          avgMemoryUsage: data.avgMemoryUsage || 0
        }
      }
      return systemHealth.value
    } catch (err) {
      console.warn('System health error:', err)
      return systemHealth.value
    }
  }

  const fetchRevenueMetrics = async () => {
    try {
      const response = await axios.get('/monitoring/revenue/metrics', {
        params: { timeRange: timeRange.value }
      })
      if (response.data?.success) {
        const data = response.data?.data || {}
        revenueMetrics.value = {
          revenuePerGb: data.revenuePerGb || 0,
          revenuePerUser: data.revenuePerUser || 0,
          totalRevenue: data.totalRevenue || 0,
          dataRevenueCorrelation: data.dataRevenueCorrelation || []
        }
      }
      return revenueMetrics.value
    } catch (err) {
      console.warn('Revenue metrics error:', err)
      return revenueMetrics.value
    }
  }

  const fetchCapacityMetrics = async () => {
    try {
      const response = await axios.get('/monitoring/capacity/status')
      if (response.data?.success) {
        const data = response.data?.data || {}
        capacityMetrics.value = {
          linkUtilization: data.linkUtilization || 0,
          peakHourTraffic: data.peakHourTraffic || null,
          loadDistribution: data.loadDistribution || [],
          congestedLinks: data.congestedLinks || []
        }
      }
      return capacityMetrics.value
    } catch (err) {
      console.warn('Capacity metrics error:', err)
      return capacityMetrics.value
    }
  }

  const fetchUserBehavior = async () => {
    try {
      const response = await axios.get('/monitoring/users/behavior', {
        params: { timeRange: timeRange.value }
      })
      if (response.data?.success) {
        const data = response.data?.data || {}
        userBehavior.value = {
          avgSessionDuration: data.avgSessionDuration || 0,
          reconnectRate: data.reconnectRate || 0,
          newUsers: data.newUsers || 0,
          returningUsers: data.returningUsers || 0,
          topConsumers: data.topConsumers || []
        }
      }
      return userBehavior.value
    } catch (err) {
      console.warn('User behavior error:', err)
      return userBehavior.value
    }
  }

  const fetchAlerts = async () => {
    try {
      const response = await axios.get('/monitoring/alerts/active')
      if (response.data?.success) {
        alerts.value = response.data.data || []
      }
      return alerts.value
    } catch (err) {
      console.warn('Alerts fetch error:', err)
      alerts.value = []
      return alerts.value
    }
  }

  // Combined fetch all metrics
  const fetchAllMetrics = async () => {
    loading.value = true
    error.value = null
    try {
      await Promise.allSettled([
        fetchTrafficOverview(),
        fetchPerformanceMetrics(),
        fetchSystemHealth(),
        fetchRevenueMetrics(),
        fetchCapacityMetrics(),
        fetchUserBehavior(),
        fetchAlerts()
      ])
      toast.success('Traffic monitoring data refreshed')
    } catch (err) {
      error.value = 'Failed to load some metrics'
      console.error('Fetch all metrics error:', err)
    } finally {
      loading.value = false
    }
  }

  // Acknowledge alert
  const acknowledgeAlert = async (alertId) => {
    try {
      await axios.post(`/monitoring/alerts/${alertId}/acknowledge`)
      alerts.value = alerts.value.filter(a => a.id !== alertId)
      toast.success('Alert acknowledged')
      return true
    } catch (err) {
      toast.error('Failed to acknowledge alert')
      return false
    }
  }

  // Update alert thresholds
  const updateThresholds = async (newThresholds) => {
    try {
      await axios.put('/monitoring/alerts/thresholds', newThresholds)
      alertThresholds.value = { ...alertThresholds.value, ...newThresholds }
      toast.success('Alert thresholds updated')
      return true
    } catch (err) {
      toast.error('Failed to update thresholds')
      return false
    }
  }

  // Helper functions
  const formatBytes = (bytes) => {
    if (!bytes || bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    let i = 0
    let size = bytes
    while (size >= k && i < sizes.length - 1) {
      size /= k
      i++
    }
    return `${Math.round(size * 100) / 100} ${sizes[i]}`
  }

  const formatSpeed = (bps) => {
    if (!bps || bps === 0) return '0 Mbps'
    if (bps >= 1000000000) return `${(bps / 1000000000).toFixed(2)} Gbps`
    if (bps >= 1000000) return `${(bps / 1000000).toFixed(1)} Mbps`
    if (bps >= 1000) return `${(bps / 1000).toFixed(0)} Kbps`
    return `${bps} bps`
  }

  const formatDuration = (minutes) => {
    if (!minutes) return '0m'
    const hours = Math.floor(minutes / 60)
    const mins = Math.floor(minutes % 60)
    if (hours > 0) return `${hours}h ${mins}m`
    return `${mins}m`
  }

  const formatPercentage = (value) => {
    return `${(value || 0).toFixed(1)}%`
  }

  // Real-time updates via WebSocket
  const handleTrafficUpdate = (event) => {
    const data = event.detail?.data
    if (!data) return
    trafficData.value.current = data.current || trafficData.value.current
    trafficData.value.download = data.download || trafficData.value.download
    trafficData.value.upload = data.upload || trafficData.value.upload
    usageMetrics.value.activeUsers = data.activeUsers || usageMetrics.value.activeUsers
  }

  const handleAlertReceived = (event) => {
    const alert = event.detail?.alert
    if (!alert) return
    alerts.value.unshift(alert)
    if (alert.severity === 'critical') {
      toast.error(`Critical: ${alert.message}`)
    } else if (alert.severity === 'warning') {
      toast.warning(alert.message)
    }
  }

  const setupWebSocketListeners = () => {
    window.addEventListener('traffic-update', handleTrafficUpdate)
    window.addEventListener('alert-received', handleAlertReceived)
  }

  const cleanupWebSocketListeners = () => {
    window.removeEventListener('traffic-update', handleTrafficUpdate)
    window.removeEventListener('alert-received', handleAlertReceived)
  }

  // Watch time range changes
  const setTimeRange = (range) => {
    timeRange.value = range
    fetchAllMetrics()
  }

  // Router list for dropdown filters
  const fetchRouters = async () => {
    try {
      const response = await axios.get('/routers')
      const fetched = Array.isArray(response.data) ? response.data : (response.data?.data || [])
      routers.value = Array.isArray(fetched) ? fetched : []
    } catch (err) {
      console.warn('Failed to load routers:', err.message)
      routers.value = []
    }
  }

  // Router-specific traffic metrics from VictoriaMetrics/Prometheus
  const fetchRouterTraffic = async (routerId = '', range = '1h') => {
    const url = routerId ? `/routers/${routerId}/metrics/traffic` : '/routers/metrics/traffic'
    try {
      const response = await axios.get(url, { params: { range, step: '30s' } })
      const data = response.data || {}
      if (!data?.success) return null

      const parseVmSeries = (series) => {
        if (!series || !Array.isArray(series.values)) return []
        return series.values.map((v, i) => ({
          t: series.timestamps?.[i] || i,
          v: typeof v === 'number' ? v : 0
        }))
      }

      if (routerId) {
        const inSeries = parseVmSeries(data.in)
        const outSeries = parseVmSeries(data.out)
        const maxLen = Math.max(inSeries.length, outSeries.length)
        const points = []
        for (let i = 0; i < maxLen; i++) {
          points.push({ download: inSeries[i]?.v ?? 0, upload: outSeries[i]?.v ?? 0 })
        }
        rawTrafficPoints.value = points.slice(-60)
        const currentIn = inSeries.length ? inSeries[inSeries.length - 1].v : 0
        const currentOut = outSeries.length ? outSeries[outSeries.length - 1].v : 0
        routerTraffic.value = { [routerId]: currentIn + currentOut }
        return { points: rawTrafficPoints.value, currentIn, currentOut }
      }

      const totalIn = parseVmSeries(data.total_in)
      const totalOut = parseVmSeries(data.total_out)
      const maxLen = Math.max(totalIn.length, totalOut.length)
      const points = []
      for (let i = 0; i < maxLen; i++) {
        points.push({ download: totalIn[i]?.v ?? 0, upload: totalOut[i]?.v ?? 0 })
      }
      rawTrafficPoints.value = points.slice(-60)
      return { points: rawTrafficPoints.value }
    } catch (err) {
      console.warn('Failed to load router traffic:', err.message)
      return null
    }
  }

  return {
    // Reactive data
    loading,
    error,
    timeRange,
    trafficData,
    routers,
    rawTrafficPoints,
    routerTraffic,
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

    // API functions
    fetchTrafficOverview,
    fetchPerformanceMetrics,
    fetchSystemHealth,
    fetchRevenueMetrics,
    fetchCapacityMetrics,
    fetchUserBehavior,
    fetchAlerts,
    fetchAllMetrics,
    fetchRouters,
    fetchRouterTraffic,
    acknowledgeAlert,
    updateThresholds,

    // Utility functions
    formatBytes,
    formatSpeed,
    formatDuration,
    formatPercentage,

    // WebSocket
    setupWebSocketListeners,
    cleanupWebSocketListeners,

    // Actions
    setTimeRange
  }
}
