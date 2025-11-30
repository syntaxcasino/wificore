/**
 * Composable for tenant operations
 * Handles subdomain checking, tenant info fetching, and package loading
 */
import { ref } from 'vue'
import axios from 'axios'

export function useTenant() {
  const loading = ref(false)
  const error = ref(null)
  const tenant = ref(null)
  const packages = ref([])

  /**
   * Check if subdomain is available
   */
  const checkSubdomainAvailability = async (subdomain) => {
    try {
      loading.value = true
      error.value = null

      const response = await axios.post('/public/subdomain/check', {
        subdomain: subdomain
      })

      return {
        available: response.data.available,
        message: response.data.message
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to check subdomain'
      return {
        available: false,
        message: error.value
      }
    } finally {
      loading.value = false
    }
  }

  /**
   * Get tenant by subdomain
   */
  const getTenantBySubdomain = async (subdomain) => {
    try {
      loading.value = true
      error.value = null

      const response = await axios.get(`/public/tenant/${subdomain}`)

      if (response.data.success) {
        tenant.value = response.data.data
        return tenant.value
      } else {
        throw new Error(response.data.message || 'Tenant not found')
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load tenant'
      tenant.value = null
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get tenant packages
   */
  const getTenantPackages = async (subdomain) => {
    try {
      loading.value = true
      error.value = null

      const response = await axios.get(`/public/tenant/${subdomain}/packages`)

      if (response.data.success) {
        tenant.value = response.data.data.tenant
        packages.value = response.data.data.packages
        return {
          tenant: tenant.value,
          packages: packages.value
        }
      } else {
        throw new Error(response.data.message || 'Failed to load packages')
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load packages'
      packages.value = []
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get tenant by current domain
   */
  const getTenantByDomain = async () => {
    try {
      loading.value = true
      error.value = null

      const response = await axios.get('/public/tenant-by-domain')

      if (response.data.success) {
        tenant.value = response.data.data
        return tenant.value
      } else {
        throw new Error(response.data.message || 'Tenant not found')
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load tenant'
      tenant.value = null
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Extract subdomain from current URL
   */
  const getSubdomainFromUrl = () => {
    const hostname = window.location.hostname
    const parts = hostname.split('.')

    // For localhost development
    if (hostname === 'localhost' || hostname === '127.0.0.1') {
      return null
    }

    // For production, extract first part of domain
    if (parts.length >= 3) {
      return parts[0]
    }

    return null
  }

  /**
   * Check if current URL is a tenant subdomain
   */
  const isSubdomain = () => {
    return getSubdomainFromUrl() !== null
  }

  return {
    // State
    loading,
    error,
    tenant,
    packages,

    // Methods
    checkSubdomainAvailability,
    getTenantBySubdomain,
    getTenantPackages,
    getTenantByDomain,
    getSubdomainFromUrl,
    isSubdomain,
  }
}
