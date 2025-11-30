import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useRouter } from 'vue-router'
import axios from 'axios'

export function useRoleBasedData() {
  const authStore = useAuthStore()
  const router = useRouter()
  
  const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
  const isTenantAdmin = computed(() => authStore.user?.role === 'admin')
  const tenantId = computed(() => authStore.user?.tenant_id)
  
  /**
   * Handle API errors with proper security responses
   */
  const handleApiError = (error) => {
    if (error.response) {
      const status = error.response.status
      const message = error.response.data?.message || 'An error occurred'
      
      // Unauthorized - clear auth and redirect to login
      if (status === 401) {
        authStore.logout()
        router.push('/login')
        throw new Error('Session expired. Please login again.')
      }
      
      // Forbidden - user doesn't have permission
      if (status === 403) {
        throw new Error('You do not have permission to access this resource.')
      }
      
      throw new Error(message)
    } else if (error.request) {
      throw new Error('Unable to connect to server. Please check your connection.')
    } else {
      throw new Error('An unexpected error occurred.')
    }
  }
  
  /**
   * Get the appropriate API endpoint based on user role
   */
  const getApiEndpoint = (resource) => {
    if (isSystemAdmin.value) {
      return `/api/system/${resource}`
    } else {
      return `/api/tenant/${resource}`
    }
  }
  
  /**
   * Fetch dashboard data based on role
   */
  const fetchDashboardData = async () => {
    try {
      const endpoint = getApiEndpoint('dashboard')
      const response = await axios.get(endpoint)
      return response.data.data || response.data.stats
    } catch (error) {
      handleApiError(error)
    }
  }
  
  /**
   * Fetch users based on role
   * - System admin: All users across all tenants
   * - Tenant admin: Only users in their tenant
   */
  const fetchUsers = async () => {
    try {
      const endpoint = getApiEndpoint('users')
      const response = await axios.get(endpoint)
      return response.data.data
    } catch (error) {
      handleApiError(error)
    }
  }
  
  /**
   * Fetch packages based on role
   * - System admin: Not accessible
   * - Tenant admin: Only their packages
   */
  const fetchPackages = async () => {
    if (isSystemAdmin.value) {
      throw new Error('Packages not available for system admin')
    }
    
    try {
      const response = await axios.get('/tenant/packages')
      return response.data.data
    } catch (error) {
      handleApiError(error)
    }
  }
  
  /**
   * Fetch routers based on role
   * - System admin: Not accessible
   * - Tenant admin: Only their routers
   */
  const fetchRouters = async () => {
    if (isSystemAdmin.value) {
      throw new Error('Routers not available for system admin')
    }
    
    try {
      const response = await axios.get('/tenant/routers')
      return response.data.data
    } catch (error) {
      handleApiError(error)
    }
  }
  
  /**
   * Fetch payments/revenue
   */
  const fetchPayments = async () => {
    try {
      const response = await axios.get('/tenant/payments')
      return response.data.data
    } catch (error) {
      handleApiError(error)
    }
  }
  
  /**
   * Fetch active sessions
   */
  const fetchSessions = async () => {
    try {
      const response = await axios.get('/tenant/sessions')
      return response.data.data
    } catch (error) {
      handleApiError(error)
    }
  }
  
  return {
    isSystemAdmin,
    isTenantAdmin,
    tenantId,
    getApiEndpoint,
    handleApiError,
    fetchDashboardData,
    fetchUsers,
    fetchPackages,
    fetchRouters,
    fetchPayments,
    fetchSessions,
  }
}
