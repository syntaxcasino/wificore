import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/stores/auth'
import Echo from 'laravel-echo'

/**
 * Composable for managing Hotspot users, sessions, and real-time updates.
 * 
 * Features:
 * - Fetch hotspot users with pagination and filtering
 * - Real-time WebSocket updates (no polling)
 * - Disconnect users via queued jobs
 * - Track provisioning status
 */
export function useHotspot() {
  const authStore = useAuthStore()
  
  // State
  const users = ref([])
  const sessions = ref([])
  const loading = ref(false)
  const error = ref(null)
  const pagination = ref({
    currentPage: 1,
    lastPage: 1,
    perPage: 15,
    total: 0,
  })
  
  // Provisioning state
  const provisioningStatus = ref({})
  
  // WebSocket subscription reference
  let echoChannel = null
  
  // Computed
  const activeUsers = computed(() => 
    Array.isArray(users.value) ? users.value.filter(u => u.status === 'active' && u.has_active_subscription) : []
  )
  
  const expiredUsers = computed(() => 
    Array.isArray(users.value) ? users.value.filter(u => u.status === 'expired' || !u.has_active_subscription) : []
  )
  
  const totalUsers = computed(() => pagination.value.total)
  
  const activeSessions = computed(() => 
    Array.isArray(sessions.value) ? sessions.value.filter(s => s.status === 'active') : []
  )
  
  /**
   * Fetch hotspot users from API
   */
  async function fetchUsers(params = {}) {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.get('/hotspot/users', {
        params: {
          page: pagination.value.currentPage,
          per_page: pagination.value.perPage,
          ...params,
        },
      })
      
      const data = response.data
      users.value = data.data || data
      
      if (data.meta) {
        pagination.value = {
          currentPage: data.meta.current_page,
          lastPage: data.meta.last_page,
          perPage: data.meta.per_page,
          total: data.meta.total,
        }
      }
      
      return users.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch hotspot users'
      console.error('Error fetching hotspot users:', err)
      throw err
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Fetch active sessions
   */
  async function fetchSessions(params = {}) {
    try {
      const response = await axios.get('/hotspot/sessions', { params })
      sessions.value = response.data.data || response.data
      return sessions.value
    } catch (err) {
      console.error('Error fetching sessions:', err)
      throw err
    }
  }
  
  /**
   * Disconnect a hotspot user (queued job)
   */
  async function disconnectUser(userId, reason = 'Admin disconnect') {
    try {
      const response = await axios.post(`/hotspot/users/${userId}/disconnect`, {
        reason,
      })
      
      // Optimistically update local state
      const userIndex = users.value.findIndex(u => u.id === userId)
      if (userIndex !== -1) {
        users.value[userIndex].status = 'disconnecting'
      }
      
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to disconnect user'
      throw err
    }
  }
  
  /**
   * Grant access to a user (e.g., after manual payment verification)
   */
  async function grantAccess(userId, packageId = null) {
    try {
      const response = await axios.post(`/hotspot/users/${userId}/grant-access`, {
        package_id: packageId,
      })
      
      const updatedUser = response.data?.data
      
      // Optimistically update the local list immediately for better UX
      if (updatedUser) {
        const index = users.value.findIndex(u => u.id === userId)
        if (index !== -1) {
          users.value.splice(index, 1, { ...users.value[index], ...updatedUser })
        }
      }
      
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to grant access'
      throw err
    }
  }
  
  /**
   * Revoke access from a user
   */
  async function revokeAccess(userId, reason = 'Admin revoked') {
    try {
      const response = await axios.post(`/hotspot/users/${userId}/revoke-access`, {
        reason,
      })
      
      const updatedUser = response.data?.data
      
      // Optimistically update the local list immediately for better UX
      if (updatedUser) {
        const index = users.value.findIndex(u => u.id === userId)
        if (index !== -1) {
          users.value.splice(index, 1, { ...users.value[index], ...updatedUser })
        }
      }
      
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to revoke access'
      throw err
    }
  }
  
  /**
   * Get user details with sessions
   */
  async function getUserDetails(userId) {
    try {
      const response = await axios.get(`/hotspot/users/${userId}`)
      return response.data.data?.user || response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch user details'
      throw err
    }
  }

  /**
   * Create a new hotspot user (admin-initiated)
   */
  async function createUser(payload) {
    try {
      const response = await axios.post('/hotspot/users', payload)
      const newUser = response.data?.data
      if (newUser) {
        users.value.unshift(newUser)
        pagination.value.total += 1
      }
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create hotspot user'
      throw err
    }
  }

  /**
   * Update an existing hotspot user
   */
  async function updateUser(userId, payload) {
    try {
      const response = await axios.put(`/hotspot/users/${userId}`, payload)
      const updated = response.data?.data
      if (updated) {
        const idx = users.value.findIndex(u => u.id === userId)
        if (idx !== -1) users.value.splice(idx, 1, { ...users.value[idx], ...updated })
      }
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update hotspot user'
      throw err
    }
  }

  /**
   * Delete a hotspot user
   */
  async function deleteUser(userId) {
    loading.value = true
    error.value = null

    // Optimistically remove from local list immediately for better UX
    const deletedUserIndex = users.value.findIndex(u => u.id === userId)
    const deletedUser = deletedUserIndex !== -1 ? users.value[deletedUserIndex] : null
    if (deletedUserIndex !== -1) {
      users.value.splice(deletedUserIndex, 1)
      pagination.value.total -= 1
    }

    try {
      const response = await axios.delete(`/hotspot/users/${userId}`)
      return response.data
    } catch (err) {
      // Restore user on error
      if (deletedUser) {
        users.value.splice(deletedUserIndex, 0, deletedUser)
        pagination.value.total += 1
      }
      error.value = err.response?.data?.message || 'Failed to delete hotspot user'
      throw err
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Subscribe to WebSocket channels for real-time updates
   */
  function subscribeToWebSocket() {
    const tenantId = authStore.user?.tenant_id
    if (typeof window === 'undefined' || !tenantId || !window.Echo) {
      console.warn('WebSocket not available or no tenant context')
      return
    }
    
    if (echoChannel) {
      return
    }

    // Subscribe to hotspot channel
    echoChannel = window.Echo.private(`tenant.${tenantId}.hotspot`)
      .listen('.hotspot.access.granted', (event) => {
        console.log('Hotspot access granted:', event)
        handleAccessGranted(event)
      })
      .listen('.hotspot.access.revoked', (event) => {
        console.log('Hotspot access revoked:', event)
        handleAccessRevoked(event)
      })
      .listen('.hotspot.package.expired', (event) => {
        console.log('Hotspot package expired:', event)
        handlePackageExpired(event)
      })
      .listen('.hotspot.login.attempted', (event) => {
        console.log('Hotspot login attempted:', event)
        handleLoginAttempted(event)
      })
      .listen('.hotspot.provisioned', (event) => {
        console.log('Hotspot provisioned:', event)
        handleProvisioned(event)
      })
      .listen('.hotspot.provision.requested', (event) => {
        console.log('Hotspot provision requested:', event)
        handleProvisionRequested(event)
      })
    
    // Subscribe to hotspot-users channel for user creation events
    window.Echo.private(`tenant.${tenantId}.hotspot-users`)
      .listen('.HotspotUserCreated', (event) => {
        console.log('Hotspot user created:', event)
        handleUserCreated(event)
      })
      .listen('.HotspotUserUpdated', (event) => {
        console.log('Hotspot user updated:', event)
        handleUserUpdated(event)
      })
      .listen('.HotspotUserDeleted', (event) => {
        console.log('Hotspot user deleted:', event)
        handleUserDeleted(event)
      })
    
    // Subscribe to dashboard-stats channel for session expiration events
    window.Echo.private(`tenant.${tenantId}.dashboard-stats`)
      .listen('.SessionExpired', (event) => {
        console.log('Session expired:', event)
        handleSessionExpired(event)
      })
    
    console.log(`Subscribed to hotspot WebSocket channels for tenant ${tenantId}`)
  }
  
  /**
   * Unsubscribe from WebSocket channels
   */
  function unsubscribeFromWebSocket() {
    const tenantId = authStore.user?.tenant_id
    if (typeof window === 'undefined' || !tenantId || !window.Echo) return
    
    window.Echo.leave(`tenant.${tenantId}.hotspot`)
    window.Echo.leave(`tenant.${tenantId}.hotspot-users`)
    window.Echo.leave(`tenant.${tenantId}.dashboard-stats`)
    echoChannel = null
    
    console.log('Unsubscribed from hotspot WebSocket channels')
  }
  
  // WebSocket event handlers
  function handleAccessGranted(event) {
    const userIndex = users.value.findIndex(u => u.id === event.user_id)
    if (userIndex !== -1) {
      users.value[userIndex] = {
        ...users.value[userIndex],
        status: 'active',
        has_active_subscription: true,
        subscription_expires_at: event.expires_at,
        package_name: event.package_name,
      }
    }
  }
  
  function handleAccessRevoked(event) {
    const userIndex = users.value.findIndex(u => u.id === event.user_id)
    if (userIndex !== -1) {
      users.value[userIndex] = {
        ...users.value[userIndex],
        status: 'revoked',
        has_active_subscription: false,
      }
    }
  }
  
  function handlePackageExpired(event) {
    const userIndex = users.value.findIndex(u => u.id === event.user_id)
    if (userIndex !== -1) {
      users.value[userIndex] = {
        ...users.value[userIndex],
        status: 'expired',
        has_active_subscription: false,
      }
    }
  }
  
  function handleLoginAttempted(event) {
    // Emit custom event for UI notification
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('hotspot:login-attempted', { detail: event }))
    }
  }
  
  function handleUserCreated(event) {
    // Add new user to the list if not already present
    const exists = users.value.some(u => u.id === event.user?.id)
    if (!exists && event.user) {
      users.value.unshift(event.user)
      pagination.value.total += 1
    }
  }

  function handleUserUpdated(event) {
    // Update existing user in the list
    const index = users.value.findIndex(u => u.id === event.user?.id)
    if (index !== -1) {
      users.value.splice(index, 1, { ...users.value[index], ...event.user })
    }
  }

  function handleUserDeleted(event) {
    // Remove user from the list
    const index = users.value.findIndex(u => u.id === event.user_id)
    if (index !== -1) {
      users.value.splice(index, 1)
      pagination.value.total -= 1
    }
  }
  
  function handleProvisioned(event) {
    provisioningStatus.value[event.service_id] = {
      success: event.success,
      error: event.error,
      status: event.status,
      timestamp: event.timestamp,
    }
    
    if (typeof window !== 'undefined') {
      window.dispatchEvent(new CustomEvent('hotspot:provisioned', { detail: event }))
    }
  }
  
  function handleProvisionRequested(event) {
    provisioningStatus.value[event.service_id] = {
      status: 'pending',
      timestamp: event.timestamp,
    }
  }
  
  // Session event handlers (reserved for future backend implementation)
  function handleSessionExpired(event) {
    // Remove expired session from the list
    const sessionId = event.session?.id
    const username = event.session?.username
    if (sessionId) {
      const index = sessions.value.findIndex(s => s.id === sessionId)
      if (index !== -1) {
        sessions.value.splice(index, 1)
      }
    } else if (username) {
      // Fallback: remove by username if id not available
      const index = sessions.value.findIndex(s => s.username === username)
      if (index !== -1) {
        sessions.value.splice(index, 1)
      }
    }
  }
  
  function handleSessionSync(event) {
    // Reserved for future backend implementation
    // Full session list sync from server
    if (event.sessions) {
      sessions.value = event.sessions
    }
  }
  
  /**
   * Change page
   */
  function setPage(page) {
    pagination.value.currentPage = page
    fetchUsers()
  }
  
  /**
   * Change items per page
   */
  function setPerPage(perPage) {
    pagination.value.perPage = perPage
    pagination.value.currentPage = 1
    fetchUsers()
  }
  
  // Lifecycle
  onMounted(() => {
    if (typeof window !== 'undefined') {
      subscribeToWebSocket()
    }
  })
  
  onUnmounted(() => {
    if (typeof window !== 'undefined') {
      unsubscribeFromWebSocket()
    }
  })
  
  // Watch for auth changes
  watch(() => authStore.user?.tenant_id, (newTenantId, oldTenantId) => {
    if (newTenantId !== oldTenantId) {
      unsubscribeFromWebSocket()
      if (newTenantId) {
        subscribeToWebSocket()
      }
    }
  })
  
  // Package options for filter dropdowns
  const packages = ref([])
  const fetchPackages = async () => {
    try {
      const response = await axios.get('/hotspot/packages', { params: { per_page: 100 } })
      packages.value = response.data?.packages?.data || response.data?.packages || []
    } catch (err) {
      console.error('fetchPackages error:', err)
    }
  }

  return {
    // State
    users,
    sessions,
    packages,
    loading,
    error,
    pagination,
    provisioningStatus,
    
    // Computed
    activeUsers,
    expiredUsers,
    totalUsers,
    activeSessions,
    
    // Methods
    fetchUsers,
    fetchSessions,
    fetchPackages,
    disconnectUser,
    grantAccess,
    revokeAccess,
    getUserDetails,
    createUser,
    updateUser,
    deleteUser,
    setPage,
    setPerPage,
    subscribeToWebSocket,
    unsubscribeFromWebSocket,
  }
}

/**
 * Composable for Hotspot provisioning management
 */
export function useHotspotProvisioning() {
  const authStore = useAuthStore()
  
  const deployments = ref([])
  const loading = ref(false)
  const error = ref(null)
  
  /**
   * Deploy hotspot to a router
   */
  async function deployHotspot(routerId, config = {}) {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/routers/${routerId}/services/hotspot/deploy`, config)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to deploy hotspot'
      throw err
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Get deployment status
   */
  async function getDeploymentStatus(serviceId) {
    try {
      const response = await axios.get(`/services/${serviceId}/status`)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to get deployment status'
      throw err
    }
  }
  
  /**
   * Undeploy hotspot from a router
   */
  async function undeployHotspot(routerId, serviceId) {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.delete(`/routers/${routerId}/services/${serviceId}`)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to undeploy hotspot'
      throw err
    } finally {
      loading.value = false
    }
  }
  
  return {
    deployments,
    loading,
    error,
    deployHotspot,
    getDeploymentStatus,
    undeployHotspot,
  }
}

export default useHotspot
