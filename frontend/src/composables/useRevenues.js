/**
 * Revenue Management Composable - Event-Driven
 * WiFi Hotspot System - Finance Module
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useToast } from '@/composables/useToast'

export function useRevenues() {
  const loading = ref(false)
  const error = ref(null)
  const revenues = ref([])
  const stats = ref({
    "total_revenues": 0,
    "total_amount": 0,
    "by_status": {},
    "by_source": [],
    "by_payment_method": []
})
  
  const { toast } = useToast()

  // Computed filters
  
  const pendingRevenues = computed(() => 
    revenues.value.filter(item => item.status === 'pending')
  )
  
  const confirmedRevenues = computed(() => 
    revenues.value.filter(item => item.status === 'confirmed')
  )
  
  const cancelledRevenues = computed(() => 
    revenues.value.filter(item => item.status === 'cancelled')
  )

  // API Functions
  const fetchRevenues = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/revenues?${params}` : '/revenues'
      const response = await axios.get(url)
      
      revenues.value = response.data.data || response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch revenues'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/revenues/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createRevenue = async (data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/revenues', data)
      
      revenues.value.unshift(response.data.data)
      toast.success('Revenue created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create revenue'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateRevenue = async (id, data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/revenues/${id}`, data)
      
      const index = revenues.value.findIndex(item => item.id === id)
      if (index !== -1) {
        revenues.value[index] = response.data.data
      }
      
      toast.success('Revenue updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update revenue'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteRevenue = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/revenues/${id}`)
      
      revenues.value = revenues.value.filter(item => item.id !== id)
      toast.success('Revenue deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete revenue'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const confirmRevenue = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/revenues/${id}/confirm`, data)
      
      const index = revenues.value.findIndex(item => item.id === id)
      if (index !== -1) {
        revenues.value[index] = response.data.data
      }
      
      toast.success('Revenue confirm successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to confirm revenue'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const cancelRevenue = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/revenues/${id}/cancel`, data)
      
      const index = revenues.value.findIndex(item => item.id === id)
      if (index !== -1) {
        revenues.value[index] = response.data.data
      }
      
      toast.success('Revenue cancel successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to cancel revenue'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const getRevenueById = (id) => {
    return revenues.value.find(item => item.id === id)
  }

  const searchRevenues = (query) => {
    const lowerQuery = query.toLowerCase()
    return revenues.value.filter(item => 
      item.revenue_number?.toLowerCase().includes(lowerQuery) ||
      item.description?.toLowerCase().includes(lowerQuery) ||
      item.reference_number?.toLowerCase().includes(lowerQuery)
    )
  }

  // Event handlers for WebSocket
  const handleRevenueCreated = (revenue) => {
    const exists = revenues.value.find(item => item.id === revenue.id)
    if (!exists) {
      revenues.value.unshift(revenue)
    }
  }

  const handleRevenueUpdated = (revenue) => {
    const index = revenues.value.findIndex(item => item.id === revenue.id)
    if (index !== -1) {
      revenues.value[index] = { ...revenues.value[index], ...revenue }
    }
  }

  const handleRevenueDeleted = (revenueId) => {
    revenues.value = revenues.value.filter(item => item.id !== revenueId)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('revenue-created', (event) => {
      if (event.detail?.revenue) {
        handleRevenueCreated(event.detail.revenue)
      }
    })

    window.addEventListener('revenue-updated', (event) => {
      if (event.detail?.revenue) {
        handleRevenueUpdated(event.detail.revenue)
      }
    })

    window.addEventListener('revenue-deleted', (event) => {
      if (event.detail?.revenueId) {
        handleRevenueDeleted(event.detail.revenueId)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('revenue-created', handleRevenueCreated)
    window.removeEventListener('revenue-updated', handleRevenueUpdated)
    window.removeEventListener('revenue-deleted', handleRevenueDeleted)
  }

  return {
    // Reactive data
    revenues,
    stats,
    pendingRevenues,
    confirmedRevenues,
    cancelledRevenues,
    loading,
    error,

    // API functions
    fetchRevenues,
    fetchStatistics,
    createRevenue,
    updateRevenue,
    deleteRevenue,
    confirmRevenue,
    cancelRevenue,

    // Utility functions
    getRevenueById,
    searchRevenues,

    // Event handlers
    handleRevenueCreated,
    handleRevenueUpdated,
    handleRevenueDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
