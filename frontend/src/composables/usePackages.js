import { ref } from 'vue'
import axios from 'axios'

export function usePackages() {
  const packages = ref([])
  const loading = ref(false)
  const error = ref(null)

  const fetchPackages = async () => {
    try {
      loading.value = true
      error.value = null
      const response = await axios.get('/api/packages')
      packages.value = response.data || []
    } catch (err) {
      console.error('Package fetch error:', err)
      error.value = err.response?.data?.message || 'Failed to load packages'
      packages.value = []
    } finally {
      loading.value = false
    }
  }

  const addPackage = async (packageData) => {
    try {
      loading.value = true
      error.value = null
      const response = await axios.post('/api/packages', packageData)
      packages.value = [...packages.value, response.data]
    } catch (err) {
      console.error('Package add error:', err)
      error.value = err.response?.data?.message || 'Failed to add package'
    } finally {
      loading.value = false
    }
  }

  const editPackage = async (packageId, updatedData) => {
    try {
      loading.value = true
      error.value = null
      const response = await axios.put(`/api/packages/${packageId}`, updatedData)
      packages.value = packages.value.map((pkg) => (pkg.id === packageId ? response.data : pkg))
    } catch (err) {
      console.error('Package edit error:', err)
      error.value = err.response?.data?.message || 'Failed to edit package'
    } finally {
      loading.value = false
    }
  }

  const deletePackage = async (packageId) => {
    try {
      loading.value = true
      error.value = null
      await axios.delete(`/api/packages/${packageId}`)
      packages.value = packages.value.filter((pkg) => pkg.id !== packageId)
    } catch (err) {
      console.error('Package delete error:', err)
      error.value = err.response?.data?.message || 'Failed to delete package'
    } finally {
      loading.value = false
    }
  }

  return {
    packages,
    loading,
    error,
    fetchPackages,
    addPackage,
    editPackage,
    deletePackage,
  }
}
