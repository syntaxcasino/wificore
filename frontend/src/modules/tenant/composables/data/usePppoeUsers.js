import { ref, computed } from 'vue'
import axios from 'axios'

export function usePppoeUsers() {
  const users = ref([])
  const loading = ref(false)
  const error = ref(null)
  
  // WebSocket channel reference
  let echoChannel = null

  const fetchUsers = async () => {
    loading.value = true
    error.value = null

    try {
      const response = await axios.get('/pppoe/users')
      const payload = response.data?.data ?? response.data
      users.value = Array.isArray(payload) ? payload : (payload?.data ?? [])
      return users.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch PPPoE users'
      throw err
    } finally {
      loading.value = false
    }
  }

  const createUser = async (userData) => {
    loading.value = true
    error.value = null

    try {
      const response = await axios.post('/pppoe/users', userData)
      const createdUser = response.data?.data

      // Optimistically add to the local list immediately for better UX
      if (createdUser) {
        users.value.unshift(createdUser)
      }

      return {
        user: createdUser,
        generatedPassword: response.data?.generated_password || null,
        response,
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create PPPoE user'
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateUser = async (userId, userData) => {
    loading.value = true
    error.value = null

    try {
      const response = await axios.put(`/pppoe/users/${userId}`, userData)
      const updatedUser = response.data?.data

      // Optimistically update the local list immediately for better UX
      if (updatedUser) {
        const index = users.value.findIndex(u => u.id === userId)
        if (index !== -1) {
          users.value.splice(index, 1, { ...users.value[index], ...updatedUser })
        }
      }

      return updatedUser
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update PPPoE user'
      throw err
    } finally {
      loading.value = false
    }
  }

  const getUser = async (userId) => {
    try {
      const response = await axios.get(`/pppoe/users/${userId}`)
      return response.data?.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch PPPoE user'
      throw err
    }
  }

  const viewPassword = async (userId) => {
    try {
      const response = await axios.get(`/pppoe/users/${userId}/password`)
      if (response.data?.success === false) {
        error.value = response.data?.message || 'Password not available'
        throw new Error(error.value)
      }
      return response.data?.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to view password'
      throw err
    }
  }

  const resetPassword = async (userId) => {
    try {
      const response = await axios.post(`/pppoe/users/${userId}/reset-password`)
      return {
        user: response.data?.data,
        generatedPassword: response.data?.generated_password || null,
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to reset password'
      throw err
    }
  }

  const toggleUserStatus = async (userId, block = true) => {
    loading.value = true
    error.value = null

    try {
      const endpoint = block ? `/pppoe/users/${userId}/block` : `/pppoe/users/${userId}/unblock`
      const response = await axios.post(endpoint)
      return response.data?.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || `Failed to ${block ? 'block' : 'unblock'} PPPoE user`
      throw err
    } finally {
      loading.value = false
    }
  }
  
  /**
   * Subscribe to WebSocket channels for real-time updates
   */
  const subscribeToWebSocket = () => {
    // Get tenantId from auth store or localStorage
    const authData = localStorage.getItem('auth')
    let tenantId = null
    if (authData) {
      try {
        const parsed = JSON.parse(authData)
        tenantId = parsed.user?.tenant_id
      } catch (e) {
        console.warn('Failed to parse auth data:', e)
      }
    }
    
    if (typeof window === 'undefined' || !tenantId) {
      return
    }

    if (!window.Echo) {
      // Echo initialises asynchronously — retry up to 3× with doubling delay
      const attempt = arguments[0] ?? 0
      if (attempt < 3) {
        setTimeout(() => subscribeToWebSocket(attempt + 1), 500 * (attempt + 1))
      }
      return
    }
    
    // Subscribe to pppoe-users channel
    echoChannel = window.Echo.private(`tenant.${tenantId}.pppoe-users`)
      .listen('.PppoeUserCreated', (event) => {
        console.log('PPPoE user created:', event)
        handleUserCreated(event)
      })
      .listen('.PppoeUserUpdated', (event) => {
        console.log('PPPoE user updated:', event)
        handleUserUpdated(event)
      })
      .listen('.PppoeUserDeleted', (event) => {
        console.log('PPPoE user deleted:', event)
        handleUserDeleted(event)
      })
    
    console.log(`Subscribed to PPPoE users WebSocket channel for tenant ${tenantId}`)
  }
  
  /**
   * Unsubscribe from WebSocket channels
   */
  const unsubscribeFromWebSocket = () => {
    // Get tenantId from auth store or localStorage
    const authData = localStorage.getItem('auth')
    let tenantId = null
    if (authData) {
      try {
        const parsed = JSON.parse(authData)
        tenantId = parsed.user?.tenant_id
      } catch (e) {
        console.warn('Failed to parse auth data:', e)
      }
    }
    
    if (typeof window === 'undefined' || !tenantId || !window.Echo) return
    
    window.Echo.leave(`tenant.${tenantId}.pppoe-users`)
    echoChannel = null
    
    console.log('Unsubscribed from PPPoE users WebSocket channel')
  }
  
  // WebSocket event handlers
  const handleUserCreated = (event) => {
    const user = event.pppoe_user || event.user
    if (!user) return
    
    // Add new user to the list if not already present
    const exists = users.value.some(u => u.id === user.id)
    if (!exists) {
      users.value.unshift(user)
    }
  }
  
  const handleUserUpdated = (event) => {
    const user = event.pppoe_user || event.user
    if (!user) return
    
    // Update existing user in the list
    const index = users.value.findIndex(u => u.id === user.id)
    if (index !== -1) {
      // Replace with new object to ensure reactivity
      users.value.splice(index, 1, { ...users.value[index], ...user })
    } else {
      // If not found, add it
      users.value.unshift(user)
    }
  }
  
  const handleUserDeleted = (event) => {
    const userId = event.pppoe_user?.id || event.id || event.user_id
    if (!userId) return
    
    // Remove user from the list
    const index = users.value.findIndex(u => u.id === userId)
    if (index !== -1) {
      users.value.splice(index, 1)
    }
  }

  const activeUsers = computed(() => Array.isArray(users.value) ? users.value.filter((u) => u.status === 'active') : [])
  const inactiveUsers = computed(() => Array.isArray(users.value) ? users.value.filter((u) => u.status === 'inactive') : [])
  const blockedUsers = computed(() => Array.isArray(users.value) ? users.value.filter((u) => u.status === 'blocked') : [])
  const expiredUsers = computed(() => Array.isArray(users.value) ? users.value.filter((u) => u.status === 'expired') : [])
  const totalUsers = computed(() => Array.isArray(users.value) ? users.value.length : 0)

  return {
    users,
    loading,
    error,
    activeUsers,
    inactiveUsers,
    blockedUsers,
    expiredUsers,
    totalUsers,
    fetchUsers,
    getUser,
    createUser,
    updateUser,
    viewPassword,
    resetPassword,
    toggleUserStatus,
    subscribeToWebSocket,
    unsubscribeFromWebSocket,
  }
}
