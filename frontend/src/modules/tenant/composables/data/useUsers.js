import { ref, computed } from 'vue'
import axios from 'axios'
import { useEventDeduplicationStore } from '@/stores/eventDeduplication'

/**
 * Users composable for managing user data and operations
 */
export function useUsers() {
  const dedupStore = useEventDeduplicationStore()
  const users = ref([])
  const loading = ref(false)
  const error = ref(null)
  const selectedUser = ref(null)

  /**
   * Fetch all users from the API
   */
  const fetchUsers = async () => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.get('/users')
      const data = response.data.data || response.data
      users.value = Array.isArray(data) ? data : []
      return users.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch users'
      console.error('Error fetching users:', err)
      users.value = []
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch a single user by ID
   */
  const fetchUser = async (userId) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.get(`/users/${userId}`)
      selectedUser.value = response.data.data || response.data
      return selectedUser.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch user'
      console.error('Error fetching user:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Create a new user
   */
  const createUser = async (userData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/users', userData)
      const newUser = response.data.data || response.data
      users.value.unshift(newUser)
      return newUser
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create user'
      console.error('Error creating user:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  // Track pending optimistic updates for rollback
  const pendingUpdates = ref(new Map())

  /**
   * Update an existing user with optimistic update and rollback
   */
  const updateUser = async (userId, userData) => {
    loading.value = true
    error.value = null

    const index = users.value.findIndex(u => u.id === userId)
    if (index === -1) {
      loading.value = false
      throw new Error('User not found')
    }

    // Save current state for potential rollback
    const previousState = { ...users.value[index] }
    const optimisticState = { ...previousState, ...userData, _optimistic: true }

    // Apply optimistic update
    users.value[index] = optimisticState
    pendingUpdates.value.set(userId, previousState)

    try {
      const response = await axios.put(`/users/${userId}`, userData)
      const updatedUser = response.data.data || response.data

      // Remove from pending updates
      pendingUpdates.value.delete(userId)

      // Only update if the response is fresher than what we have
      // (WebSocket event might have already updated it)
      if (isDataFresher(updatedUser, users.value[index])) {
        users.value[index] = updatedUser
      }

      return updatedUser
    } catch (err) {
      // Rollback on error
      const currentIndex = users.value.findIndex(u => u.id === userId)
      if (currentIndex !== -1) {
        users.value[currentIndex] = previousState
      }
      pendingUpdates.value.delete(userId)

      error.value = err.response?.data?.message || 'Failed to update user'
      console.error('Error updating user (rolled back):', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Delete a user with optimistic delete and rollback
   */
  const deleteUser = async (userId) => {
    loading.value = true
    error.value = null

    const index = users.value.findIndex(u => u.id === userId)
    if (index === -1) {
      loading.value = false
      throw new Error('User not found')
    }

    // Save current state for potential rollback
    const deletedUser = users.value[index]
    const deletedState = { ...deletedUser, _deleted: true, _optimistic: true }

    // Apply optimistic delete (mark as deleted but keep reference for rollback)
    users.value[index] = deletedState
    pendingUpdates.value.set(userId, deletedUser)

    // Hide from UI immediately (but keep in array for rollback)
    const visibleUsers = users.value.filter(u => !u._deleted)

    try {
      await axios.delete(`/users/${userId}`)

      // Remove from pending updates
      pendingUpdates.value.delete(userId)

      // Actually remove from the list
      users.value = users.value.filter(u => u.id !== userId)

      return true
    } catch (err) {
      // Rollback on error - restore the deleted user
      const currentIndex = users.value.findIndex(u => u.id === userId)
      if (currentIndex !== -1) {
        users.value[currentIndex] = deletedUser
      } else {
        // If somehow not found, add it back
        users.value.push(deletedUser)
      }
      pendingUpdates.value.delete(userId)

      error.value = err.response?.data?.message || 'Failed to delete user'
      console.error('Error deleting user (rolled back):', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Block/unblock a user with optimistic update and rollback
   */
  const toggleUserStatus = async (userId, block = true) => {
    loading.value = true
    error.value = null

    const index = users.value.findIndex(u => u.id === userId)
    if (index === -1) {
      loading.value = false
      throw new Error('User not found')
    }

    // Save current state for potential rollback
    const previousState = { ...users.value[index] }
    const optimisticStatus = block ? 'blocked' : 'active'
    const optimisticState = { ...previousState, status: optimisticStatus, _optimistic: true }

    // Apply optimistic update
    users.value[index] = optimisticState
    pendingUpdates.value.set(userId, previousState)

    try {
      const endpoint = block ? `/users/${userId}/block` : `/users/${userId}/unblock`
      const response = await axios.post(endpoint)
      const updatedUser = response.data.data || response.data

      // Remove from pending updates
      pendingUpdates.value.delete(userId)

      // Only update if the response is fresher than what we have
      if (isDataFresher(updatedUser, users.value[index])) {
        users.value[index] = updatedUser
      }

      return updatedUser
    } catch (err) {
      // Rollback on error
      const currentIndex = users.value.findIndex(u => u.id === userId)
      if (currentIndex !== -1) {
        users.value[currentIndex] = previousState
      }
      pendingUpdates.value.delete(userId)

      error.value = err.response?.data?.message || `Failed to ${block ? 'block' : 'unblock'} user`
      console.error(`Error ${block ? 'blocking' : 'unblocking'} user (rolled back):`, err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get online users
   */
  const fetchOnlineUsers = async () => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.get('/users/online')
      return response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch online users'
      console.error('Error fetching online users:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get blocked users
   */
  const fetchBlockedUsers = async () => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.get('/users/blocked')
      return response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch blocked users'
      console.error('Error fetching blocked users:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  // Computed properties
  const activeUsers = computed(() => 
    Array.isArray(users.value) ? users.value.filter(u => u.status === 'active') : []
  )

  const inactiveUsers = computed(() => 
    Array.isArray(users.value) ? users.value.filter(u => u.status === 'inactive') : []
  )

  const blockedUsers = computed(() => 
    Array.isArray(users.value) ? users.value.filter(u => u.status === 'blocked') : []
  )

  const totalUsers = computed(() => Array.isArray(users.value) ? users.value.length : 0)

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

  const handleUserCreated = (event) => {
    const userData = event.detail?.user || event.detail
    const timestamp = event.detail?.timestamp || userData?.updated_at || userData?.created_at
    if (!userData?.id) return

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('user-created', userData.id, timestamp)) {
      return
    }

    // Check if user already exists (avoid duplicates from optimistic updates)
    const existingIndex = users.value.findIndex(u => u.id === userData.id)
    if (existingIndex === -1) {
      users.value.unshift(userData)
      console.log('[Users] Added via event:', userData.username || userData.name)
    } else {
      // User exists, check if event data is fresher
      if (isDataFresher(userData, users.value[existingIndex])) {
        users.value[existingIndex] = { ...users.value[existingIndex], ...userData }
        console.log('[Users] Updated via create event (fresher data):', userData.username || userData.name)
      } else {
        console.log('[Users] Ignored stale create event for:', userData.username || userData.name)
      }
    }

    lastSyncTimestamp.value = new Date().toISOString()
  }

  const handleUserUpdated = (event) => {
    const userData = event.detail?.user || event.detail
    const timestamp = event.detail?.timestamp || userData?.updated_at
    if (!userData?.id) return

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('user-updated', userData.id, timestamp)) {
      return
    }

    const index = users.value.findIndex(u => u.id === userData.id)
    if (index !== -1) {
      // Prevent stale data overwrites - compare timestamps
      if (isDataFresher(userData, users.value[index])) {
        users.value[index] = { ...users.value[index], ...userData }
        console.log('[Users] Updated via event:', userData.username || userData.name)
      } else {
        console.log('[Users] Ignored stale update event for:', userData.username || userData.name)
      }
    } else {
      // User not in list, add them (might have been created while offline)
      users.value.unshift(userData)
      console.log('[Users] Added via update event (was missing):', userData.username || userData.name)
    }

    lastSyncTimestamp.value = new Date().toISOString()
  }

  const handleUserDeleted = (event) => {
    const userId = event.detail?.userId || event.detail?.user?.id || event.detail?.id
    const timestamp = event.detail?.timestamp
    if (!userId) return

    // Deduplication: Skip if already processed this event
    if (!dedupStore.tryProcess('user-deleted', userId, timestamp)) {
      return
    }

    const user = users.value.find(u => u.id === userId)
    if (user) {
      users.value = users.value.filter(u => u.id !== userId)
      console.log('[Users] Deleted via event:', userId)
    }

    lastSyncTimestamp.value = new Date().toISOString()
  }

  // Catch-up fetch for reconnects - fetch data changed since last sync
  const catchUpFetch = async () => {
    if (!lastSyncTimestamp.value) {
      // No last sync, do full fetch
      return fetchUsers()
    }

    try {
      console.log('[Users] Running catch-up fetch since:', lastSyncTimestamp.value)
      const response = await axios.get('/users', {
        params: { since: lastSyncTimestamp.value }
      })
      const data = response.data.data || response.data

      if (Array.isArray(data)) {
        // Merge catch-up data with existing
        data.forEach(user => {
          const index = users.value.findIndex(u => u.id === user.id)
          if (index === -1) {
            users.value.push(user)
          } else if (isDataFresher(user, users.value[index])) {
            users.value[index] = { ...users.value[index], ...user }
          }
        })
      }

      lastSyncTimestamp.value = new Date().toISOString()
    } catch (err) {
      console.error('[Users] Catch-up fetch failed:', err)
      // Fall back to full fetch
      return fetchUsers()
    }
  }

  // Handle WebSocket reconnect - catch up on missed data
  const handleWebSocketReconnect = () => {
    console.log('[Users] WebSocket reconnected, running catch-up fetch')
    catchUpFetch()
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('user-created', handleUserCreated)
    window.addEventListener('user-updated', handleUserUpdated)
    window.addEventListener('user-deleted', handleUserDeleted)
    window.addEventListener('websocket-reconnected', handleWebSocketReconnect)
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('user-created', handleUserCreated)
    window.removeEventListener('user-updated', handleUserUpdated)
    window.removeEventListener('user-deleted', handleUserDeleted)
    window.removeEventListener('websocket-reconnected', handleWebSocketReconnect)
  }

  return {
    // State
    users,
    loading,
    error,
    selectedUser,
    lastSyncTimestamp,
    pendingUpdates,

    // Computed
    activeUsers,
    inactiveUsers,
    blockedUsers,
    totalUsers,

    // Methods
    fetchUsers,
    fetchUser,
    createUser,
    updateUser,
    deleteUser,
    toggleUserStatus,
    fetchOnlineUsers,
    fetchBlockedUsers,

    // WebSocket
    setupWebSocketListeners,
    cleanupWebSocketListeners,
    catchUpFetch,
    isDataFresher
  }
}
