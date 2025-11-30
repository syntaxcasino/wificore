import { ref, computed } from 'vue'
import axios from 'axios'

/**
 * Users composable for managing user data and operations
 */
export function useUsers() {
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
      users.value = response.data.data || response.data
      return users.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch users'
      console.error('Error fetching users:', err)
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

  /**
   * Update an existing user
   */
  const updateUser = async (userId, userData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/users/${userId}`, userData)
      const updatedUser = response.data.data || response.data
      
      // Update in the list
      const index = users.value.findIndex(u => u.id === userId)
      if (index !== -1) {
        users.value[index] = updatedUser
      }
      
      return updatedUser
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update user'
      console.error('Error updating user:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Delete a user
   */
  const deleteUser = async (userId) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/users/${userId}`)
      
      // Remove from the list
      users.value = users.value.filter(u => u.id !== userId)
      
      return true
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete user'
      console.error('Error deleting user:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Block/unblock a user
   */
  const toggleUserStatus = async (userId, block = true) => {
    loading.value = true
    error.value = null
    
    try {
      const endpoint = block ? `/users/${userId}/block` : `/users/${userId}/unblock`
      const response = await axios.post(endpoint)
      const updatedUser = response.data.data || response.data
      
      // Update in the list
      const index = users.value.findIndex(u => u.id === userId)
      if (index !== -1) {
        users.value[index] = updatedUser
      }
      
      return updatedUser
    } catch (err) {
      error.value = err.response?.data?.message || `Failed to ${block ? 'block' : 'unblock'} user`
      console.error(`Error ${block ? 'blocking' : 'unblocking'} user:`, err)
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
    users.value.filter(u => u.status === 'active')
  )

  const inactiveUsers = computed(() => 
    users.value.filter(u => u.status === 'inactive')
  )

  const blockedUsers = computed(() => 
    users.value.filter(u => u.status === 'blocked')
  )

  const totalUsers = computed(() => users.value.length)

  return {
    // State
    users,
    loading,
    error,
    selectedUser,
    
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
    fetchBlockedUsers
  }
}
