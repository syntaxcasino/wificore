import { ref } from 'vue'
import axios from 'axios'

export function usePackages() {
  const packages = ref([])
  const loading = ref(true) // Start with loading true to prevent flash of empty state
  const refreshing = ref(false)
  const listError = ref('')
  const formError = ref('')
  const error = ref(null)
  const showFormOverlay = ref(false)
  const showDetailsOverlay = ref(false)
  const showUpdateOverlay = ref(false)
  const currentPackage = ref(null)
  const isEditing = ref(false)
  const selectedPackage = ref(null)
  const formData = ref({
    name: '',
    description: '',
    type: 'hotspot',
    price: 0,
    speed: '',
    upload_speed: '',
    download_speed: '',
    data_limit: '',
    validity: '',
    duration: '',
    devices: 1,
    enable_burst: false,
    enable_schedule: false,
    scheduled_activation_time: null,
    hide_from_client: false,
    status: 'active',
    is_active: true
  })
  const formSubmitting = ref(false)
  const formMessage = ref({ text: '', type: '' })
  const formSubmitted = ref(false)
  const showMenu = ref(null)

  const fetchPackages = async () => {
    loading.value = true
    listError.value = ''
    try {
      // Use authenticated API endpoint for tenant-aware filtering
      const response = await axios.get('/packages')
      const fetchedPackages = Array.isArray(response.data) ? response.data : (response.data.data || [])
      
      // Sort by created_at descending (newest first)
      packages.value = fetchedPackages.sort((a, b) => {
        const dateA = new Date(a.created_at || 0)
        const dateB = new Date(b.created_at || 0)
        return dateB - dateA
      })
    } catch (err) {
      listError.value = err.response?.data?.error || 'Failed to fetch packages'
      console.error('fetchPackages error:', err.message, err.response?.data)
      packages.value = []
    } finally {
      loading.value = false
    }
  }

  const addPackage = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      // Use authenticated API endpoint - tenant_id auto-assigned by backend
      const response = await axios.post('/packages', formData.value)
      formMessage.value = { text: 'Package created successfully', type: 'success' }
      formSubmitted.value = true
      setTimeout(() => {
        showFormOverlay.value = false
        formSubmitted.value = false
        resetFormData()
        fetchPackages()
      }, 1500)
    } catch (err) {
      formMessage.value = {
        text: err.response?.data?.error || 'Failed to create package',
        type: 'error'
      }
      console.error('addPackage error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const editPackage = (pkg) => {
    selectedPackage.value = pkg
    formData.value = { ...pkg }
    isEditing.value = true
    showUpdateOverlay.value = true
  }

  const updatePackage = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      // Use authenticated API endpoint - backend verifies ownership
      const response = await axios.put(`/packages/${selectedPackage.value.id}`, formData.value)
      const updatedPackage = response.data
      
      // Update local state instead of refetching
      const index = packages.value.findIndex(p => p.id === selectedPackage.value.id)
      if (index !== -1) {
        packages.value[index] = updatedPackage
      }
      
      formMessage.value = { text: 'Package updated successfully', type: 'success' }
      showUpdateOverlay.value = false
    } catch (err) {
      formMessage.value = {
        text: err.response?.data?.error || 'Failed to update package',
        type: 'error'
      }
      console.error('updatePackage error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const deletePackage = async (id) => {
    try {
      // Use authenticated API endpoint - backend verifies ownership
      await axios.delete(`/packages/${id}`)
      
      // Remove from local state instead of refetching
      packages.value = packages.value.filter(p => p.id !== id)
    } catch (err) {
      console.error('deletePackage error:', err.message, err.response?.data)
      throw err
    }
  }

  const duplicatePackage = async (pkg) => {
    formData.value = {
      ...pkg,
      name: `${pkg.name} (Copy)`,
      id: undefined
    }
    isEditing.value = false
    showFormOverlay.value = true
  }

  const toggleStatus = async (pkg) => {
    try {
      const newStatus = pkg.status === 'active' ? 'inactive' : 'active'
      const newIsActive = pkg.is_active ? false : true
      
      // Use authenticated API endpoint - backend verifies ownership
      const response = await axios.put(`/packages/${pkg.id}`, {
        ...pkg,
        status: newStatus,
        is_active: newIsActive
      })
      
      const updatedPackage = response.data
      
      // Update local state instead of refetching
      const index = packages.value.findIndex(p => p.id === pkg.id)
      if (index !== -1) {
        packages.value[index] = updatedPackage
      }
    } catch (err) {
      console.error('toggleStatus error:', err.message, err.response?.data)
      throw err
    }
  }

  const openCreateOverlay = () => {
    showFormOverlay.value = true
    isEditing.value = false
    resetFormData()
  }

  const openEditOverlay = (pkg) => {
    editPackage(pkg)
  }

  const openDetails = async (pkg) => {
    try {
      currentPackage.value = JSON.parse(JSON.stringify(pkg))
      showDetailsOverlay.value = true
    } catch (error) {
      console.error('Error in openDetails:', error)
      currentPackage.value = pkg
      showDetailsOverlay.value = true
    }
  }

  const closeDetails = () => {
    showDetailsOverlay.value = false
    currentPackage.value = null
  }

  const closeFormOverlay = () => {
    showFormOverlay.value = false
    resetFormData()
  }

  const closeUpdateOverlay = () => {
    showUpdateOverlay.value = false
    selectedPackage.value = null
    resetFormData()
  }

  const resetFormData = () => {
    formData.value = {
      name: '',
      description: '',
      type: 'hotspot',
      price: 0,
      speed: '',
      upload_speed: '',
      download_speed: '',
      data_limit: '',
      validity: '',
      duration: '',
      devices: 1,
      enable_burst: false,
      enable_schedule: false,
      scheduled_activation_time: null,
      hide_from_client: false,
      status: 'active',
      is_active: true
    }
  }

  const toggleMenu = (packageId) => {
    showMenu.value = showMenu.value === packageId ? null : packageId
  }

  const closeMenuOnOutsideClick = (event, menuRef) => {
    if (showMenu.value && menuRef && !menuRef.contains(event.target)) {
      showMenu.value = null
    }
  }

  const formatTimestamp = (timestamp) => {
    if (!timestamp) return ''
    const date = new Date(timestamp)
    return date.toLocaleString()
  }

  const statusBadgeClass = (status) => {
    return {
      'px-2 py-1 text-xs font-medium rounded-full': true,
      'bg-green-100 text-green-800': status === 'active',
      'bg-gray-100 text-gray-800': status === 'inactive'
    }
  }

  return {
    packages,
    loading,
    refreshing,
    listError,
    formError,
    error,
    showFormOverlay,
    showDetailsOverlay,
    showUpdateOverlay,
    currentPackage,
    isEditing,
    selectedPackage,
    formData,
    formSubmitting,
    formMessage,
    formSubmitted,
    showMenu,
    toggleMenu,
    closeMenuOnOutsideClick,
    fetchPackages,
    addPackage,
    editPackage,
    updatePackage,
    deletePackage,
    duplicatePackage,
    toggleStatus,
    openCreateOverlay,
    openEditOverlay,
    openDetails,
    closeDetails,
    closeFormOverlay,
    closeUpdateOverlay,
    resetFormData,
    formatTimestamp,
    statusBadgeClass
  }
}
