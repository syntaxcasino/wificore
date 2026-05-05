import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'

export function useOnlineUsers() {
  const { error: showError } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const error = ref(null)
  const users = ref([])
  const selectedUser = ref(null)
  const showDetailsOverlay = ref(false)

  const totalOnline = computed(() => users.value.length)
  const hotspotCount = computed(() => users.value.filter(u => u.type === 'hotspot').length)
  const pppoeCount = computed(() => users.value.filter(u => u.type === 'pppoe').length)

  const getUserInitials = (user) =>
    user.name ? user.name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '?'

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

  const formatTime = (date) => date ? new Date(date).toLocaleTimeString() : 'N/A'
  const formatDateTime = (date) => date ? new Date(date).toLocaleString() : 'N/A'

  const fetchUsers = async () => {
    const isInitial = users.value.length === 0
    if (isInitial) { loading.value = true; error.value = null }
    try {
      const [hotspotRes, pppoeRes] = await Promise.all([
        axios.get('/hotspot/sessions'),
        axios.get('/pppoe/sessions/live')
      ])
      const hotspotSessions = hotspotRes.data?.sessions || hotspotRes.data?.data || []
      const pppoeSessions = pppoeRes.data?.sessions || pppoeRes.data?.data || []
      const mapHotspot = (s) => ({
        id: `hs_${s.id}`, type: 'hotspot',
        username: s.username || s.user?.username || 'Unknown',
        name: s.user?.name || s.username || 'Unknown',
        phone: s.user?.phone || '', ip_address: s.ip_address || s.framed_ip || '',
        package: s.package || null, session_duration: s.session_time || s.duration || 0,
        bytes_in: s.input_bytes || s.bytes_in || 0, bytes_out: s.output_bytes || s.bytes_out || 0,
        connected_at: s.created_at || s.called_station_id || null, _raw: s
      })
      const mapPppoe = (s) => ({
        id: `pp_${s.id}`, type: 'pppoe',
        username: s.username || s.user?.username || 'Unknown',
        name: s.user?.name || s.username || 'Unknown',
        phone: s.user?.phone || '', ip_address: s.framed_ip_address || s.ip_address || '',
        package: s.package || null, session_duration: s.acct_session_time || s.duration || 0,
        bytes_in: s.acct_input_octets || s.bytes_in || 0, bytes_out: s.acct_output_octets || s.bytes_out || 0,
        connected_at: s.created_at || null, _raw: s
      })
      users.value = [
        ...hotspotSessions.map(mapHotspot),
        ...pppoeSessions.map(mapPppoe)
      ]
    } catch (err) {
      if (isInitial) error.value = err.response?.data?.message || 'Failed to load online users'
      console.error('fetchUsers error:', err)
    } finally {
      loading.value = false
    }
  }

  const viewUserDetails = (user) => {
    selectedUser.value = user
    showDetailsOverlay.value = true
  }

  const closeDetailsOverlay = () => {
    showDetailsOverlay.value = false
  }

  const disconnectUser = async (user) => {
    const confirmed = await confirmStore.open({
      title: 'Disconnect User',
      message: `Disconnect ${user.name || user.username}?`,
      confirmText: 'Disconnect', cancelText: 'Cancel', variant: 'warning'
    })
    if (!confirmed) return
    try {
      if (user.type === 'hotspot') {
        const userId = user._raw?.user_id || user._raw?.id || user.id.replace('hs_', '')
        await axios.post(`/hotspot/users/${userId}/disconnect`)
      } else {
        const userId = user._raw?.user_id || user._raw?.id || user.id.replace('pp_', '')
        await axios.post(`/pppoe/users/${userId}/disconnect`)
      }
      users.value = users.value.filter(u => u.id !== user.id)
      showDetailsOverlay.value = false
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to disconnect user')
    }
  }

  const exportData = (filteredData) => {
    const csv = [
      ['Username', 'Name', 'Type', 'IP', 'Package', 'Duration', 'Data In', 'Data Out'].join(','),
      ...filteredData.map(u =>
        [u.username, u.name, u.type, u.ip_address, u.package?.name || '', u.session_duration, u.bytes_in, u.bytes_out].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `online-users-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  return {
    loading, error, users, selectedUser, showDetailsOverlay,
    totalOnline, hotspotCount, pppoeCount,
    getUserInitials, formatBytes, formatDuration, formatTime, formatDateTime,
    fetchUsers, viewUserDetails, closeDetailsOverlay, disconnectUser, exportData
  }
}
