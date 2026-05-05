import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'

export function useInactivePppoeSessions() {
  const sessions   = ref([])
  const loading    = ref(false)
  const error      = ref(null)
  const total      = ref(0)
  const lastPage   = ref(1)
  const currentPage = ref(1)
  const perPage    = ref(50)
  const searchQuery = ref('')

  const fetchSessions = async (opts = {}) => {
    loading.value = true
    error.value   = null
    try {
      const params = {
        page:     opts.page     ?? currentPage.value,
        per_page: opts.perPage  ?? perPage.value,
        search:   opts.search   ?? searchQuery.value,
      }
      const response = await axios.get('pppoe/sessions/inactive', { params })
      const payload  = response.data

      sessions.value  = Array.isArray(payload.data) ? payload.data : []
      total.value     = payload.total     ?? sessions.value.length
      lastPage.value  = payload.last_page ?? 1
      currentPage.value = params.page

      return sessions.value
    } catch (err) {
      error.value = err?.response?.data?.message || err?.message || 'Failed to load inactive sessions.'
      console.error('Error fetching inactive sessions:', err)
      return []
    } finally {
      loading.value = false
    }
  }

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

  const filterSessions = (query, filters = {}) => {
    let result = [...sessions.value]
    if (query) {
      const q = query.toLowerCase()
      result = result.filter(s =>
        s.username?.toLowerCase().includes(q) ||
        s.ip_address?.includes(q) ||
        s.mac_address?.toLowerCase().includes(q)
      )
    }
    if (filters.router) {
      result = result.filter(s => String(s.router_id ?? '') === String(filters.router))
    }
    return result
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

  const formatTerminateCause = (cause) => {
    if (!cause) return 'Unknown'
    const map = {
      'User-Request':          'User Disconnected',
      'Lost-Carrier':          'Lost Carrier',
      'Lost-Service':          'Lost Service',
      'Idle-Timeout':          'Idle Timeout',
      'Session-Timeout':       'Session Timeout',
      'Admin-Reset':           'Admin Reset',
      'Admin-Reboot':          'Admin Reboot',
      'Port-Error':            'Port Error',
      'NAS-Error':             'NAS Error',
      'NAS-Request':           'NAS Request',
      'NAS-Reboot':            'NAS Reboot',
      'Port-Unneeded':         'Port Unneeded',
      'Port-Preempted':        'Port Preempted',
      'Port-Suspended':        'Port Suspended',
      'Service-Unavailable':   'Service Unavailable',
      'Callback':              'Callback',
      'User-Error':            'User Error',
      'Host-Request':          'Host Request',
    }
    return map[cause] || cause
  }

  return {
    sessions,
    loading,
    error,
    total,
    lastPage,
    currentPage,
    perPage,
    searchQuery,
    routers,
    fetchSessions,
    filterSessions,
    formatBytes,
    formatDuration,
    formatDateTime,
    getUserInitials,
    formatTerminateCause,
  }
}
