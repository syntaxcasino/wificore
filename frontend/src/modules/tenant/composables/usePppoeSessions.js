import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'

export function usePppoeSessions() {
  const { subscribeToPrivateChannel, unsubscribeFromChannel } = useBroadcasting()
  const authStore = useAuthStore()
  const toast = useToast()

  // State
  const sessions = ref([])
  const loading = ref(false)
  const refreshing = ref(false)
  const error = ref(null)
  const sessionChannel = ref(null)
  // Debounce guard for rapid WebSocket events
  let refreshDebounceTimer = null
  const REFRESH_DEBOUNCE_MS = 2000

  // Stats
  const totalSessions = computed(() => sessions.value.length)
  const totalUsers = computed(() => new Set(sessions.value.map(s => s.username)).size)
  const totalBandwidth = computed(() => 
    sessions.value.reduce((sum, s) => 
      sum + (s.download_rate || s.download_speed || 0) + (s.upload_rate || s.upload_speed || 0), 0)
  )

  // Routers list from sessions
  const routers = computed(() => {
    const byId = new Map()
    for (const s of sessions.value) {
      if (!s?.router_id) continue
      if (!byId.has(s.router_id)) {
        byId.set(s.router_id, { id: s.router_id, name: s.router_name || 'N/A' })
      }
    }
    return Array.from(byId.values())
  })

  // Actions
  const fetchSessions = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('pppoe/sessions')
      const payload = response.data?.data ?? response.data
      sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      return sessions.value
    } catch (err) {
      // Guard against undefined or non-axios errors
      error.value = err?.response?.data?.message || err?.message || 'Failed to load active sessions. Please try again.'
      console.error('Error fetching sessions:', err)
      return []
    } finally {
      loading.value = false
    }
  }

  const refreshSessions = async () => {
    refreshing.value = true
    error.value = null
    try {
      const response = await axios.get('pppoe/sessions')
      const payload = response.data?.data ?? response.data
      sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      return sessions.value
    } catch (err) {
      // Guard against undefined or non-axios errors
      error.value = err?.response?.data?.message || err?.message || 'Failed to refresh sessions. Please try again.'
      console.error('Error refreshing sessions:', err)
      return []
    } finally {
      refreshing.value = false
    }
  }

  const disconnectSession = async (session) => {
    try {
      const response = await axios.post('pppoe/sessions/disconnect', { username: session.username })
      if (response.data?.success) {
        toast.success(`Successfully disconnected ${session.username}`)
        // Optimistically remove from local state immediately for better UX
        sessions.value = sessions.value.filter(s => s.username !== session.username)
        return true
      } else {
        toast.error(response.data?.message || 'Failed to disconnect session')
        return false
      }
    } catch (err) {
      console.error('Error disconnecting session:', err)
      const errorMsg = err?.response?.data?.message || err?.message || 'Failed to disconnect session'
      toast.error(errorMsg)
      return false
    }
  }

  // Filtering
  const filterSessions = (query, filters = {}) => {
    let result = [...sessions.value]
    
    if (query) {
      const q = query.toLowerCase()
      result = result.filter(session =>
        session.username?.toLowerCase().includes(q) ||
        session.ip_address?.includes(q) ||
        session.framed_ip?.includes(q) ||
        session.mac_address?.toLowerCase().includes(q) ||
        session.calling_station_id?.toLowerCase().includes(q)
      )
    }
    
    if (filters.router) {
      result = result.filter(session => String(session.router_id ?? '') === String(filters.router))
    }
    
    if (filters.duration) {
      result = result.filter(session => {
        const hours = session.duration / 3600
        if (filters.duration === 'short') return hours < 1
        if (filters.duration === 'medium') return hours >= 1 && hours <= 6
        if (filters.duration === 'long') return hours > 6
        return true
      })
    }
    
    return result
  }

  // WebSocket — event-driven only, no polling
  const debouncedRefresh = () => {
    if (refreshDebounceTimer) clearTimeout(refreshDebounceTimer)
    refreshDebounceTimer = setTimeout(() => refreshSessions(), REFRESH_DEBOUNCE_MS)
  }

  const setupWebSocketListeners = () => {
    const tenantId = authStore.tenantId
    if (!tenantId) {
      console.warn('WebSocket: No tenant ID available')
      return
    }

    try {
      sessionChannel.value = `tenant.${tenantId}.pppoe-sessions`
      subscribeToPrivateChannel(sessionChannel.value, {
        PppoeSessionStarted: debouncedRefresh,
        PppoeSessionEnded: (event) => {
          if (event?.session) {
            sessions.value = sessions.value.filter(s =>
              !(s.username === event.session.username && s.router_id === event.session.router_id)
            )
          }
        },
        PppoeSessionUpdated: debouncedRefresh
      })
    } catch (err) {
      console.error('WebSocket setup error:', err)
    }
  }

  const cleanupWebSocketListeners = () => {
    try {
      if (refreshDebounceTimer) {
        clearTimeout(refreshDebounceTimer)
        refreshDebounceTimer = null
      }
      if (sessionChannel.value && unsubscribeFromChannel) {
        unsubscribeFromChannel(sessionChannel.value)
        sessionChannel.value = null
      }
    } catch (err) {
      console.error('WebSocket cleanup error:', err)
    }
  }

  // Helpers
  const formatBytes = (bytes) => {
    // Distinguish between null/undefined (metrics unavailable) vs actual 0 bytes
    if (bytes === null || bytes === undefined) return '-'
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
  }

  const formatDuration = (seconds) => {
    if (!seconds) return '0s'
    const hours = Math.floor(seconds / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)
    if (hours > 0) return `${hours}h ${minutes}m`
    return `${minutes}m`
  }

  const formatDateTime = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleString()
  }

  const getUserInitials = (session) => {
    if (!session.username) return '?'
    return session.username.slice(0, 2).toUpperCase()
  }

  return {
    // State
    sessions,
    loading,
    refreshing,
    error,
    routers,
    
    // Computed
    totalSessions,
    totalUsers,
    totalBandwidth,
    
    // Actions
    fetchSessions,
    refreshSessions,
    disconnectSession,
    filterSessions,
    setupWebSocketListeners,
    cleanupWebSocketListeners,
    
    // Helpers
    formatBytes,
    formatDuration,
    formatDateTime,
    getUserInitials
  }
}
