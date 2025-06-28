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

  return {
    packages,
    loading,
    error,
    fetchPackages,
  }
}
