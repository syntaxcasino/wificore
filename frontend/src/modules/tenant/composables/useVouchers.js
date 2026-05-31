import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'
import { readSnapshot, scheduleAfterPaint, writeSnapshot } from '@/modules/common/composables/performance/useViewCache'
import { useEventDeduplicationStore } from '@/stores/eventDeduplication'

export function useVouchers() {
  const toast = useToast()
  const dedupStore = useEventDeduplicationStore()
  const authStore = useAuthStore()
  const cacheKey = () => `tenant:vouchers:${authStore.user?.tenant_id || authStore.tenantId || 'default'}:v1`

  const hydrateSnapshot = () => {
    const snapshot = readSnapshot(cacheKey(), 30 * 1000)
    if (!snapshot || typeof snapshot !== 'object') return false
    if (Array.isArray(snapshot.vouchers)) vouchers.value = snapshot.vouchers
    if (Array.isArray(snapshot.packages)) packages.value = snapshot.packages
    if (snapshot.pagination && typeof snapshot.pagination === 'object') {
      pagination.value = { ...pagination.value, ...snapshot.pagination }
    }
    if (snapshot.stats && typeof snapshot.stats === 'object') {
      stats.value = { ...stats.value, ...snapshot.stats }
    }
    return true
  }

  const persistSnapshot = () => writeSnapshot(cacheKey(), {
    vouchers: vouchers.value,
    packages: packages.value,
    pagination: pagination.value,
    stats: stats.value,
  })

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

  // Active filters for server-side filtering
  const activeFilters = ref({
    search: '',
    status: '',
    package_id: ''
  })
  
  // Stats
  const stats = ref({
    total: 0,
    unused: 0,
    used: 0,
    expired: 0,
    revoked: 0
  })

  // Track pending optimistic updates for rollback
  const pendingUpdates = ref(new Map())

  // Computed stats for DataViewContainer
  const statsForView = computed(() => [
    { color: 'bg-emerald-500', value: stats.value.unused || 0, tooltip: 'Unused vouchers' },
    { color: 'bg-blue-500', value: stats.value.used || 0, tooltip: 'Used vouchers' },
    { color: 'bg-amber-500', value: stats.value.expired || 0, tooltip: 'Expired vouchers' },
    { color: 'bg-red-500', value: stats.value.revoked || 0, tooltip: 'Revoked vouchers' }
  ])

  // Actions
  const fetchPackages = async () => {
    if (packages.value.length === 0) {
      hydrateSnapshot()
      if (packages.value.length === 0) {
        scheduleAfterPaint(() => {
          if (packages.value.length === 0) {
            // Keep background loading only; the page can render without packages.
          }
        })
      }
    }
    try {
      const res = await axios.get('/packages')
      const data = res.data.data || res.data
      packages.value = Array.isArray(data) ? data : (data.data || [])
      persistSnapshot()
      return packages.value
    } catch (err) {
      console.error('Failed to fetch packages:', err)
      throw err
    }
  }

  const fetchStats = async () => {
    if (stats.value.total === 0) {
      hydrateSnapshot()
    }
    try {
      const res = await axios.get('/vouchers/stats')
      stats.value = res.data.data || {}
      persistSnapshot()
      return stats.value
    } catch (err) {
      console.error('Failed to fetch voucher stats:', err)
      throw err
    }
  }

  const fetchVouchers = async (params = {}) => {
    const isInitial = vouchers.value.length === 0
    if (isInitial && !hydrateSnapshot()) {
      scheduleAfterPaint(() => {
        if (vouchers.value.length === 0) loading.value = true
      })
    }
    loading.value = true
    error.value = null
    
    try {
      const queryParams = {
        page: params.page || pagination.value.currentPage,
        per_page: params.per_page || pagination.value.perPage,
      }

      const search = params.search !== undefined ? params.search : activeFilters.value.search
      const status = params.status !== undefined ? params.status : activeFilters.value.status
      const package_id = params.package_id !== undefined ? params.package_id : activeFilters.value.package_id

      if (search) queryParams.search = search
      if (status) queryParams.status = status
      if (package_id) queryParams.package_id = package_id
      
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
      persistSnapshot()
      
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

  const fetchVoucherDetails = async (voucherId) => {
    if (!voucherId) return null

    try {
      const res = await axios.get(`/vouchers/${voucherId}`)
      return res.data.data || res.data
    } catch (err) {
      console.error('Failed to fetch voucher details:', err)
      throw err
    }
  }

  const refreshVouchers = async () => {
    return fetchVouchers({ page: pagination.value.currentPage })
  }

  const setFilters = (filters) => {
    activeFilters.value = { ...activeFilters.value, ...filters }
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
      payload.expires_at = formData.expires_at || null
      if (formData.notes) payload.notes = formData.notes

      const response = await axios.post('/vouchers/generate', payload)
      const generated = response.data?.data?.vouchers || []
      toast.success(`Generated ${generated.length || formData.quantity} voucher(s)`)

      // IMMEDIATE UI UPDATE: Inject vouchers locally so they appear right away.
      // Also dispatch events so any listening components pick them up instantly.
      generated.forEach((voucher, idx) => {
        if (!voucher?.id) return
        // Add to list if not already present
        const exists = vouchers.value.some(v => v.id === voucher.id)
        if (!exists) {
          vouchers.value.unshift(voucher)
        }
        // Dispatch local event for redundancy (WebSocket may be delayed)
        if (typeof window !== 'undefined') {
          window.dispatchEvent(new CustomEvent('voucher-created', {
            detail: { voucher, timestamp: new Date().toISOString() }
          }))
        }
      })

      // Update stats immediately
      stats.value.unused = (stats.value.unused || 0) + generated.length
      stats.value.total = (stats.value.total || 0) + generated.length
      persistSnapshot()

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
    const index = vouchers.value.findIndex(v => v.id === voucher.id)
    if (index === -1) {
      toast.error('Voucher not found')
      return false
    }

    // No optimistic updates - let WebSocket events handle everything
    
    try {
      await axios.post(`/vouchers/${voucher.id}/revoke`)
      toast.success(`Voucher ${voucher.code} revoked`)

      // Purely event-driven - WebSocket events will handle the UI update automatically
      return true
    } catch (err) {
      const message = err.response?.data?.message || 'Failed to revoke voucher'
      toast.error(message)
      console.error('Error revoking voucher:', err)
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
  // Track last sync timestamp for catch-up on reconnect
  const lastSyncTimestamp = ref(null)

  // Helper: Check if new data is fresher than existing
  const isDataFresher = (newData, existingData) => {
    const newTime = newData.updated_at || newData.created_at || newData.timestamp
    const existingTime = existingData?.updated_at || existingData?.created_at

    if (!newTime && !existingTime) return true // No timestamps, accept new data
    if (!existingTime) return true // No existing timestamp, accept new data
    if (!newTime) return false // No new timestamp, keep existing

    return new Date(newTime).getTime() >= new Date(existingTime).getTime()
  }

  const handleVoucherCreated = (event) => {
    const voucherData = event.detail?.voucher || event.detail?.data?.voucher || event.detail
    const timestamp = event.detail?.timestamp || voucherData?.updated_at || voucherData?.created_at
    if (!voucherData?.id) return

    console.log('[Vouchers] Received voucher-created event:', voucherData.code)

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('voucher-created', voucherData.id, timestamp)) {
      console.log('[Vouchers] Duplicate voucher-created event ignored:', voucherData.code)
      return
    }

    // Check if voucher already exists (avoid duplicates from optimistic updates)
    const existingIndex = vouchers.value.findIndex(v => v.id === voucherData.id)
    if (existingIndex === -1) {
      vouchers.value.unshift(voucherData)
      stats.value.unused = (stats.value.unused || 0) + 1
      stats.value.total = (stats.value.total || 0) + 1
      console.log('[Vouchers] Added via event:', voucherData.code)
    } else {
      // Voucher exists, check if event data is fresher
      if (isDataFresher(voucherData, vouchers.value[existingIndex])) {
        const oldStatus = vouchers.value[existingIndex].status
        vouchers.value[existingIndex] = { ...vouchers.value[existingIndex], ...voucherData }

        // Update stats if status changed
        if (oldStatus !== voucherData.status) {
          if (stats.value[oldStatus] > 0) stats.value[oldStatus]--
          if (stats.value[voucherData.status] !== undefined) {
            stats.value[voucherData.status] = (stats.value[voucherData.status] || 0) + 1
          }
        }
        console.log('[Vouchers] Updated via create event (fresher data):', voucherData.code)
      } else {
        console.log('[Vouchers] Ignored stale create event for:', voucherData.code)
      }
    }

    lastSyncTimestamp.value = new Date().toISOString()
  }

  const handleVoucherUpdated = (event) => {
    const voucherData = event.detail?.voucher || event.detail?.data?.voucher || event.detail
    const timestamp = event.detail?.timestamp || voucherData?.updated_at
    if (!voucherData?.id) return

    console.log('[Vouchers] Received voucher-updated event:', voucherData.code)

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('voucher-updated', voucherData.id, timestamp)) {
      console.log('[Vouchers] Duplicate voucher-updated event ignored:', voucherData.code)
      return
    }

    const index = vouchers.value.findIndex(v => v.id === voucherData.id)
    if (index !== -1) {
      // Prevent stale data overwrites - compare timestamps
      if (isDataFresher(voucherData, vouchers.value[index])) {
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
      } else {
        console.log('[Vouchers] Ignored stale update event for:', voucherData.code)
      }
    } else {
      // Voucher not in list, add them (might have been created while offline)
      vouchers.value.unshift(voucherData)
      stats.value.unused = (stats.value.unused || 0) + 1
      stats.value.total = (stats.value.total || 0) + 1
      console.log('[Vouchers] Added via update event (was missing):', voucherData.code)
    }

    lastSyncTimestamp.value = new Date().toISOString()
  }

  const handleVoucherDeleted = (event) => {
    const voucherId = event.detail?.voucherId || event.detail?.voucher?.id || event.detail?.id
    const timestamp = event.detail?.timestamp
    if (!voucherId) return

    console.log('[Vouchers] Received voucher-deleted event for ID:', voucherId)

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('voucher-deleted', voucherId, timestamp)) {
      console.log('[Vouchers] Duplicate voucher-deleted event ignored:', voucherId)
      return
    }

    const voucher = vouchers.value.find(v => v.id === voucherId)
    if (voucher && stats.value[voucher.status] > 0) {
      stats.value[voucher.status]--
      stats.value.total = Math.max(0, (stats.value.total || 0) - 1)
      console.log('[Vouchers] Deleted via event:', voucher.code || `ID ${voucherId}`)
    } else {
      console.log('[Vouchers] Voucher not found for deletion, ID:', voucherId)
    }

    vouchers.value = vouchers.value.filter(v => v.id !== voucherId)
    lastSyncTimestamp.value = new Date().toISOString()
  }

  // Catch-up fetch for reconnects - fetch data changed since last sync
  const catchUpFetch = async () => {
    if (!lastSyncTimestamp.value) {
      // No last sync, do full fetch
      return fetchVouchers()
    }

    try {
      console.log('[Vouchers] Running catch-up fetch since:', lastSyncTimestamp.value)
      const response = await axios.get('/vouchers', {
        params: { since: lastSyncTimestamp.value }
      })
      const data = response.data.data || response.data

      if (Array.isArray(data)) {
        // Merge catch-up data with existing
        data.forEach(voucher => {
          const index = vouchers.value.findIndex(v => v.id === voucher.id)
          if (index === -1) {
            vouchers.value.push(voucher)
          } else if (isDataFresher(voucher, vouchers.value[index])) {
            vouchers.value[index] = { ...vouchers.value[index], ...voucher }
          }
        })
      }

      lastSyncTimestamp.value = new Date().toISOString()
      await fetchStats() // Refresh stats after catch-up
    } catch (err) {
      console.error('[Vouchers] Catch-up fetch failed:', err)
      // Fall back to full fetch
      return fetchVouchers()
    }
  }

  // Handle WebSocket reconnect - catch up on missed data
  const handleWebSocketReconnect = () => {
    console.log('[Vouchers] WebSocket reconnected, running catch-up fetch')
    catchUpFetch()
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('voucher-created', handleVoucherCreated)
    window.addEventListener('voucher-updated', handleVoucherUpdated)
    window.addEventListener('voucher-deleted', handleVoucherDeleted)
    window.addEventListener('websocket-reconnected', handleWebSocketReconnect)
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('voucher-created', handleVoucherCreated)
    window.removeEventListener('voucher-updated', handleVoucherUpdated)
    window.removeEventListener('voucher-deleted', handleVoucherDeleted)
    window.removeEventListener('websocket-reconnected', handleWebSocketReconnect)
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
    lastSyncTimestamp,
    pendingUpdates,
    activeFilters,

    // Computed
    statsForView,

    // Actions
    fetchPackages,
    fetchStats,
    fetchVouchers,
    fetchVoucherDetails,
    refreshVouchers,
    goToPage,
    generateVouchers,
    revokeVoucher,
    filterVouchers,
    setFilters,

    // Helpers
    statusClass,
    formatDate,
    getPackageById,
    calculateTotalValue,

    // WebSocket
    setupWebSocketListeners,
    cleanupWebSocketListeners,
    catchUpFetch,
    isDataFresher
  }
}
