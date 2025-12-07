/**
 * Position Management Composable - Event-Driven
 * WiFi Hotspot System - HR Module
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useToast } from '@/composables/useToast'

export function usePositions() {
  const loading = ref(false)
  const error = ref(null)
  const positions = ref([])
  const stats = ref({
    "total": 0,
    "active": 0,
    "inactive": 0,
    "by_level": [],
    "by_department": []
})
  
  const { toast } = useToast()

  // Computed filters
  
  const activePositions = computed(() => 
    positions.value.filter(item => item.status === 'active')
  )
  
  const inactivePositions = computed(() => 
    positions.value.filter(item => item.status === 'inactive')
  )

  // API Functions
  const fetchPositions = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/positions?${params}` : '/positions'
      const response = await axios.get(url)
      
      positions.value = response.data.data || response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch positions'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/positions/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createPosition = async (data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/positions', data)
      
      positions.value.unshift(response.data.data)
      toast.success('Position created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create position'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updatePosition = async (id, data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/positions/${id}`, data)
      
      const index = positions.value.findIndex(item => item.id === id)
      if (index !== -1) {
        positions.value[index] = response.data.data
      }
      
      toast.success('Position updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update position'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deletePosition = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/positions/${id}`)
      
      positions.value = positions.value.filter(item => item.id !== id)
      toast.success('Position deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete position'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }


  const getPositionById = (id) => {
    return positions.value.find(item => item.id === id)
  }

  const searchPositions = (query) => {
    const lowerQuery = query.toLowerCase()
    return positions.value.filter(item => 
      item.title?.toLowerCase().includes(lowerQuery) ||
      item.code?.toLowerCase().includes(lowerQuery) ||
      item.description?.toLowerCase().includes(lowerQuery)
    )
  }

  // Event handlers for WebSocket
  const handlePositionCreated = (position) => {
    const exists = positions.value.find(item => item.id === position.id)
    if (!exists) {
      positions.value.unshift(position)
    }
  }

  const handlePositionUpdated = (position) => {
    const index = positions.value.findIndex(item => item.id === position.id)
    if (index !== -1) {
      positions.value[index] = { ...positions.value[index], ...position }
    }
  }

  const handlePositionDeleted = (positionId) => {
    positions.value = positions.value.filter(item => item.id !== positionId)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('position-created', (event) => {
      if (event.detail?.position) {
        handlePositionCreated(event.detail.position)
      }
    })

    window.addEventListener('position-updated', (event) => {
      if (event.detail?.position) {
        handlePositionUpdated(event.detail.position)
      }
    })

    window.addEventListener('position-deleted', (event) => {
      if (event.detail?.positionId) {
        handlePositionDeleted(event.detail.positionId)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('position-created', handlePositionCreated)
    window.removeEventListener('position-updated', handlePositionUpdated)
    window.removeEventListener('position-deleted', handlePositionDeleted)
  }

  return {
    // Reactive data
    positions,
    stats,
    activePositions,
    inactivePositions,
    loading,
    error,

    // API functions
    fetchPositions,
    fetchStatistics,
    createPosition,
    updatePosition,
    deletePosition,
    ,

    // Utility functions
    getPositionById,
    searchPositions,

    // Event handlers
    handlePositionCreated,
    handlePositionUpdated,
    handlePositionDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
