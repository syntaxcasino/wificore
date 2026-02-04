import { ref, computed } from 'vue'
import axios from 'axios'

export function usePppoeUsers() {
  const users = ref([])
  const loading = ref(false)
  const error = ref(null)

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

      if (updatedUser) {
        const index = users.value.findIndex((u) => String(u.id) === String(userId))
        if (index !== -1) {
          users.value[index] = updatedUser
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

  const activeUsers = computed(() => users.value.filter((u) => u.status === 'active'))
  const inactiveUsers = computed(() => users.value.filter((u) => u.status === 'inactive'))
  const totalUsers = computed(() => users.value.length)

  return {
    users,
    loading,
    error,
    activeUsers,
    inactiveUsers,
    totalUsers,
    fetchUsers,
    getUser,
    createUser,
    updateUser,
    viewPassword,
    resetPassword,
    toggleUserStatus,
  }
}
