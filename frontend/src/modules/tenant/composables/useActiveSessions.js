/**
 * Active Sessions Composable - Event-Driven with WebSocket
 * WiFi Hotspot System
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useToast } from '@/modules/common/composables/useToast'
import { readSnapshot, scheduleAfterPaint, writeSnapshot } from '@/modules/common/composables/performance/useViewCache'

export function useActiveSessions() {
  const loading = ref(false)
  const error = ref(null)
  const sessions = ref([])
  const packages = ref([])
  const cacheKey = 'tenant:active-sessions:v1'

  const { toast } = useToast()

  // Filters
  const filters = ref({
    package: '',
    duration: ''
  })

  // Computed
  const stats = computed(() => ({
    total: sessions.value.length,
    users: new Set(sessions.value.map(s => s.username)).size,
    bandwidth: sessions.value.reduce((sum, s) => sum + (s.current_bandwidth || 0), 0),
    totalData: sessions.value.reduce((sum, s) => sum + (s.bytes_in || 0) + (s.bytes_out || 0), 0)
  }))

  const filteredSessions = computed(() => {
    let result = [...sessions.value]
    if (filters.value.package) {
      result = result.filter(s => s.package?.id === parseInt(filters.value.package))
    }
    if (filters.value.duration) {
      result = result.filter(s => {
        const minutes = s.duration / 60
        if (filters.value.duration === 'short') return minutes < 5
        if (filters.value.duration === 'medium') return minutes >= 5 && minutes <= 30
        if (filters.value.duration === 'long') return minutes > 30
        return true
      })
    }
    return result
  })

  const hydrateSessions = () => {
    const snapshot = readSnapshot(cacheKey, 20 * 1000)
    if (snapshot && Array.isArray(snapshot.sessions)) {
      sessions.value = snapshot.sessions
      packages.value = Array.isArray(snapshot.packages) ? snapshot.packages : packages.value
      return true
    }
    return false
  }

  // API Functions
  const fetchSessions = async () => {
    const isInitial = sessions.value.length === 0
    if (isInitial) {
      const cached = hydrateSessions()
      if (!cached) {
        scheduleAfterPaint(() => {
          if (sessions.value.length === 0) loading.value = true
        })
      }
    }
    error.value = null
    try {
      const response = await axios.get('/hotspot/sessions/active')
      const data = response.data?.sessions || response.data?.data || []
      sessions.value = data
      writeSnapshot(cacheKey, { sessions: sessions.value, packages: packages.value })
      return data
    } catch (err) {
      // Guard against undefined or non-axios errors
      const errorMsg = err?.response?.data?.message || err?.message || 'Failed to load active sessions'
      error.value = errorMsg
      toast.error(errorMsg)
      console.error('Fetch sessions error:', err)
      return []
    } finally {
      loading.value = false
    }
  }

  const fetchPackages = async () => {
    try {
      const response = await axios.get('/packages')
      packages.value = (response.data?.data || response.data || []).map(p => ({ id: p.id, name: p.name }))
      writeSnapshot(cacheKey, { sessions: sessions.value, packages: packages.value })
      return packages.value
    } catch (err) {
      console.warn('Failed to fetch packages:', err)
      return []
    }
  }

  const disconnectSession = async (session) => {
    try {
      const userId = session._raw?.user_id || session._raw?.id || session.id
      await axios.post(`/hotspot/users/${userId}/disconnect`)
      sessions.value = sessions.value.filter(s => s.id !== session.id)
      toast.success(`${session.user?.name || session.username} disconnected successfully`)
      return true
    } catch (err) {
      // Guard against undefined or non-axios errors
      const errorMsg = err?.response?.data?.message || err?.message || 'Failed to disconnect session'
      error.value = errorMsg
      toast.error(errorMsg)
      console.error('Disconnect session error:', err)
      return false
    }
  }

  // Utility functions
  const setFilter = (key, value) => {
    filters.value[key] = value
  }

  const clearFilters = () => {
    filters.value = { package: '', duration: '' }
  }

  const searchSessions = (query) => {
    if (!query) return sessions.value
    const lowercaseQuery = query.toLowerCase()
    return sessions.value.filter(s =>
      s.username?.toLowerCase().includes(lowercaseQuery) ||
      s.user?.name?.toLowerCase().includes(lowercaseQuery) ||
      s.ip_address?.includes(lowercaseQuery) ||
      s.mac_address?.toLowerCase().includes(lowercaseQuery)
    )
  }

  const getSessionById = (id) => {
    return sessions.value.find(s => s.id === id)
  }

  const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return '-'
    if (bytes === 0) return '0 B'
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

  const formatBytesCompact = (bytes) => {
    if (bytes === null || bytes === undefined) return '-'
    if (bytes === 0) return '0'
    if (bytes >= 1073741824) return `${(bytes / 1073741824).toFixed(1)}G`
    if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)}M`
    if (bytes >= 1024) return `${(bytes / 1024).toFixed(1)}K`
    return `${bytes}B`
  }

  const formatDuration = (seconds) => {
    if (!seconds) return '0s'
    const hours = Math.floor(seconds / 3600)
    const minutes = Math.floor((seconds % 3600) / 60)
    const secs = Math.floor(seconds % 60)
    if (hours > 0) return `${hours}h ${minutes}m`
    if (minutes > 0) return `${minutes}m ${secs}s`
    return `${secs}s`
  }

  const formatTime = (date) => {
    if (!date) return 'N/A'
    return new Date(date).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  }

  // WebSocket event handlers
  const handleSessionCreated = (event) => {
    const sessionData = event.detail?.session
    if (!sessionData) return
    const exists = sessions.value.some(s => s.id === sessionData.id)
    if (!exists) {
      sessions.value.unshift(sessionData)
    }
  }

  const handleSessionUpdated = (event) => {
    const sessionData = event.detail?.session
    if (!sessionData) return
    const index = sessions.value.findIndex(s => s.id === sessionData.id)
    if (index !== -1) {
      sessions.value[index] = { ...sessions.value[index], ...sessionData }
    }
  }

  const handleSessionDeleted = (event) => {
    const sessionId = event.detail?.sessionId
    if (!sessionId) return
    sessions.value = sessions.value.filter(s => s.id !== sessionId)
  }

  // WebSocket Setup
  const setupWebSocketListeners = () => {
    window.addEventListener('session-created', handleSessionCreated)
    window.addEventListener('session-updated', handleSessionUpdated)
    window.addEventListener('session-deleted', handleSessionDeleted)
  }

  const cleanupWebSocketListeners = () => {
    window.removeEventListener('session-created', handleSessionCreated)
    window.removeEventListener('session-updated', handleSessionUpdated)
    window.removeEventListener('session-deleted', handleSessionDeleted)
  }

  return {
    // Reactive data
    sessions,
    filteredSessions,
    packages,
    filters,
    stats,
    loading,
    error,

    // API functions
    fetchSessions,
    fetchPackages,
    disconnectSession,

    // Utility functions
    getSessionById,
    searchSessions,
    setFilter,
    clearFilters,
    formatBytes,
    formatBytesCompact,
    formatDuration,
    formatTime,

    // Event handlers
    handleSessionCreated,
    handleSessionUpdated,
    handleSessionDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
