import { ref } from 'vue'
import axios from 'axios'
import { readSnapshot, scheduleAfterPaint, writeSnapshot } from '@/modules/common/composables/performance/useViewCache'

/**
 * Shared composable for reports that pull from both hotspot and pppoe sessions.
 * Used by UserSessionHistory, DailyLoginReports, BandwidthUsageSummary, ExpiredAccounts.
 */
export function useSessionReports(endpoint = null) {
  const loading = ref(false)
  const refreshing = ref(false)
  const sessions = ref([])
  const users = ref([])
  const cacheKey = endpoint ? `tenant:session-reports:${endpoint}` : 'tenant:session-reports:combined'

  const formatDateTime = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleString('en-GB', {
      day: 'numeric', month: 'short', year: 'numeric',
      hour: '2-digit', minute: '2-digit'
    })
  }

  const formatBytes = (bytes) => {
    if (bytes === null || bytes === undefined) return '-'
    if (bytes === 0) return '0 B'
    const k = 1024
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
    const i = Math.floor(Math.log(bytes) / Math.log(k))
    return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
  }

  const formatDuration = (seconds) => {
    if (!seconds) return '—'
    const hours = Math.floor(seconds / 3600)
    const mins = Math.floor((seconds % 3600) / 60)
    if (hours > 0) return `${hours}h ${mins}m`
    return `${mins}m`
  }

  const hydrateSessions = () => {
    const snapshot = readSnapshot(`${cacheKey}:sessions`, 30 * 1000)
    if (snapshot && Array.isArray(snapshot)) {
      sessions.value = snapshot
      return true
    }
    return false
  }

  const persistSessions = () => writeSnapshot(`${cacheKey}:sessions`, sessions.value)

  const fetchSessions = async () => {
    const isInitial = sessions.value.length === 0
    if (isInitial && !hydrateSessions()) {
      scheduleAfterPaint(() => {
        if (sessions.value.length === 0) loading.value = true
      })
    } else if (sessions.value.length > 0) {
      refreshing.value = true
    }
    try {
      if (endpoint) {
        const res = await axios.get(endpoint)
        sessions.value = res.data?.sessions || res.data?.data || []
      } else {
        const [hotspotRes, pppoeRes] = await Promise.all([
          axios.get('/hotspot/sessions').catch(() => ({ data: { sessions: [] } })),
          axios.get('/pppoe/sessions/live').catch(() => ({ data: { sessions: [] } }))
        ])
        const hotspotSessions = (hotspotRes.data?.sessions || hotspotRes.data?.data || []).map(s => ({ ...s, _type: 'hotspot' }))
        const pppoeSessions = (pppoeRes.data?.sessions || pppoeRes.data?.data || []).map(s => ({ ...s, _type: 'pppoe' }))
        sessions.value = [...hotspotSessions, ...pppoeSessions]
      }
    } catch (err) {
      console.error('fetchSessions error:', err)
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  const hydrateExpired = () => {
    const snapshot = readSnapshot(`${cacheKey}:expired`, 30 * 1000)
    if (snapshot && Array.isArray(snapshot)) {
      users.value = snapshot
      return true
    }
    return false
  }

  const persistExpired = () => writeSnapshot(`${cacheKey}:expired`, users.value)

  const fetchExpired = async () => {
    const isInitial = users.value.length === 0
    if (isInitial && !hydrateExpired()) {
      scheduleAfterPaint(() => {
        if (users.value.length === 0) loading.value = true
      })
    } else if (users.value.length > 0) {
      refreshing.value = true
    }
    try {
      const [hotspotRes, pppoeRes] = await Promise.all([
        axios.get('/hotspot/users', { params: { status: 'expired' } }).catch(() => ({ data: {} })),
        axios.get('/pppoe/users', { params: { status: 'expired' } }).catch(() => ({ data: {} }))
      ])
      const hotspotUsers = (hotspotRes.data?.users?.data || hotspotRes.data?.users || []).map(u => ({ ...u, _type: 'hotspot' }))
      const pppoeUsers = (pppoeRes.data?.users?.data || pppoeRes.data?.users || []).map(u => ({ ...u, _type: 'pppoe' }))
      users.value = [...hotspotUsers, ...pppoeUsers]
      persistExpired()
    } catch (err) {
      console.error('fetchExpired error:', err)
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  const refreshData = () => fetchSessions()
  const refreshExpired = () => fetchExpired()

  return {
    loading, refreshing, sessions, users,
    formatDateTime, formatBytes, formatDuration,
    fetchSessions, fetchExpired, refreshData, refreshExpired
  }
}
