import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import axios from 'axios'
import { useAuthStore } from '@/modules/common/stores/authStore'
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
  let sessionsChannel = null
  
  // Computed
  const activeUsers = computed(() => 
    users.value.filter(u => u.status === 'active' && u.has_active_subscription)
  )
  
  const expiredUsers = computed(() => 
    users.value.filter(u => u.status === 'expired' || !u.has_active_subscription)
  )
  
  const totalUsers = computed(() => pagination.value.total)
  
  const activeSessions = computed(() => 
    sessions.value.filter(s => s.status === 'active')
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
      return response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch user details'
      throw err
    }
  }
  
  /**
   * Subscribe to WebSocket channels for real-time updates
   */
  function subscribeToWebSocket() {
    const tenantId = authStore.user?.tenant_id
    if (!tenantId || !window.Echo) {
      console.warn('WebSocket not available or no tenant context')
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
    
    console.log(`Subscribed to hotspot WebSocket channels for tenant ${tenantId}`)
  }
  
  /**
   * Unsubscribe from WebSocket channels
   */
  function unsubscribeFromWebSocket() {
    const tenantId = authStore.user?.tenant_id
    if (!tenantId || !window.Echo) return
    
    window.Echo.leave(`tenant.${tenantId}.hotspot`)
    window.Echo.leave(`tenant.${tenantId}.hotspot-users`)
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
    window.dispatchEvent(new CustomEvent('hotspot:login-attempted', { detail: event }))
  }
  
  function handleUserCreated(event) {
    // Add new user to the list if not already present
    const exists = users.value.some(u => u.id === event.user?.id)
    if (!exists && event.user) {
      users.value.unshift(event.user)
      pagination.value.total += 1
    }
  }
  
  function handleProvisioned(event) {
    provisioningStatus.value[event.service_id] = {
      success: event.success,
      error: event.error,
      status: event.status,
      timestamp: event.timestamp,
    }
    
    window.dispatchEvent(new CustomEvent('hotspot:provisioned', { detail: event }))
  }
  
  function handleProvisionRequested(event) {
    provisioningStatus.value[event.service_id] = {
      status: 'pending',
      timestamp: event.timestamp,
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
    subscribeToWebSocket()
  })
  
  onUnmounted(() => {
    unsubscribeFromWebSocket()
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
  
  return {
    // State
    users,
    sessions,
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
    disconnectUser,
    grantAccess,
    revokeAccess,
    getUserDetails,
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
