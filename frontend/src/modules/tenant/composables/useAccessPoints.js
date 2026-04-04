/**
 * Access Points Management Composable - Event-Driven (No Polling)
 * WiFi Hotspot System
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'

export function useAccessPoints() {
  const loading = ref(false)
  const error = ref(null)
  const accessPoints = ref([])
  const stats = ref({
    total: 0,
    online: 0,
    offline: 0,
    unknown: 0,
    totalUsers: 0
  })
  
  const { toast } = useToast()
  const authStore = useAuthStore()

  // Computed filters
  const onlineAccessPoints = computed(() => 
    Array.isArray(accessPoints.value) ? accessPoints.value.filter(ap => ap.status === 'online') : []
  )
  
  const offlineAccessPoints = computed(() => 
    Array.isArray(accessPoints.value) ? accessPoints.value.filter(ap => ap.status === 'offline') : []
  )
  
  const unknownAccessPoints = computed(() => 
    Array.isArray(accessPoints.value) ? accessPoints.value.filter(ap => ap.status === 'unknown' || !ap.status) : []
  )

  // API Functions (trigger events, no polling needed)
  const fetchAccessPoints = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/access-points?${params}` : '/access-points'
      const response = await axios.get(url)
      
      // Debug logging to understand response structure
      console.log('fetchAccessPoints response:', response)
      console.log('response.data:', response?.data)
      
      // Handle various response structures safely
      let apData = []
      if (response?.data) {
        if (Array.isArray(response.data)) {
          apData = response.data
        } else if (response.data.access_points && Array.isArray(response.data.access_points)) {
          apData = response.data.access_points
        } else if (response.data.data && Array.isArray(response.data.data)) {
          apData = response.data.data
        } else if (typeof response.data === 'object') {
          // If it's a single object wrapped, try to extract
          apData = [response.data]
        }
      }
      
      accessPoints.value = apData.map(ap => ({
        id: ap.id,
        router_id: ap.router_id || null,
        name: ap.name || 'Unnamed',
        vendor: ap.vendor || 'other',
        model: ap.model || '',
        ip_address: ap.ip_address || null,
        mac_address: ap.mac_address || null,
        serial_number: ap.serial_number || null,
        location: ap.location || null,
        status: ap.status || 'unknown',
        active_users: ap.active_users || 0,
        total_capacity: ap.total_capacity || 0,
        management_protocol: ap.management_protocol || 'snmp'
      }))
      
      updateStats()
      
      return accessPoints.value
    } catch (err) {
      console.error('fetchAccessPoints error:', err)
      // Safely extract error message with multiple fallbacks
      let errorMsg = 'Failed to fetch access points'
      try {
        if (err?.response?.data?.message) {
          errorMsg = err.response.data.message
        } else if (err?.response?.data?.error) {
          errorMsg = err.response.data.error
        } else if (err?.message) {
          errorMsg = err.message
        } else if (typeof err === 'string') {
          errorMsg = err
        }
      } catch (e) {
        errorMsg = 'An unexpected error occurred'
      }
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchAccessPoint = async (apId) => {
    try {
      const response = await axios.get(`/access-points/${apId}`)
      // Handle both direct response and wrapped response
      return response.data.access_point || response.data
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.message || String(err) || 'Failed to fetch access point details'
      toast.error(errorMsg)
      throw err
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/access-points/statistics')
      const statsData = response.data?.stats || {}
      stats.value = {
        total: statsData.total || accessPoints.value.length,
        online: statsData.online || onlineAccessPoints.value.length,
        offline: statsData.offline || offlineAccessPoints.value.length,
        unknown: statsData.unknown || unknownAccessPoints.value.length,
        totalUsers: statsData.total_users || accessPoints.value.reduce((sum, ap) => sum + (ap.active_users || 0), 0)
      }
      return stats.value
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      // Fallback to computed stats
      updateStats()
      return stats.value
    }
  }

  const createAccessPoint = async (apData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/access-points', apData)
      
      // Handle both wrapped {access_point: ...} and direct response
      const newAp = response.data?.access_point || response.data
      
      // Add to local state (will be updated by event)
      if (newAp) {
        accessPoints.value.unshift(newAp)
        updateStats()
      }
      
      toast.success('Access point created successfully')
      return newAp
      
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to create access point'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateAccessPoint = async (apId, updates) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/access-points/${apId}`, updates)
      
      // Handle both wrapped {access_point: ...} and direct response
      const updatedAp = response.data?.access_point || response.data
      
      // Update local state (will be updated by event)
      if (updatedAp) {
        const index = accessPoints.value.findIndex(ap => ap.id === apId)
        if (index !== -1) {
          accessPoints.value[index] = { ...accessPoints.value[index], ...updatedAp }
          updateStats()
        }
      }
      
      toast.success('Access point updated successfully')
      return updatedAp
      
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to update access point'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteAccessPoint = async (apId) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/access-points/${apId}`)
      
      // Remove from local state (will be updated by event)
      accessPoints.value = accessPoints.value.filter(ap => ap.id !== apId)
      updateStats()
      
      toast.success('Access point deleted successfully')
      
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to delete access point'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const syncAccessPoint = async (apId) => {
    try {
      const response = await axios.post(`/access-points/${apId}/sync`)
      
      // Handle both wrapped and direct response
      const syncedAp = response.data?.access_point || response.data
      
      // Update local state
      if (syncedAp) {
        const index = accessPoints.value.findIndex(ap => ap.id === apId)
        if (index !== -1) {
          accessPoints.value[index] = { ...accessPoints.value[index], ...syncedAp }
          updateStats()
        }
      }
      
      toast.success('Access point synced successfully')
      return syncedAp
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to sync access point'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    }
  }

  // Utility functions
  const updateStats = () => {
    const online = accessPoints.value.filter(ap => ap.status === 'online').length
    const offline = accessPoints.value.filter(ap => ap.status === 'offline').length
    const unknown = accessPoints.value.filter(ap => ap.status === 'unknown' || !ap.status).length
    const totalUsers = accessPoints.value.reduce((sum, ap) => sum + (ap.active_users || 0), 0)
    
    stats.value = {
      total: accessPoints.value.length,
      online,
      offline,
      unknown,
      totalUsers
    }
  }

  const getAccessPointById = (id) => {
    return accessPoints.value.find(ap => ap.id === id)
  }

  const getAccessPointsByStatus = (status) => {
    return accessPoints.value.filter(ap => ap.status === status)
  }

  // Search and filter
  const searchAccessPoints = (query) => {
    if (!query) return accessPoints.value
    
    const lowercaseQuery = query.toLowerCase()
    return accessPoints.value.filter(ap => 
      ap.name?.toLowerCase().includes(lowercaseQuery) ||
      ap.ip_address?.toLowerCase().includes(lowercaseQuery) ||
      ap.mac_address?.toLowerCase().includes(lowercaseQuery) ||
      ap.serial_number?.toLowerCase().includes(lowercaseQuery) ||
      ap.location?.toLowerCase().includes(lowercaseQuery)
    )
  }

  // Event handlers for WebSocket updates
  const handleAccessPointCreated = (event) => {
    const apData = event.detail?.access_point
    if (!apData) return
    // Check if access point already exists
    const exists = accessPoints.value.some(ap => ap.id === apData.id)
    if (!exists) {
      accessPoints.value.unshift(apData)
      updateStats()
    }
  }

  const handleAccessPointUpdated = (event) => {
    const apData = event.detail?.access_point
    if (!apData) return
    const index = accessPoints.value.findIndex(ap => ap.id === apData.id)
    if (index !== -1) {
      accessPoints.value[index] = { ...accessPoints.value[index], ...apData }
      updateStats()
    }
  }

  const handleAccessPointDeleted = (event) => {
    const apId = event.detail?.accessPointId
    if (!apId) return
    accessPoints.value = accessPoints.value.filter(ap => ap.id !== apId)
    updateStats()
  }

  // Setup WebSocket event listeners - use named handlers
  const setupWebSocketListeners = () => {
    // Listen for custom events from Echo/Laravel WebSockets
    window.addEventListener('access-point-created', handleAccessPointCreated)
    window.addEventListener('access-point-updated', handleAccessPointUpdated)
    window.addEventListener('access-point-deleted', handleAccessPointDeleted)
    
    // Subscribe to Laravel Echo channel if available
    if (window.Echo && authStore.tenant?.id) {
      window.Echo.channel(`tenant.${authStore.tenant.id}`)
        .listen('.access-point-created', (e) => {
          handleAccessPointCreated({ detail: e })
        })
        .listen('.access-point-updated', (e) => {
          handleAccessPointUpdated({ detail: e })
        })
        .listen('.access-point-deleted', (e) => {
          handleAccessPointDeleted({ detail: e })
        })
    }
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('access-point-created', handleAccessPointCreated)
    window.removeEventListener('access-point-updated', handleAccessPointUpdated)
    window.removeEventListener('access-point-deleted', handleAccessPointDeleted)
    
    // Unsubscribe from Echo channel if available
    if (window.Echo && authStore.tenant?.id) {
      window.Echo.leaveChannel(`tenant.${authStore.tenant.id}`)
    }
  }

  return {
    // Reactive data
    accessPoints,
    stats,
    onlineAccessPoints,
    offlineAccessPoints,
    unknownAccessPoints,
    loading,
    error,

    // API functions
    fetchAccessPoints,
    fetchStatistics,
    createAccessPoint,
    updateAccessPoint,
    deleteAccessPoint,
    syncAccessPoint,
    fetchAccessPoint,

    // Utility functions
    getAccessPointById,
    getAccessPointsByStatus,
    searchAccessPoints,
    updateStats,

    // Event handlers
    handleAccessPointCreated,
    handleAccessPointUpdated,
    handleAccessPointDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
