import { ref } from 'vue'
import axios from 'axios'

export function useRouterProvisioning() {
  const routers = ref([])
  const loading = ref(false)
  const error = ref(null)

  const apiBaseUrl = 'https://api.yourserver.com/api' // Replace with your Laravel API URL

  // Fetch all router configurations
  const fetchRouters = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get(`${apiBaseUrl}/router-configs`)
      routers.value = response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch router configurations'
    } finally {
      loading.value = false
    }
  }

  // Add a new router configuration
  const addRouter = async (routerData) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post(`${apiBaseUrl}/router-configs`, routerData)
      routers.value.push(response.data.data || response.data)
      return response.data.data || response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to add router configuration'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Update an existing router configuration
  const editRouter = async (id, routerData) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.put(`${apiBaseUrl}/router-configs/${id}`, routerData)
      const updatedRouter = response.data.data || response.data
      const index = routers.value.findIndex((r) => r.id === id)
      if (index !== -1) {
        routers.value[index] = updatedRouter
      }
      return updatedRouter
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update router configuration'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Save configuration scripts
  const saveConfigs = async (routerId, scripts) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post(`${apiBaseUrl}/provisioning/configs`, {
        router_id: routerId,
        scripts,
      })
      return response.data.token
    } catch (err) {
      error.value = err.response?.data?.error || 'Failed to save configurations'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Fetch interfaces from router
  const fetchInterfaces = async (routerId) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post(`${apiBaseUrl}/provisioning/interfaces`, {
        router_id: routerId,
      })
      return response.data
    } catch (err) {
      error.value = err.response?.data?.error || 'Failed to fetch interfaces'
      throw err
    } finally {
      loading.value = false
    }
  }

  // Apply configurations to router
  const applyConfigs = async (routerId, interfaceAssignments, configurations) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post(`${apiBaseUrl}/provisioning/apply`, {
        router_id: routerId,
        interface_assignments: interfaceAssignments,
        configurations,
      })
      return response.data
    } catch (err) {
      error.value = err.response?.data?.error || 'Failed to apply configurations'
      throw err
    } finally {
      loading.value = false
    }
  }

  return {
    routers,
    loading,
    error,
    fetchRouters,
    addRouter,
    editRouter,
    saveConfigs,
    fetchInterfaces,
    applyConfigs,
  }
}
