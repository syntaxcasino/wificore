import { ref, onUnmounted } from 'vue'
import axios from 'axios'
import { useNotificationStore } from '@/stores/notifications'
import { scheduleAfterPaint } from '@/modules/common/composables/performance/useViewCache'

const PACKAGE_CACHE_TTL_MS = 15 * 1000

export function usePackages() {
  const notify = useNotificationStore()

  const packages = ref([])
  const loading = ref(false)
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
  const selectedPackageIds = ref([])
  let websocketListenersInitialized = false

  const packageCacheKey = () => {
    if (typeof window === 'undefined') {
      return null
    }

    const tenantId = window.localStorage.getItem('selectedTenantId') || 'default'
    return `tenant:packages:${tenantId}`
  }

  const sortPackages = (items) => [...items].sort((a, b) => {
    const dateA = new Date(a.created_at || 0)
    const dateB = new Date(b.created_at || 0)
    return dateB - dateA
  })

  const readCachedPackages = () => {
    const cacheKey = packageCacheKey()
    if (!cacheKey) {
      return null
    }

    try {
      const raw = window.sessionStorage.getItem(cacheKey)
      if (!raw) {
        return null
      }

      const parsed = JSON.parse(raw)
      if (!Array.isArray(parsed?.data) || typeof parsed?.cachedAt !== 'number') {
        return null
      }

      if ((Date.now() - parsed.cachedAt) > PACKAGE_CACHE_TTL_MS) {
        window.sessionStorage.removeItem(cacheKey)
        return null
      }

      return parsed.data
    } catch {
      return null
    }
  }

  const writeCachedPackages = (items) => {
    const cacheKey = packageCacheKey()
    if (!cacheKey) {
      return
    }

    try {
      window.sessionStorage.setItem(cacheKey, JSON.stringify({
        cachedAt: Date.now(),
        data: items,
      }))
    } catch {
      // Ignore browser storage cache failures
    }
  }

  const fetchPackages = async () => {
    const isInitialLoad = packages.value.length === 0
    listError.value = ''

    if (isInitialLoad) {
      const cachedPackages = readCachedPackages()
      if (cachedPackages) {
        packages.value = sortPackages(cachedPackages)
        refreshing.value = true
      } else {
        scheduleAfterPaint(() => {
          if (packages.value.length === 0) loading.value = true
        })
      }
    } else {
      refreshing.value = true
    }

    try {
      // Use authenticated API endpoint for tenant-aware filtering
      const response = await axios.get('/packages')
      const fetchedPackages = Array.isArray(response.data) ? response.data : (response.data.data || [])
      packages.value = sortPackages(fetchedPackages)
      writeCachedPackages(packages.value)
      setupWebSocketListeners()
    } catch (err) {
      listError.value = err.response?.data?.error || 'Failed to fetch packages'
      console.error('fetchPackages error:', err.message, err.response?.data)
      if (packages.value.length === 0) {
        packages.value = []
      }
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  const addPackage = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      // Ensure validity is populated from duration if empty
      if (!formData.value.validity && formData.value.duration) {
        formData.value.validity = formData.value.duration
      }
      const response = await axios.post('/packages', formData.value)
      const msg = response.data?.message || 'Package created successfully'
      const newPackage = response.data?.data
      formMessage.value = { text: msg, type: 'success' }
      formSubmitted.value = true
      notify.success('Package Created', msg)

      // Trigger immediate UI update via the event-driven path
      if (newPackage?.id && typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('package-created', { detail: { package: newPackage } }))
      }

      setTimeout(() => {
        showFormOverlay.value = false
        formSubmitted.value = false
        resetFormData()
      }, 1500)

      return true
    } catch (err) {
      const errors = err.response?.data?.errors
      let errMsg
      if (errors && typeof errors === 'object') {
        const fields = Object.keys(errors).map(k => k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
        errMsg = `Please fill in: ${fields.join(', ')}`
      } else {
        errMsg = err.response?.data?.error || err.response?.data?.message || 'Failed to create package'
      }
      formMessage.value = { text: errMsg, type: 'error' }
      notify.error('Package Creation Failed', errMsg)
      console.error('addPackage error:', err.message, err.response?.data)
      return false
    } finally {
      formSubmitting.value = false
    }
  }

  const editPackage = (pkg) => {
    selectedPackage.value = pkg
    // Clean copy: strip read-only/computed fields that shouldn't be sent
    const {
      users_count, created_at, updated_at, routers,
      ...editableFields
    } = pkg
    formData.value = editableFields
    isEditing.value = true
    showUpdateOverlay.value = true
  }

  const updatePackage = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      const response = await axios.put(`/packages/${selectedPackage.value.id}`, formData.value)
      const msg = response.data?.message || 'Package updated successfully'
      formMessage.value = { text: msg, type: 'success' }
      notify.success('Package Updated', msg)

      // Trigger immediate UI update via the event-driven path
      const updatedPkg = response.data?.data
      if (updatedPkg?.id && typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('package-updated', { detail: { package: updatedPkg } }))
      }

      // Close overlay and reset state after a short delay so user sees success
      setTimeout(() => {
        showUpdateOverlay.value = false
        selectedPackage.value = null
        resetFormData()
      }, 1000)
    } catch (err) {
      const errors = err.response?.data?.errors
      let errMsg
      if (errors && typeof errors === 'object') {
        const fields = Object.keys(errors).map(k => k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
        errMsg = `Please fill in: ${fields.join(', ')}`
      } else {
        errMsg = err.response?.data?.error || err.response?.data?.message || 'Failed to update package'
      }
      formMessage.value = { text: errMsg, type: 'error' }
      notify.error('Package Update Failed', errMsg)
      console.error('updatePackage error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const deletePackage = async (id) => {
    try {
      await axios.delete(`/packages/${id}`)
      // Trigger immediate UI update via the event-driven path
      if (typeof window !== 'undefined') {
        window.dispatchEvent(new CustomEvent('package-deleted', { detail: { packageId: String(id) } }))
      }
    } catch (err) {
      console.error('deletePackage error:', err.message, err.response?.data)
      throw err
    }
  }

  const togglePackageSelection = (id) => {
    const ids = selectedPackageIds.value
    const idx = ids.indexOf(id)
    if (idx === -1) {
      selectedPackageIds.value = [...ids, id]
    } else {
      selectedPackageIds.value = ids.filter((i) => i !== id)
    }
  }

  const toggleSelectAll = (allIds) => {
    const current = selectedPackageIds.value
    const allSelected = allIds.length > 0 && allIds.every((id) => current.includes(id))
    if (allSelected) {
      selectedPackageIds.value = current.filter((id) => !allIds.includes(id))
    } else {
      const merged = new Set([...current, ...allIds])
      selectedPackageIds.value = Array.from(merged)
    }
  }

  const clearSelection = () => {
    selectedPackageIds.value = []
  }

  const batchDeletePackages = async () => {
    const ids = [...selectedPackageIds.value]
    if (!ids.length) return
    clearSelection()
    const errors = []
    for (const id of ids) {
      try {
        await deletePackage(id)
      } catch (err) {
        errors.push(id)
        console.error('batchDeletePackages error for id:', id, err.message)
      }
    }
    if (errors.length) {
      throw new Error(`Failed to delete ${errors.length} of ${ids.length} packages`)
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
      const newIsActive = !pkg.is_active

      await axios.put(`/packages/${pkg.id}`, {
        ...pkg,
        status: newStatus,
        is_active: newIsActive
      })

      // Purely event-driven - WebSocket events will handle the UI update automatically
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
    showDetailsOverlay.value = true
    currentPackage.value = JSON.parse(JSON.stringify(pkg))
    try {
      const { data } = await axios.get(`/packages/${pkg.id}`)
      currentPackage.value = data
    } catch (err) {
      console.error('openDetails fetch error:', err.message)
      // Keep the list data as fallback
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

  // WebSocket handlers — react to custom events dispatched by websocket.js
  const handlePackageCreated = (event) => {
    const pkg = event.detail?.package || event.detail
    if (!pkg?.id) return
    
    console.log('[Packages] Received package-created event:', pkg.name)
    
    const exists = packages.value.some(p => p.id === pkg.id)
    if (!exists) {
      packages.value.unshift(pkg)
      writeCachedPackages(packages.value)
      console.log('[Packages] Added via event:', pkg.name)
    } else {
      console.log('[Packages] Package already exists, ignoring:', pkg.name)
    }
  }

  const handlePackageUpdated = (event) => {
    const pkg = event.detail?.package || event.detail
    if (!pkg?.id) return
    
    console.log('[Packages] Received package-updated event:', pkg.name)
    
    const index = packages.value.findIndex(p => p.id === pkg.id)
    if (index !== -1) {
      packages.value.splice(index, 1, { ...packages.value[index], ...pkg })
      writeCachedPackages(packages.value)
      console.log('[Packages] Updated via event:', pkg.name)
    } else {
      console.log('[Packages] Package not found for update, adding:', pkg.name)
      packages.value.unshift(pkg)
      writeCachedPackages(packages.value)
    }
  }

  const handlePackageDeleted = (event) => {
    const id = event.detail?.package?.id || event.detail?.id || event.detail?.packageId
    if (!id) return
    
    const pkgName = event.detail?.package?.name || event.detail?.name || `ID ${id}`
    console.log('[Packages] Received package-deleted event:', pkgName)
    
    const originalLength = packages.value.length
    packages.value = packages.value.filter(p => p.id !== id)
    writeCachedPackages(packages.value)
    
    if (packages.value.length < originalLength) {
      console.log('[Packages] Deleted via event:', pkgName)
    } else {
      console.log('[Packages] Package not found for deletion:', pkgName)
    }
  }

  const setupWebSocketListeners = () => {
    if (websocketListenersInitialized || typeof window === 'undefined') {
      return
    }

    window.addEventListener('package-created', handlePackageCreated)
    window.addEventListener('package-updated', handlePackageUpdated)
    window.addEventListener('package-deleted', handlePackageDeleted)
    websocketListenersInitialized = true
  }

  const cleanupWebSocketListeners = () => {
    if (!websocketListenersInitialized || typeof window === 'undefined') {
      return
    }

    window.removeEventListener('package-created', handlePackageCreated)
    window.removeEventListener('package-updated', handlePackageUpdated)
    window.removeEventListener('package-deleted', handlePackageDeleted)
    websocketListenersInitialized = false
  }

  onUnmounted(cleanupWebSocketListeners)

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
    selectedPackageIds,
    toggleMenu,
    closeMenuOnOutsideClick,
    fetchPackages,
    addPackage,
    editPackage,
    updatePackage,
    deletePackage,
    batchDeletePackages,
    togglePackageSelection,
    toggleSelectAll,
    clearSelection,
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
    statusBadgeClass,
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
