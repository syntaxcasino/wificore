import { ref, reactive, watch, onUnmounted } from 'vue'
import axios from 'axios'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'

export function useRouters() {
  const routers = ref([]) // Initialize as empty array
  const loading = ref(false)
  const refreshing = ref(false)
  const listError = ref('')
  const formError = ref('')
  const detailsError = ref('')
  const detailsLoading = ref(false)
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
  let latestMetricsRequestToken = 0

  // WebSocket Integration
  const { subscribeToPrivateChannel, unsubscribe } = useBroadcasting()
  const authStore = useAuthStore()
  let routerUpdatesChannel = null

  // Watch formData for changes
  watch(
    formData,
    (newValue) => {
      // Form data changed
    },
    { deep: true },
  )

  // Custom DOM event handlers for router-created/deleted (dispatched by websocket.js)
  const handleRouterCreated = (event) => {
    const router = event.detail?.router || event.detail
    if (!router?.id) return
    const exists = routers.value.some(r => String(r.id) === String(router.id))
    if (!exists) {
      routers.value.unshift(router)
    }
  }

  const handleRouterDeleted = (event) => {
    const id = event.detail?.router?.id || event.detail?.id
    if (!id) return
    routers.value = routers.value.filter(r => String(r.id) !== String(id))
    if (currentRouter.value && String(currentRouter.value.id) === String(id)) {
      showDetailsOverlay.value = false
      currentRouter.value = null
    }
  }

  const handleRouterUpdated = (event) => {
    const router = event.detail?.router || event.detail
    if (!router?.id) return
    const index = routers.value.findIndex(r => String(r.id) === String(router.id))
    if (index !== -1) {
      routers.value[index] = { ...routers.value[index], ...router }
    }
  }

  const handleRouterStatusUpdated = (event) => {
    const data = event.detail
    // Backend sends {routers: [...]} or single router in {router: ...} or flat structure
    const updates = data?.routers || (data?.router ? [data.router] : [data].filter(Boolean))
    if (!updates || updates.length === 0) return
    
    updates.forEach((update) => {
      if (!update?.id) return
      const index = routers.value.findIndex(r => String(r.id) === String(update.id))
      if (index !== -1) {
        routers.value[index] = {
          ...routers.value[index],
          status: update.status ?? routers.value[index].status,
          vpn_status: update.vpn_status ?? routers.value[index].vpn_status,
          vpn_last_handshake: update.vpn_last_handshake ?? routers.value[index].vpn_last_handshake,
          is_online: update.is_online ?? (update.status === 'online'),
          last_seen: update.last_seen ?? routers.value[index].last_seen,
        }
      }
      
      // Also update current router if details modal is open
      if (currentRouter.value && String(currentRouter.value.id) === String(update.id)) {
        currentRouter.value = {
          ...currentRouter.value,
          status: update.status ?? currentRouter.value.status,
          vpn_status: update.vpn_status ?? currentRouter.value.vpn_status,
          vpn_last_handshake: update.vpn_last_handshake ?? currentRouter.value.vpn_last_handshake,
          is_online: update.is_online ?? (update.status === 'online'),
          last_seen: update.last_seen ?? currentRouter.value.last_seen,
        }
      }
    })
  }

  const setupRealtimeUpdates = () => {
    try {
      const tenantId = authStore.tenantId
      if (!tenantId) return

      // Unsubscribe existing if any
      if (routerUpdatesChannel) {
        unsubscribeFromChannel(routerUpdatesChannel)
      }

      // Listen for create/delete/update events dispatched by websocket.js subscribeModuleChannels
      window.addEventListener('router-created', handleRouterCreated)
      window.addEventListener('router-deleted', handleRouterDeleted)
      window.addEventListener('router-updated', handleRouterUpdated)
      window.addEventListener('router-status-updated', handleRouterStatusUpdated)

      routerUpdatesChannel = `tenant.${tenantId}.router-updates`
      
      if (typeof subscribeToPrivateChannel !== 'function') {
        console.warn('subscribeToPrivateChannel is not a function, skipping WebSocket setup')
        return
      }
      
      // Note: RouterStatusUpdated is handled via CustomEvent from websocket.js
      // Only subscribe to RouterLiveDataUpdated here (no CustomEvent equivalent)
      subscribeToPrivateChannel(routerUpdatesChannel, {
        RouterLiveDataUpdated: (event) => {
          // Update router metrics
          const routerIndex = routers.value.findIndex(r => String(r.id) === String(event.router_id))
          if (routerIndex !== -1) {
            const router = routers.value[routerIndex]
            const newData = event.liveData || event.data
            
            routers.value[routerIndex] = {
              ...router,
              live_data: {
                ...(router.live_data || {}),
                ...newData
              },
              resources: {
                ...(router.resources || {}),
                ...newData
              }
            }
          }
          
          // Also update current router detail if open
          if (currentRouter.value && String(currentRouter.value.id) === String(event.router_id)) {
            const newData = event.liveData || event.data
            currentRouter.value = {
              ...currentRouter.value,
              live_data: {
                ...(currentRouter.value.live_data || {}),
                ...newData
              },
              resources: {
                ...(currentRouter.value.resources || {}),
                ...newData
              }
            }
          }
        }
      })
  } catch (err) {
    console.warn('setupRealtimeUpdates error:', err.message)
  }
  }

  const cleanupRealtimeUpdates = () => {
    window.removeEventListener('router-created', handleRouterCreated)
    window.removeEventListener('router-deleted', handleRouterDeleted)
    if (routerUpdatesChannel) {
      unsubscribe(routerUpdatesChannel)
      routerUpdatesChannel = null
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

  const fetchRouterMetricsBatch = async (routerIds) => {
    if (!Array.isArray(routerIds) || routerIds.length === 0) return {}

    try {
      const response = await axios.post('/routers/metrics/live', {
        router_ids: routerIds,
      })

      if (response.data?.success && response.data?.live_data && typeof response.data.live_data === 'object') {
        return response.data.live_data
      }

      return {}
    } catch (err) {
      console.warn('fetchRouterMetricsBatch error:', err.message, err.response?.data)
      return {}
    }
  }

  const fetchRouters = async () => {
    const isInitialLoad = routers.value.length === 0

    if (isInitialLoad) {
      loading.value = true
      listError.value = ''
    } else {
      refreshing.value = true
    }

    try {
      const response = await axios.get('/routers')
      
      // RouterController returns either a plain array, a paginated response {data:{data:[],total,...}},
      // or a flat {data:[]} shape. Unwrap to always get a plain array.
      const rawData = response.data
      const fetchedRouters = Array.isArray(rawData)
        ? rawData
        : Array.isArray(rawData?.data)
          ? rawData.data
          : Array.isArray(rawData?.data?.data)
            ? rawData.data.data
            : []
      
      // Filter out null/undefined entries that may occur after delete operations
      const validRouters = fetchedRouters.filter(r => r != null && typeof r === 'object')
      
      // Sort deterministically so order does not reshuffle between refreshes
      const sortedRouters = [...validRouters].sort((a, b) => {
        // Compare by name
        const nameA = String(a?.name ?? '').trim()
        const nameB = String(b?.name ?? '').trim()
        const byName = nameA.localeCompare(nameB, 'en', { numeric: true, sensitivity: 'base' })
        if (byName !== 0) return byName

        // Compare by IP address
        const parseIp = (ip) => {
          const cleanIp = String(ip ?? '').split('/')[0].trim()
          if (!cleanIp) return null
          const parts = cleanIp.split('.').map(p => Number(p))
          if (parts.length !== 4 || parts.some(n => Number.isNaN(n))) return null
          return parts
        }
        const aIp = parseIp(a?.ip_address)
        const bIp = parseIp(b?.ip_address)
        if (!aIp && !bIp) {
          // fall through to id comparison
        } else if (!aIp) {
          return 1
        } else if (!bIp) {
          return -1
        } else {
          for (let i = 0; i < 4; i++) {
            if (aIp[i] !== bIp[i]) return aIp[i] - bIp[i]
          }
        }

        // Compare by ID as final tiebreaker
        const idA = String(a?.id ?? '')
        const idB = String(b?.id ?? '')
        return idA.localeCompare(idB, 'en', { numeric: true, sensitivity: 'base' })
      })

      routers.value = sortedRouters

      const routerIds = sortedRouters.map((r) => String(r?.id ?? '')).filter((id) => id !== '')
      const requestToken = ++latestMetricsRequestToken

      // Do not block initial render; load DB rows first, then hydrate metrics asynchronously.
      void fetchRouterMetricsBatch(routerIds)
        .then((liveDataMap) => {
          if (requestToken !== latestMetricsRequestToken) {
            return
          }

          if (!liveDataMap || typeof liveDataMap !== 'object') {
            return
          }

          routers.value = routers.value.map((router) => {
            const rid = String(router?.id ?? '')
            const live = liveDataMap[rid]

            if (!live || typeof live !== 'object') {
              return router
            }

            return {
              ...router,
              live_data: {
                ...(router.live_data || {}),
                ...live,
              },
            }
          })
        })
        .catch((err) => {
          console.warn('fetchRouterMetricsBatch async merge error:', err.message, err.response?.data)
        })

      // Routers fetched and sorted successfully
      
      // Start listening for real-time updates
      setupRealtimeUpdates()
    } catch (err) {
      const errorMessage = err.response?.data?.error || 'Failed to fetch routers'
      console.error('fetchRouters error:', err.message, err.response?.data)

      // Only show the full-page error state if there is nothing to render.
      // During refresh, keep current list visible.
      if (routers.value.length === 0) {
        listError.value = errorMessage
      }
    } finally {
      loading.value = false
      refreshing.value = false
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

  const reprovisionRouter = async (id) => {
    try {
      // Optimistically mark router as pending in the list
      const idx = routers.value.findIndex(r => String(r.id) === String(id))
      if (idx !== -1) {
        routers.value[idx] = { ...routers.value[idx], status: 'pending', provisioning_stage: 'pending' }
      }
      if (currentRouter.value && String(currentRouter.value.id) === String(id)) {
        currentRouter.value = { ...currentRouter.value, status: 'pending', provisioning_stage: 'pending' }
      }

      const response = await axios.post(`/routers/${id}/reset-provisioning`, { start_probing: true })

      if (!response.data?.success) {
        throw new Error(response.data?.message || 'Reprovisioning request failed')
      }

      // Subscribe to provisioning progress channel for this router
      if (window.Echo) {
        const channelName = `router-provisioning.${id}`
        const existing = window.Echo.connector?.channels?.[`private-${channelName}`]
        if (!existing) {
          window.Echo.private(channelName)
            .listen('.RouterProvisioningProgress', (event) => {
              const routerIdx = routers.value.findIndex(r => String(r.id) === String(id))
              if (routerIdx !== -1 && event.status) {
                routers.value[routerIdx] = { ...routers.value[routerIdx], status: event.status }
              }
              if (currentRouter.value && String(currentRouter.value.id) === String(id) && event.status) {
                currentRouter.value = { ...currentRouter.value, status: event.status }
              }
            })
            .listen('.RouterProvisioned', (event) => {
              fetchRouters()
              window.Echo.leave(channelName)
            })
            .listen('.RouterProvisioningFailed', (event) => {
              const routerIdx = routers.value.findIndex(r => String(r.id) === String(id))
              if (routerIdx !== -1) {
                routers.value[routerIdx] = { ...routers.value[routerIdx], status: 'error' }
              }
              window.Echo.leave(channelName)
            })
        }
      }

      return response.data
    } catch (err) {
      // Revert optimistic status on failure
      await fetchRouters()
      console.error('reprovisionRouter error:', err.message, err.response?.data)
      throw err
    }
  }

  const openCreateOverlay = () => {
    console.log('>>> openCreateOverlay START <<<')
    showFormOverlay.value = true
    console.log('showFormOverlay set to:', showFormOverlay.value)
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
    console.log('openCreateOverlay complete, formData:', JSON.parse(JSON.stringify(formData.value)))
    console.log('>>> openCreateOverlay END <<<')
  }

  const openEditOverlay = (router) => {
    editRouter(router)
  }

  const openDetails = async (router) => {
    try {
      console.log('openDetails called for router:', router.id, router.name)
      // Immediately show modal with DB data
      currentRouter.value = JSON.parse(JSON.stringify(router))
      showDetailsOverlay.value = true
      detailsLoading.value = false // DB data is already available
      
      // Fetch fresh data asynchronously (non-blocking)
      fetchRouterDetails(router.id)
    } catch (error) {
      console.error('Error in openDetails:', error)
      currentRouter.value = router
      showDetailsOverlay.value = true
    }
  }

  const fetchRouterDetails = async (routerId) => {
    refreshing.value = true
    detailsError.value = ''
    try {
      console.log('Fetching DB details for router:', routerId)
      
      // Step 1: Fetch DB data (fast, always available)
      const response = await axios.get(`/routers/${routerId}/details`)
      console.log('Fetched router DB details:', response.data)

      const data = response.data || {}
      const routerPayload = data.router || {}
      
      // Update with DB data immediately
      currentRouter.value = {
        ...currentRouter.value,
        ...routerPayload,
        interfaces: data.interfaces || currentRouter.value?.interfaces || [],
        hotspots: data.hotspots || currentRouter.value?.hotspots || [],
        radius_servers: data.radius_servers || currentRouter.value?.radius_servers || [],
        services: data.services || currentRouter.value?.services || [],
        access_points: data.access_points || currentRouter.value?.access_points || [],
      }

      // Step 2: Fetch live metrics from VictoriaMetrics
      console.log('Fetching live metrics from VictoriaMetrics...')
      let hasMetrics = false
      try {
        const metricsResponse = await axios.get(`/routers/${routerId}/metrics/live`)
        if (metricsResponse.data?.success && metricsResponse.data?.live_data && typeof metricsResponse.data.live_data === 'object') {
          const metricsLive = metricsResponse.data.live_data
          console.log('Metrics from VictoriaMetrics:', metricsLive)

          if (Object.keys(metricsLive).length > 0) {
            hasMetrics = true
            currentRouter.value = {
              ...currentRouter.value,
              live_data: {
                ...(currentRouter.value?.live_data || {}),
                ...metricsLive,
              },
              resources: {
                ...(currentRouter.value?.resources || {}),
                ...metricsLive,
              },
            }
          }
        }
      } catch (err) {
        console.warn('Could not fetch router metrics:', err.message)
        // Don't set error - DB data is still valid
      }

      if (!hasMetrics) {
        console.log('Falling back to router live-data endpoint...')
        try {
          const liveResponse = await axios.get(`/routers/${routerId}/live-data`)
          const fallbackLive = liveResponse.data?.resources || liveResponse.data?.live_data || {}

          if (Object.keys(fallbackLive).length > 0) {
            currentRouter.value = {
              ...currentRouter.value,
              live_data: {
                ...(currentRouter.value?.live_data || {}),
                ...fallbackLive,
              },
              resources: {
                ...(currentRouter.value?.resources || {}),
                ...fallbackLive,
              },
              active_connections:
                liveResponse.data?.active_connections ?? currentRouter.value?.active_connections,
            }
          }
        } catch (err) {
          console.warn('Could not fetch router live data fallback:', err.message)
        }
      }

      if (data?.success === false && data?.error) {
        detailsError.value = data.error
      }
    } catch (error) {
      console.warn('Could not fetch router details:', error.message)
      detailsError.value =
        error.response?.data?.error || error.message || 'Failed to fetch router details'
    } finally {
      refreshing.value = false
    }
  }

  const refreshDetails = async () => {
    if (currentRouter.value && currentRouter.value.id) {
      await fetchRouterDetails(currentRouter.value.id)
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

  onUnmounted(() => {
    cleanupRealtimeUpdates()
  })

  return {
    routers,
    loading,
    refreshing,
    listError,
    formError,
    detailsError,
    detailsLoading,
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
    reprovisionRouter,
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
