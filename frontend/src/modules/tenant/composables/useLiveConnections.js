/**
 * Live Connections Composable - Event-Driven with WebSocket/SSE
 * WiFi Hotspot System
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'

export function useLiveConnections() {
  const loading = ref(false)
  const error = ref(null)
  const connections = ref([])
  const routers = ref([])
  const stats = ref({
    total: 0,
    hotspot: 0,
    pppoe: 0,
    active: 0,
    idle: 0,
    download: 0,
    upload: 0,
    bandwidth: 0,
    avgSessionDuration: 0,
    peakToday: 0
  })

  // Track peak concurrent for today (persist in memory)
  let todayPeak = 0

  const { toast } = useToast()
  const authStore = useAuthStore()

  // Throttling controls
  let lastStatsFetch = 0
  const STATS_THROTTLE_MS = 5000 // 5 seconds minimum between fetches
  let statsFetchPromise = null

  // Filters
  const filters = ref({
    type: '',
    router: ''
  })

  // Computed
  const filteredConnections = computed(() => {
    let data = connections.value

    if (filters.value.type) {
      data = data.filter(c => c.type === filters.value.type)
    }

    if (filters.value.router) {
      data = data.filter(c => c.router_id === filters.value.router)
    }

    return data
  })

  const hotspotConnections = computed(() =>
    Array.isArray(connections.value) ? connections.value.filter(c => c.type === 'hotspot') : []
  )

  const pppoeConnections = computed(() =>
    Array.isArray(connections.value) ? connections.value.filter(c => c.type === 'pppoe') : []
  )

  const activeConnections = computed(() =>
    Array.isArray(connections.value) ? connections.value.filter(c => c.download_rate > 0 || c.upload_rate > 0) : []
  )

  // API Functions
  const fetchConnections = async () => {
    const isInitial = connections.value.length === 0
    if (isInitial) {
      loading.value = true
      error.value = null
    }

    try {
      const [pppoeRes, hotspotRes] = await Promise.allSettled([
        axios.get('/pppoe/sessions/live'),
        axios.get('/hotspot/sessions')
      ])

      const merged = []

      if (pppoeRes.status === 'fulfilled') {
        const pppoeData = pppoeRes.value.data?.sessions || pppoeRes.value.data?.data || []
        pppoeData.forEach((s, i) => {
          merged.push({
            id: `pppoe-${s.id || i}`,
            username: s.username || s.name || 'Unknown',
            user_name: s.caller_id || s.name || '',
            ip_address: s.address || s.ip_address || '',
            mac_address: s.caller_id || s.mac_address || '',
            type: 'pppoe',
            router_name: s.router?.name || s.router_name || 'Unknown',
            router_id: s.router?.id || s.router_id || null,
            download_rate: s.tx_byte ? Number(s.tx_byte) : (s.download_rate || 0),
            upload_rate: s.rx_byte ? Number(s.rx_byte) : (s.upload_rate || 0),
            uptime: s.uptime_seconds || s.uptime || 0,
            connected_at: s.started_at || s.created_at || new Date().toISOString(),
            service: s.service || '',
            _raw: s
          })
        })
      }

      if (hotspotRes.status === 'fulfilled') {
        const hotspotData = hotspotRes.value.data?.sessions || hotspotRes.value.data?.data || []
        hotspotData.forEach((s, i) => {
          merged.push({
            id: `hotspot-${s.id || i}`,
            username: s.username || s.user || 'Unknown',
            user_name: s.name || s.user_name || '',
            ip_address: s.address || s.ip_address || '',
            mac_address: s.mac_address || '',
            type: 'hotspot',
            router_name: s.router?.name || s.router_name || 'Unknown',
            router_id: s.router?.id || s.router_id || null,
            download_rate: s.bytes_out ? Number(s.bytes_out) : (s.download_rate || 0),
            upload_rate: s.bytes_in ? Number(s.bytes_in) : (s.upload_rate || 0),
            uptime: s.uptime_seconds || s.uptime || 0,
            connected_at: s.started_at || s.created_at || new Date().toISOString(),
            _raw: s
          })
        })
      }

      connections.value = merged
      updateStats()

      if (pppoeRes.status === 'rejected' && hotspotRes.status === 'rejected') {
        if (isInitial) {
          error.value = 'Failed to load live connections. Please check your network.'
          toast.error(error.value)
        }
      }

      return merged
    } catch (err) {
      if (isInitial) {
        error.value = err.response?.data?.message || 'Failed to load connections.'
        toast.error(error.value)
      }
      console.error('fetchConnections error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchRouters = async () => {
    try {
      const response = await axios.get('/routers')
      const data = Array.isArray(response.data) ? response.data : (response.data?.data || [])
      routers.value = data.map(r => ({ id: r.id, name: r.name }))
      return routers.value
    } catch (err) {
      console.warn('Failed to fetch routers for filter:', err.message)
      return []
    }
  }

  const disconnectUser = async (conn) => {
    try {
      if (conn.type === 'pppoe') {
        await axios.post('/pppoe/sessions/disconnect', { session_id: conn._raw?.id, username: conn.username })
      } else {
        const userId = conn._raw?.user_id || conn._raw?.id
        if (userId) {
          await axios.post(`/hotspot/users/${userId}/disconnect`)
        }
      }

      // Remove from local state
      connections.value = connections.value.filter(c => c.id !== conn.id)
      updateStats()

      toast.success(`${conn.username} disconnected successfully`)
      return true
    } catch (err) {
      const errorMsg = err.response?.data?.message || 'Failed to disconnect user'
      error.value = errorMsg
      toast.error(errorMsg)
      throw err
    }
  }

  // Utility functions
  const updateStats = () => {
    const total = connections.value.length
    const hotspot = connections.value.filter(c => c.type === 'hotspot').length
    const pppoe = connections.value.filter(c => c.type === 'pppoe').length
    const active = connections.value.filter(c => c.download_rate > 0 || c.upload_rate > 0).length
    const idle = total - active
    
    // Bandwidth calculations
    const download = connections.value.reduce((sum, c) => sum + c.download_rate, 0)
    const upload = connections.value.reduce((sum, c) => sum + c.upload_rate, 0)
    const bandwidth = download + upload

    // Average session duration in minutes
    const avgSessionDuration = total > 0
      ? Math.round(connections.value.reduce((sum, c) => sum + (c.uptime || 0), 0) / total / 60)
      : 0

    // Update peak today tracking
    if (total > todayPeak) {
      todayPeak = total
    }

    stats.value = {
      total,
      hotspot,
      pppoe,
      active,
      idle,
      download,
      upload,
      bandwidth,
      avgSessionDuration,
      peakToday: todayPeak
    }
  }

  const setFilter = (key, value) => {
    filters.value[key] = value
  }

  const clearFilters = () => {
    filters.value = { type: '', router: '' }
  }

  const searchConnections = (query) => {
    if (!query) return connections.value

    const lowercaseQuery = query.toLowerCase()
    return connections.value.filter(c =>
      c.username.toLowerCase().includes(lowercaseQuery) ||
      c.ip_address.includes(lowercaseQuery) ||
      c.mac_address.toLowerCase().includes(lowercaseQuery)
    )
  }

  const getConnectionById = (id) => {
    return connections.value.find(c => c.id === id)
  }

  const formatBytes = (bytes) => {
    if (!bytes) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
  }

  // Event handlers for WebSocket/SSE updates
  const handleConnectionCreated = (event) => {
    const connectionData = event.detail?.connection
    if (!connectionData) return

    const exists = connections.value.some(c => c.id === connectionData.id)
    if (!exists) {
      connections.value.unshift(connectionData)
      updateStats()
    }
  }

  const handleConnectionUpdated = (event) => {
    const connectionData = event.detail?.connection
    if (!connectionData) return

    const index = connections.value.findIndex(c => c.id === connectionData.id)
    if (index !== -1) {
      connections.value[index] = { ...connections.value[index], ...connectionData }
      updateStats()
    }
  }

  const handleConnectionDeleted = (event) => {
    const connectionId = event.detail?.connectionId
    if (!connectionId) return

    connections.value = connections.value.filter(c => c.id !== connectionId)
    updateStats()
  }

  // SSE Setup for real-time updates
  let eventSource = null

  const setupSSEListeners = () => {
    if (eventSource) return

    const token = authStore.token
    const sseUrl = `${import.meta.env.VITE_API_BASE_URL || ''}/live-connections/stream?token=${token}`

    eventSource = new EventSource(sseUrl)

    eventSource.onmessage = (event) => {
      try {
        const data = JSON.parse(event.data)
        if (data.type === 'connection-created') {
          handleConnectionCreated({ detail: { connection: data.connection } })
        } else if (data.type === 'connection-updated') {
          handleConnectionUpdated({ detail: { connection: data.connection } })
        } else if (data.type === 'connection-deleted') {
          handleConnectionDeleted({ detail: { connectionId: data.connectionId } })
        } else if (data.type === 'stats-update') {
          updateStats()
        }
      } catch (err) {
        console.error('SSE message parse error:', err)
      }
    }

    eventSource.onerror = (err) => {
      console.error('SSE connection error:', err)
      // Attempt to reconnect after 5 seconds
      setTimeout(() => {
        cleanupSSEListeners()
        setupSSEListeners()
      }, 5000)
    }
  }

  const cleanupSSEListeners = () => {
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }
  }

  // Fallback: WebSocket event listeners (alternative to SSE)
  const setupWebSocketListeners = () => {
    window.addEventListener('connection-created', handleConnectionCreated)
    window.addEventListener('connection-updated', handleConnectionUpdated)
    window.addEventListener('connection-deleted', handleConnectionDeleted)
    window.addEventListener('connection-stats-update', updateStats)
  }

  const cleanupWebSocketListeners = () => {
    window.removeEventListener('connection-created', handleConnectionCreated)
    window.removeEventListener('connection-updated', handleConnectionUpdated)
    window.removeEventListener('connection-deleted', handleConnectionDeleted)
    window.removeEventListener('connection-stats-update', updateStats)
  }

  /**
   * Fetch aggregated stats from backend (with throttling)
   * This is the scalable approach - backend does heavy calculations
   */
  const fetchStats = async (force = false) => {
    const now = Date.now()
    
    // Throttle: only fetch if 5 seconds have passed (unless forced)
    if (!force && (now - lastStatsFetch) < STATS_THROTTLE_MS) {
      return statsFetchPromise || Promise.resolve(stats.value)
    }
    
    // Return existing promise if one is in flight
    if (statsFetchPromise) {
      return statsFetchPromise
    }
    
    lastStatsFetch = now
    
    statsFetchPromise = axios.get('/connections/stats', {
      params: { fresh: force }
    })
      .then(response => {
        if (response.data?.success && response.data?.data) {
          const data = response.data.data
          // Update stats with backend-calculated values
          stats.value = {
            total: data.total ?? 0,
            hotspot: data.hotspot ?? 0,
            pppoe: data.pppoe ?? 0,
            active: data.active ?? 0,
            idle: data.idle ?? 0,
            download: data.download ?? 0,
            upload: data.upload ?? 0,
            bandwidth: data.bandwidth ?? 0,
            avgSessionDuration: data.avgSessionDuration ?? 0,
            peakToday: data.peakToday ?? 0
          }
          todayPeak = data.peakToday ?? todayPeak
        }
        return stats.value
      })
      .catch(err => {
        console.warn('Failed to fetch stats from backend, falling back to client-side:', err.message)
        // Fallback: calculate client-side if backend fails
        updateStats()
        return stats.value
      })
      .finally(() => {
        statsFetchPromise = null
      })
    
    return statsFetchPromise
  }

  // Modify event handlers to use throttled fetchStats
  const throttledUpdateStats = () => {
    fetchStats().catch(console.error)
  }

  return {
    // Reactive data
    connections,
    filteredConnections,
    hotspotConnections,
    pppoeConnections,
    activeConnections,
    routers,
    filters,
    stats,
    loading,
    error,

    // API functions
    fetchConnections,
    fetchRouters,
    disconnectUser,
    fetchStats, // New: backend stats with throttling

    // Utility functions
    getConnectionById,
    searchConnections,
    setFilter,
    clearFilters,
    updateStats: throttledUpdateStats, // Now throttled
    formatBytes,

    // Event handlers
    handleConnectionCreated,
    handleConnectionUpdated,
    handleConnectionDeleted,

    // Real-time setup (SSE preferred, WebSocket fallback)
    setupSSEListeners,
    cleanupSSEListeners,
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
