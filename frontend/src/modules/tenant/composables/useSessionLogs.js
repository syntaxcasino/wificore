import { ref, computed } from 'vue'
import axios from 'axios'
import { LogIn, LogOut, Clock, AlertCircle } from 'lucide-vue-next'

export function useSessionLogs() {
  const loading = ref(false)
  const logs = ref([])

  const stats = computed(() => ({
    total: logs.value.length,
    logins: logs.value.filter(l => l.event_type === 'login').length,
    logouts: logs.value.filter(l => l.event_type === 'logout').length,
    errors: logs.value.filter(l => l.event_type === 'error').length
  }))

  const formatDateTime = (date) => {
    if (!date) return '—'
    return new Date(date).toLocaleString('en-GB', {
      day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit'
    })
  }

  const formatDuration = (seconds) => {
    if (!seconds) return '—'
    const hours = Math.floor(seconds / 3600)
    const mins = Math.floor((seconds % 3600) / 60)
    if (hours > 0) return `${hours}h ${mins}m`
    return `${mins}m ${seconds % 60}s`
  }

  const getEventIcon = (eventType) =>
    ({ login: LogIn, logout: LogOut, timeout: Clock, error: AlertCircle }[eventType] || Clock)

  const getEventIconColor = (eventType) =>
    ({ login: 'text-emerald-600', logout: 'text-blue-600', timeout: 'text-amber-600', error: 'text-red-600' }[eventType] || 'text-slate-600')

  const getLogStatus = (eventType) =>
    ({ login: 'success', logout: 'info', timeout: 'warning', error: 'error' }[eventType] || 'default')

  const fetchLogs = async () => {
    loading.value = true
    try {
      const response = await axios.get('/monitoring/session-logs', { params: { per_page: 500 } })
      const data = response.data?.logs?.data || response.data?.logs || response.data?.data || []
      logs.value = data.map(l => ({
        id: l.id,
        username: l.username || 'Unknown',
        event_type: l.event_type || 'unknown',
        ip_address: l.ip_address || null,
        mac_address: l.mac_address || null,
        session_duration: l.session_duration || null,
        created_at: l.created_at || new Date().toISOString()
      }))
    } catch (err) {
      console.error('fetchLogs error:', err)
    } finally {
      loading.value = false
    }
  }

  const exportLogs = (data) => {
    const csv = [
      ['Time', 'Username', 'Event', 'IP Address', 'MAC Address', 'Duration'].join(','),
      ...data.map(l => [l.created_at, l.username, l.event_type, l.ip_address || '', l.mac_address || '', l.session_duration || ''].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `session-logs-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  return {
    loading, logs, stats,
    formatDateTime, formatDuration,
    getEventIcon, getEventIconColor, getLogStatus,
    fetchLogs, exportLogs
  }
}
