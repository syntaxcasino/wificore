import { ref, reactive, watch } from 'vue'
import axios from 'axios'

export function useRouters() {
  const routers = ref([]) // Initialize as empty array
  const loading = ref(false)
  const refreshing = ref(false)
  const listError = ref('')
  const formError = ref('')
  const showFormOverlay = ref(false)
  const showDetailsOverlay = ref(false)
  const showUpdateOverlay = ref(false)
  const currentRouter = ref(null)
  const isEditing = ref(false)
  const selectedRouter = ref(null)
  const formData = ref({
    name: '',
    id: null,
    ip_address: '',
    config_token: '',
    hotspot_interfaces: [],
    pppoe_interfaces: [],
    configurations: {},
    connectivity_script: '',
    service_script: '',
    model: '',
    os_version: '',
    last_seen: null,
    status: 'pending',
  })
  const formSubmitting = ref(false)
  const currentStep = ref(1)
  const steps = ['Router Name', 'Connectivity', 'Services']
  const configLoading = ref(false)
  const connectivityVerified = ref(false)
  const availableInterfaces = ref([])
  const configurationProgress = reactive({ status: 'Idle', percentage: 0, message: '' })
  const formMessage = ref({ text: '', type: '' })
  const formSubmitted = ref(false)
  const showMenu = ref(null) // Track which router's menu is open (null or router ID)

  // Watch formData for changes
  watch(
    formData,
    (newValue) => {
      // Form data changed
    },
    { deep: true },
  )

  const formatTimestamp = (timestamp) => {
    if (!timestamp) return ''
    const date = new Date(timestamp)
    return date.toLocaleString()
  }

  const statusBadgeClass = (status) => {
    return {
      'px-2 py-1 text-xs font-medium rounded-full': true,
      'bg-green-100 text-green-800': status === 'active',
      'bg-yellow-100 text-yellow-800': status === 'pending',
      'bg-red-100 text-red-800': status === 'disconnected',
    }
  }

  const toggleMenu = (routerId) => {
    showMenu.value = showMenu.value === routerId ? null : routerId
  }

  const closeMenuOnOutsideClick = (event, menuRef) => {
    if (showMenu.value && menuRef && !menuRef.contains(event.target)) {
      showMenu.value = null
    }
  }

  const fetchRouters = async () => {
    loading.value = true
    listError.value = ''
    try {
      const response = await axios.get('/routers')
      // API returns array directly, not wrapped in data property
      const fetchedRouters = Array.isArray(response.data) ? response.data : (response.data.data || [])
      
      // Sort by ID to maintain consistent order
      routers.value = fetchedRouters.sort((a, b) => {
        const idA = a.id || 0
        const idB = b.id || 0
        return idA - idB
      })
      
      // Routers fetched and sorted successfully
    } catch (err) {
      listError.value = err.response?.data?.error || 'Failed to fetch routers'
      console.error('fetchRouters error:', err.message, err.response?.data)
      routers.value = [] // Reset on error
    } finally {
      loading.value = false
    }
  }

  const addRouter = async () => {
    if (!formData.value.name || formData.value.name.trim() === '') {
      formMessage.value = { text: 'Router name is required', type: 'error' }
      console.error('addRouter failed: Router name is required')
      return
    }
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      const response = await axios.post('/routers', {
        name: formData.value.name,
      })
      formData.value = {
        ...formData.value,
        ...response.data,
        interface_assignments: response.data.interface_assignments || [],
        interface_services: response.data.interface_services || {},
        configurations: response.data.configurations || {},
        connectivity_script: response.data.connectivity_script || '',
        service_script: response.data.service_script || '',
        model: response.data.model || '',
        os_version: response.data.os_version || '',
        last_seen: response.data.last_seen || null,
        status: response.data.status || 'pending',
      }
      formMessage.value = { text: 'Router created successfully', type: 'success' }
      currentStep.value = 2
    } catch (err) {
      formMessage.value = {
        text: err.response?.data?.error || 'Failed to create router',
        type: 'error',
      }
      console.error('addRouter error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const verifyConnectivity = async () => {
    if (!formData.value.id) {
      console.error('verifyConnectivity failed: No router ID')
      formMessage.value = {
        text: 'Router ID is missing. Please create the router first by completing Step 1.',
        type: 'error',
      }
      return
    }
    configLoading.value = true
    formMessage.value = { text: '', type: '' }
    try {
      const response = await axios.get(`/routers/${formData.value.id}/verify-connectivity`)
      if (response.data.status === 'connected') {
        connectivityVerified.value = true
        availableInterfaces.value = response.data.interfaces || []
        formData.value = {
          ...formData.value,
          model: response.data.model || '',
          os_version: response.data.os_version || '',
          last_seen: response.data.last_seen || null,
          status: 'active',
        }
        formMessage.value = {
          text: 'Connectivity verified, router details and interfaces loaded',
          type: 'success',
        }
      } else {
        throw new Error(response.data.error || 'Failed to verify connectivity')
      }
    } catch (err) {
      console.error(
        'Verify connectivity error:',
        err.message,
        'Response:',
        err.response?.data,
        'Status:',
        err.response?.status,
      )
      connectivityVerified.value = false
      availableInterfaces.value = []
      const errorMessage =
        err.response?.data?.error ||
        err.message ||
        `Unable to connect to the router. Ensure the connectivity script is applied and the router is online at ${formData.value.ip_address}:8728.`
      formMessage.value = { text: errorMessage, type: 'error' }
    } finally {
      configLoading.value = false
    }
  }

  const generateConfigs = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      const response = await axios.post(
        `/routers/${formData.value.id}/generate-service-config`,
        formData.value,
      )
      formData.value.service_script = response.data.service_script || ''
      formMessage.value = { text: 'Service configuration generated successfully', type: 'success' }
    } catch (err) {
      formMessage.value = {
        text: err.response?.data?.error || 'Failed to generate service configuration',
        type: 'error',
      }
      console.error('generateConfigs error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const applyConfigurations = async () => {
    formSubmitting.value = true
    configurationProgress.status = 'Applying'
    configurationProgress.percentage = 0
    configurationProgress.message = ''
    try {
      console.log('Sending POST to /routers/' + formData.value.id + '/apply-configs')
      const response = await axios.post(`/routers/${formData.value.id}/apply-configs`, {
        service_script: formData.value.service_script,
      })
      console.log('applyConfigurations response:', response.data)
      configurationProgress.percentage = 100
      configurationProgress.status = 'Applied'
      configurationProgress.message = 'Configuration applied successfully'
      formMessage.value = { text: 'Configuration applied successfully', type: 'success' }
      formSubmitted.value = true
      setTimeout(() => {
        showFormOverlay.value = false
        formSubmitted.value = false
        currentStep.value = 1
        formData.value = {
          name: '',
          id: null,
          ip_address: '',
          config_token: '',
          hotspot_interfaces: [],
          pppoe_interfaces: [],
          configurations: {},
          connectivity_script: '',
          service_script: '',
          model: '',
          os_version: '',
          last_seen: null,
          status: 'pending',
        }
        console.log('Form reset, formData:', JSON.parse(JSON.stringify(formData.value)))
      }, 2000)
    } catch (err) {
      configurationProgress.status = 'Failed'
      configurationProgress.percentage = 0
      configurationProgress.message = err.response?.data?.error || 'Failed to apply configuration'
      formMessage.value = { text: configurationProgress.message, type: 'error' }
      console.error('applyConfigurations error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const editRouter = (router) => {
    selectedRouter.value = router
    formData.value = {
      ...router,
      interface_assignments: router.interface_assignments || [],
      interface_services: router.interface_services || {},
      configurations: router.configurations || {},
      connectivity_script: router.connectivity_script || '',
      service_script: router.service_script || '',
    }
    isEditing.value = true
    showUpdateOverlay.value = true
    console.log('editRouter called, formData:', JSON.parse(JSON.stringify(formData.value)))
  }

  const updateRouter = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    try {
      console.log('Sending PUT to /routers/' + selectedRouter.value.id)
      await axios.put(`/routers/${selectedRouter.value.id}`, {
        name: formData.value.name,
        ip_address: formData.value.ip_address,
        config_token: formData.value.config_token,
      })
      formMessage.value = { text: 'Router updated successfully', type: 'success' }
      showUpdateOverlay.value = false
      await fetchRouters()
    } catch (err) {
      formMessage.value = {
        text: err.response?.data?.error || 'Failed to update router',
        type: 'error',
      }
      console.error('updateRouter error:', err.message, err.response?.data)
    } finally {
      formSubmitting.value = false
    }
  }

  const deleteRouter = async (id) => {
    try {
      console.log('Sending DELETE to /routers/' + id)
      await axios.delete(`/routers/${id}`)
      await fetchRouters()
    } catch (err) {
      console.error('deleteRouter error:', err.message, err.response?.data)
      throw err
    }
  }

  const openCreateOverlay = () => {
    showFormOverlay.value = true
    isEditing.value = false
    currentStep.value = 1
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      hotspot_interfaces: [],
      pppoe_interfaces: [],
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending',
    }
    console.log('openCreateOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)))
  }

  const openEditOverlay = (router) => {
    editRouter(router)
  }

  const openDetails = async (router) => {
    try {
      console.log('openDetails called for router:', router.id, router.name)
      currentRouter.value = JSON.parse(JSON.stringify(router))
      showDetailsOverlay.value = true
      console.log('Current router set:', {
        id: currentRouter.value.id,
        name: currentRouter.value.name,
        status: currentRouter.value.status,
      })
      await fetchRouterDetails(router.id)
    } catch (error) {
      console.error('Error in openDetails:', error)
      currentRouter.value = router
      showDetailsOverlay.value = true
    }
  }

  const fetchRouterDetails = async (routerId) => {
    refreshing.value = true
    try {
      console.log('Fetching details for router:', routerId)
      const response = await axios.get(`/routers/${routerId}/details`)
      console.log('Fetched router details:', response.data)
      currentRouter.value = { ...currentRouter.value, ...response.data }
    } catch (error) {
      console.warn('Could not fetch fresh router details:', error.message)
    } finally {
      refreshing.value = false
    }
  }

  const refreshDetails = async () => {
    if (currentRouter.value && currentRouter.value.id) {
      refreshing.value = true
      try {
        await fetchRouterDetails(currentRouter.value.id)
      } finally {
        refreshing.value = false
      }
    }
  }

  const closeDetails = () => {
    showDetailsOverlay.value = false
    currentRouter.value = null
  }

  const closeFormOverlay = () => {
    showFormOverlay.value = false
    currentStep.value = 1
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      hotspot_interfaces: [],
      pppoe_interfaces: [],
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending',
    }
    console.log('closeFormOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)))
    fetchRouters()
  }

  const closeUpdateOverlay = () => {
    showUpdateOverlay.value = false
    selectedRouter.value = null
    formData.value = {
      name: '',
      id: null,
      ip_address: '',
      config_token: '',
      hotspot_interfaces: [],
      pppoe_interfaces: [],
      configurations: {},
      connectivity_script: '',
      service_script: '',
      model: '',
      os_version: '',
      last_seen: null,
      status: 'pending',
    }
    console.log('closeUpdateOverlay called, formData:', JSON.parse(JSON.stringify(formData.value)))
  }

  const nextStep = () => {
    if (currentStep.value < steps.length) {
      currentStep.value++
      console.log('nextStep called, currentStep:', currentStep.value)
    }
  }

  const previousStep = () => {
    if (currentStep.value > 1) {
      currentStep.value--
      console.log('previousStep called, currentStep:', currentStep.value)
    }
  }

  const copyToClipboard = (script) => {
    navigator.clipboard
      .writeText(script)
      .then(() => {
        formMessage.value = { text: 'Script copied to clipboard', type: 'success' }
        console.log('copyToClipboard successful, script:', script)
      })
      .catch((err) => {
        formMessage.value = { text: 'Failed to copy script', type: 'error' }
        console.error('copyToClipboard failed:', err.message)
      })
  }

  const updateInterfaceAssignments = (interfaceName, event) => {
    if (event.target.checked) {
      formData.value.interface_assignments = [
        ...formData.value.interface_assignments,
        interfaceName,
      ]
      if (!formData.value.interface_services[interfaceName]) {
        formData.value.interface_services[interfaceName] = 'none'
        formData.value.configurations[interfaceName] = {}
      }
    } else {
      formData.value.interface_assignments = formData.value.interface_assignments.filter(
        (name) => name !== interfaceName,
      )
      delete formData.value.interface_services[interfaceName]
      delete formData.value.configurations[interfaceName]
    }
    console.log(
      'updateInterfaceAssignments called, formData:',
      JSON.parse(JSON.stringify(formData.value)),
    )
  }

  const updateFormData = (data) => {
    console.log('updateFormData called in useRouters, data:', JSON.parse(JSON.stringify(data)))
    Object.assign(formData.value, data)
    console.log('formData updated in useRouters:', JSON.parse(JSON.stringify(formData.value)))
  }

  return {
    routers,
    loading,
    refreshing,
    listError,
    formError,
    showFormOverlay,
    showDetailsOverlay,
    showUpdateOverlay,
    currentRouter,
    isEditing,
    selectedRouter,
    formData,
    formSubmitting,
    currentStep,
    steps,
    configLoading,
    connectivityVerified,
    availableInterfaces,
    configurationProgress,
    formMessage,
    formSubmitted,
    showMenu,
    toggleMenu,
    closeMenuOnOutsideClick,
    fetchRouters,
    verifyConnectivity,
    addRouter,
    editRouter,
    updateRouter,
    deleteRouter,
    generateConfigs,
    applyConfigurations,
    formatTimestamp,
    statusBadgeClass,
    openCreateOverlay,
    openEditOverlay,
    openDetails,
    closeDetails,
    refreshDetails,
    closeFormOverlay,
    closeUpdateOverlay,
    nextStep,
    previousStep,
    copyToClipboard,
    updateInterfaceAssignments,
    updateFormData,
  }
}
