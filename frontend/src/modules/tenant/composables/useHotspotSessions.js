import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'

export function useHotspotSessions() {
  const { subscribeToPrivateChannel, unsubscribe } = useBroadcasting()
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
      sum + (s.download_rate || 0) + (s.upload_rate || 0), 0)
  )

  // Actions
  const fetchSessions = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('hotspot/sessions/live')
      const payload = response.data?.data ?? response.data
      sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      return sessions.value
    } catch (err) {
      error.value = err?.response?.data?.message || err?.message || 'Failed to load active hotspot sessions.'
      console.error('Error fetching hotspot sessions:', err)
      return []
    } finally {
      loading.value = false
    }
  }

  const refreshSessions = async () => {
    refreshing.value = true
    error.value = null
    try {
      const response = await axios.get('hotspot/sessions/live')
      const payload = response.data?.data ?? response.data
      sessions.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      return sessions.value
    } catch (err) {
      error.value = err?.response?.data?.message || err?.message || 'Failed to refresh hotspot sessions.'
      console.error('Error refreshing hotspot sessions:', err)
      return []
    } finally {
      refreshing.value = false
    }
  }

  const disconnectSession = async (session) => {
    try {
      const response = await axios.post(`hotspot/users/${session.hotspot_user_id}/disconnect`, {
        reason: 'Admin disconnect',
      })
      if (response.data?.success) {
        toast.success(`Successfully disconnected ${session.username}`)
        // Optimistically remove from local state immediately for better UX
        sessions.value = sessions.value.filter(s => s.id !== session.id)
        return true
      } else {
        toast.error(response.data?.message || 'Failed to disconnect session')
        return false
      }
    } catch (err) {
      console.error('Error disconnecting hotspot session:', err)
      toast.error(err?.response?.data?.message || err?.message || 'Failed to disconnect session')
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
      console.warn('WebSocket: No tenant ID available for hotspot sessions')
      return
    }

    try {
      sessionChannel.value = `tenant.${tenantId}.hotspot-sessions`
      subscribeToPrivateChannel(sessionChannel.value, {
        HotspotSessionStarted: debouncedRefresh,
        HotspotSessionEnded: (event) => {
          if (event?.session) {
            sessions.value = sessions.value.filter(s =>
              !(s.username === event.session.username && s.nas_ip_address === event.session.nas_ip_address)
            )
          }
        },
        HotspotSessionUpdated: debouncedRefresh,
      })
    } catch (err) {
      console.error('WebSocket setup error (hotspot sessions):', err)
    }
  }

  const cleanupWebSocketListeners = () => {
    try {
      if (refreshDebounceTimer) {
        clearTimeout(refreshDebounceTimer)
        refreshDebounceTimer = null
      }
      if (sessionChannel.value) {
        unsubscribe(sessionChannel.value)
        sessionChannel.value = null
      }
    } catch (err) {
      console.error('WebSocket cleanup error (hotspot sessions):', err)
    }
  }

  // Helpers
  const formatBytes = (bytes) => {
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
    getUserInitials,
  }
}
