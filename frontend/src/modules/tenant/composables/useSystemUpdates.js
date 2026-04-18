import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'

export function useSystemUpdates() {
  const { error: showError } = useToast()

  const loading = ref(false)
  const checking = ref(false)
  const servers = ref([])
  const routers = ref([])
  const accessPoints = ref([])
  const updateHistory = ref([])
  const lastCheck = ref(null)
  const showRouterDetails = ref(false)
  const selectedRouter = ref(null)

  const tabs = [
    { id: 'servers', label: 'Servers' },
    { id: 'routers', label: 'Routers' },
    { id: 'access-points', label: 'Access Points' },
    { id: 'history', label: 'Update History' }
  ]

  const stats = computed(() => ({
    servers: servers.value.length,
    routers: routers.value.length,
    accessPoints: accessPoints.value.length,
    serverUpdates: servers.value.filter(s => s.update_available).length,
    routerUpdates: routers.value.filter(r => r.update_available).length,
    apUpdates: accessPoints.value.filter(ap => ap.update_available).length
  }))

  const lastCheckText = computed(() => {
    if (!lastCheck.value) return 'Never'
    const diff = (new Date() - new Date(lastCheck.value)) / 1000 / 60
    if (diff < 1) return 'Just now'
    if (diff < 60) return `${Math.floor(diff)}m ago`
    return `${Math.floor(diff / 60)}h ago`
  })

  const lastCheckTime = computed(() =>
    lastCheck.value ? new Date(lastCheck.value).toLocaleString() : ''
  )

  const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('en-GB', {
      day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit'
    })
  }

  const getUpdateStatusClass = (status) =>
    ({
      success: 'bg-emerald-100 text-emerald-700',
      failed: 'bg-red-100 text-red-700',
      pending: 'bg-amber-100 text-amber-700',
      in_progress: 'bg-blue-100 text-blue-700'
    }[status] || 'bg-slate-100 text-slate-700')

  const fetchUpdates = async () => {
    loading.value = true
    try {
      const [serversRes, routersRes, apsRes, historyRes] = await Promise.all([
        axios.get('/system-updates/servers').catch(() => ({ data: { servers: [] } })),
        axios.get('/system-updates/routers').catch(() => ({ data: { routers: [] } })),
        axios.get('/system-updates/access-points').catch(() => ({ data: { access_points: [] } })),
        axios.get('/system-updates/history').catch(() => ({ data: { history: [] } }))
      ])
      servers.value = serversRes.data?.servers || []
      routers.value = routersRes.data?.routers || []
      accessPoints.value = apsRes.data?.access_points || []
      updateHistory.value = historyRes.data?.history || []
      lastCheck.value = new Date().toISOString()
    } catch (err) {
      console.error('fetchUpdates error:', err)
    } finally {
      loading.value = false
    }
  }

  const checkForUpdates = async () => {
    checking.value = true
    try {
      await axios.post('/system-updates/check')
      await fetchUpdates()
    } catch (err) {
      console.error('checkForUpdates error:', err)
    } finally {
      checking.value = false
    }
  }

  const updateServer = async (server) => {
    server.updating = true
    try {
      await axios.post(`/system-updates/servers/${server.id}/update`)
      await fetchUpdates()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to update server')
    } finally {
      server.updating = false
    }
  }

  const updateRouter = async (router) => {
    router.updating = true
    try {
      await axios.post(`/system-updates/routers/${router.id}/update`)
      await fetchUpdates()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to update router')
    } finally {
      router.updating = false
    }
  }

  const updateAccessPoint = async (ap) => {
    ap.updating = true
    try {
      await axios.post(`/system-updates/access-points/${ap.id}/update`)
      await fetchUpdates()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to update access point')
    } finally {
      ap.updating = false
    }
  }

  const viewRouterDetails = (router) => {
    selectedRouter.value = router
    showRouterDetails.value = true
  }

  return {
    loading, checking, servers, routers, accessPoints, updateHistory, lastCheck,
    showRouterDetails, selectedRouter, tabs,
    stats, lastCheckText, lastCheckTime,
    formatDate, getUpdateStatusClass,
    fetchUpdates, checkForUpdates,
    updateServer, updateRouter, updateAccessPoint, viewRouterDetails
  }
}
