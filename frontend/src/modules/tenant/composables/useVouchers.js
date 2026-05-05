import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useToast } from '@/modules/common/composables/useToast'

export function useVouchers() {
  const toast = useToast()

  // State
  const vouchers = ref([])
  const packages = ref([])
  const loading = ref(false)
  const error = ref(null)
  const generating = ref(false)
  const generateError = ref(null)
  
  // Pagination
  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    from: 0,
    to: 0,
    total: 0
  })
  
  // Stats
  const stats = ref({
    total: 0,
    unused: 0,
    used: 0,
    expired: 0,
    revoked: 0
  })

  // Computed stats for DataViewContainer
  const statsForView = computed(() => [
    { color: 'bg-emerald-500', value: stats.value.unused || 0, tooltip: 'Unused vouchers' },
    { color: 'bg-blue-500', value: stats.value.used || 0, tooltip: 'Used vouchers' },
    { color: 'bg-amber-500', value: stats.value.expired || 0, tooltip: 'Expired vouchers' },
    { color: 'bg-red-500', value: stats.value.revoked || 0, tooltip: 'Revoked vouchers' }
  ])

  // Actions
  const fetchPackages = async () => {
    try {
      const res = await axios.get('/packages')
      const data = res.data.data || res.data
      packages.value = Array.isArray(data) ? data : (data.data || [])
      return packages.value
    } catch (err) {
      console.error('Failed to fetch packages:', err)
      throw err
    }
  }

  const fetchStats = async () => {
    try {
      const res = await axios.get('/vouchers/stats')
      stats.value = res.data.data || {}
      return stats.value
    } catch (err) {
      console.error('Failed to fetch voucher stats:', err)
      throw err
    }
  }

  const fetchVouchers = async (params = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const queryParams = {
        page: params.page || pagination.value.currentPage,
        per_page: params.per_page || pagination.value.perPage,
        ...params
      }
      
      const res = await axios.get('/vouchers', { params: queryParams })
      const data = res.data.data || res.data
      
      if (data.data) {
        vouchers.value = data.data
        pagination.value = {
          currentPage: data.current_page || 1,
          lastPage: data.last_page || 1,
          perPage: data.per_page || 25,
          from: data.from || 0,
          to: data.to || 0,
          total: data.total || 0
        }
      } else if (Array.isArray(data)) {
        vouchers.value = data
      }
      
      return vouchers.value
    } catch (err) {
      if (err.response?.status === 401) return
      error.value = err.response?.data?.message || 'Failed to load vouchers'
      console.error('Error fetching vouchers:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  const refreshVouchers = async () => {
    return fetchVouchers({ page: pagination.value.currentPage })
  }

  const goToPage = async (page) => {
    if (page < 1 || page > pagination.value.lastPage) return
    pagination.value.currentPage = page
    await fetchVouchers({ page })
  }

  const generateVouchers = async (formData) => {
    if (!formData.package_id || !formData.quantity) {
      generateError.value = 'Package and quantity are required'
      return false
    }
    
    generating.value = true
    generateError.value = null
    
    try {
      const payload = {
        package_id: formData.package_id,
        quantity: formData.quantity,
      }
      if (formData.prefix) payload.prefix = formData.prefix
      if (formData.expires_at) payload.expires_at = formData.expires_at
      if (formData.notes) payload.notes = formData.notes
      
      await axios.post('/vouchers/generate', payload)
      toast.success(`Generated ${formData.quantity} voucher(s)`)
      await fetchVouchers()
      await fetchStats()
      return true
    } catch (err) {
      generateError.value = err.response?.data?.message || 'Failed to generate vouchers'
      toast.error(generateError.value)
      return false
    } finally {
      generating.value = false
    }
  }

  const revokeVoucher = async (voucher) => {
    try {
      await axios.post(`/vouchers/${voucher.id}/revoke`)
      toast.success(`Voucher ${voucher.code} revoked`)
      await fetchVouchers({ page: pagination.value.currentPage })
      await fetchStats()
      return true
    } catch (err) {
      const message = err.response?.data?.message || 'Failed to revoke voucher'
      toast.error(message)
      return false
    }
  }

  // Filtering
  const filterVouchers = (query, filters = {}) => {
    let result = [...vouchers.value]
    
    if (query) {
      const q = query.toLowerCase()
      result = result.filter(v => v.code?.toLowerCase().includes(q))
    }
    
    if (filters.status) {
      result = result.filter(v => v.status === filters.status)
    }
    
    if (filters.package_id) {
      result = result.filter(v => v.package_id === filters.package_id || v.package?.id === filters.package_id)
    }
    
    return result
  }

  // Helpers
  const statusClass = (status) => {
    const map = {
      unused: 'bg-green-100 text-green-700',
      used: 'bg-blue-100 text-blue-700',
      expired: 'bg-yellow-100 text-yellow-700',
      revoked: 'bg-red-100 text-red-700',
    }
    return map[status] || 'bg-gray-100 text-gray-700'
  }

  const formatDate = (d) => {
    if (!d) return ''
    return new Date(d).toLocaleString('en-US', { 
      month: 'short', 
      day: 'numeric', 
      year: 'numeric', 
      hour: '2-digit', 
      minute: '2-digit' 
    })
  }

  const getPackageById = (id) => packages.value.find(p => p.id === id)
  
  const calculateTotalValue = (pkg, qty) => {
    if (!pkg || !qty) return 0
    return (pkg.price || 0) * qty
  }

  // WebSocket event handlers for real-time updates
  const handleVoucherCreated = (event) => {
    const voucherData = event.detail?.voucher || event.detail?.data?.voucher || event.detail
    if (!voucherData?.id) return

    // Check if voucher already exists (avoid duplicates from optimistic updates)
    const exists = vouchers.value.some(v => v.id === voucherData.id)
    if (!exists) {
      vouchers.value.unshift(voucherData)
      stats.value.unused = (stats.value.unused || 0) + 1
      stats.value.total = (stats.value.total || 0) + 1
      console.log('[Vouchers] Added via event:', voucherData.code)
    }
  }

  const handleVoucherUpdated = (event) => {
    const voucherData = event.detail?.voucher || event.detail?.data?.voucher || event.detail
    if (!voucherData?.id) return

    const index = vouchers.value.findIndex(v => v.id === voucherData.id)
    if (index !== -1) {
      const oldStatus = vouchers.value[index].status
      vouchers.value[index] = { ...vouchers.value[index], ...voucherData }

      // Update stats if status changed
      if (oldStatus !== voucherData.status) {
        if (stats.value[oldStatus] > 0) stats.value[oldStatus]--
        if (stats.value[voucherData.status] !== undefined) {
          stats.value[voucherData.status] = (stats.value[voucherData.status] || 0) + 1
        }
      }
      console.log('[Vouchers] Updated via event:', voucherData.code)
    }
  }

  const handleVoucherDeleted = (event) => {
    const voucherId = event.detail?.voucherId || event.detail?.voucher?.id || event.detail?.id
    if (!voucherId) return

    const voucher = vouchers.value.find(v => v.id === voucherId)
    if (voucher && stats.value[voucher.status] > 0) {
      stats.value[voucher.status]--
      stats.value.total = Math.max(0, (stats.value.total || 0) - 1)
    }

    vouchers.value = vouchers.value.filter(v => v.id !== voucherId)
    console.log('[Vouchers] Deleted via event:', voucherId)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('voucher-created', handleVoucherCreated)
    window.addEventListener('voucher-updated', handleVoucherUpdated)
    window.addEventListener('voucher-deleted', handleVoucherDeleted)
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('voucher-created', handleVoucherCreated)
    window.removeEventListener('voucher-updated', handleVoucherUpdated)
    window.removeEventListener('voucher-deleted', handleVoucherDeleted)
  }

  return {
    // State
    vouchers,
    packages,
    loading,
    error,
    generating,
    generateError,
    pagination,
    stats,

    // Computed
    statsForView,

    // Actions
    fetchPackages,
    fetchStats,
    fetchVouchers,
    refreshVouchers,
    goToPage,
    generateVouchers,
    revokeVoucher,
    filterVouchers,

    // Helpers
    statusClass,
    formatDate,
    getPackageById,
    calculateTotalValue,

    // WebSocket
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
