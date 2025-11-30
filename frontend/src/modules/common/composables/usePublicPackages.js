import { ref } from 'vue'
import axios from 'axios'

export function usePublicPackages() {
  const packages = ref([])
  const loading = ref(false)
  const error = ref(null)
  const tenantId = ref(null)

  /**
   * Fetch public packages for the current tenant
   * Tenant is auto-detected from router IP, subdomain, or query params
   */
  const fetchPublicPackages = async (params = {}) => {
    loading.value = true
    error.value = null

    try {
      const response = await axios.get('/public/packages', { params })
      
      if (response.data.success) {
        packages.value = response.data.packages
        tenantId.value = response.data.tenant_id
        
        // Store tenant ID in session storage for future requests
        if (tenantId.value) {
          sessionStorage.setItem('current_tenant_id', tenantId.value)
        }
        
        return response.data
      } else {
        throw new Error(response.data.message || 'Failed to load packages')
      }
    } catch (err) {
      error.value = err.response?.data?.message || err.message || 'Failed to load packages'
      console.error('Error fetching public packages:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Set tenant session explicitly
   * Useful when tenant is known but not auto-detected
   */
  const setTenantSession = async (tenantIdValue) => {
    try {
      await axios.post('/public/set-tenant', {
        tenant_id: tenantIdValue
      })
      
      tenantId.value = tenantIdValue
      sessionStorage.setItem('current_tenant_id', tenantIdValue)
      
      return true
    } catch (err) {
      console.error('Error setting tenant session:', err)
      return false
    }
  }

  /**
   * Get tenant ID from session storage
   */
  const getTenantFromSession = () => {
    return sessionStorage.getItem('current_tenant_id')
  }

  /**
   * Clear tenant session
   */
  const clearTenantSession = () => {
    tenantId.value = null
    sessionStorage.removeItem('current_tenant_id')
  }

  return {
    packages,
    loading,
    error,
    tenantId,
    fetchPublicPackages,
    setTenantSession,
    getTenantFromSession,
    clearTenantSession
  }
}
