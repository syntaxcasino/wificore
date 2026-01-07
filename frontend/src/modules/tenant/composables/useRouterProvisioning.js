import { ref, reactive, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from '@/services/api/axios'
import { usePusher } from '@/composables/usePusher'

export function useRouterProvisioning(props, emit) {
  // Get Pusher instance for WebSocket events
  const { pusher, isConnected } = usePusher()
  // Reactive data
  const routerName = ref('')
  const formSubmitted = ref(false)
  const formSubmitting = ref(false)

  // Provisioning state
  const currentStage = ref(1)
  const provisioningProgress = ref(0)
  const provisioningStatus = ref('Initializing')
  const provisioningRouter = ref(null)
  const initialConfig = ref('')
  const waitingForJobCompletion = ref(false)
  const provisioningLogs = ref([])

  // VPN configuration - MANDATORY for all routers
  const enableVpn = ref(true) // Always true
  const vpnConfig = ref(null)
  const vpnScript = ref('')
  const vpnConnected = ref(false)
  const vpnConnectivityStatus = ref('pending') // pending, checking, verified, failed
  const vpnConnectivityAttempts = ref(0)
  const vpnLatencyMs = ref(null)

  // Service configuration
  const enableHotspot = ref(false)
  const enablePPPoE = ref(false)
  const serviceScript = ref('')
  const availableInterfaces = ref([])
  const selectedHotspotInterfaces = ref([])
  const selectedPPPoEInterfaces = ref([])
  const connectionStatus = ref('Waiting')
  const deploymentFailed = ref(false)
  const deploymentTimedOut = ref(false)
  
  // Computed for connection status styling
  const connectionStatusClass = computed(() => {
    const classes = {
      'Waiting': 'bg-yellow-500',
      'Connecting': 'bg-blue-500',
      'Connected': 'bg-green-500',
      'Failed': 'bg-red-500'
    }
    return classes[connectionStatus.value] || 'bg-gray-500'
  })
  
  const connectionStatusTextClass = computed(() => {
    const classes = {
      'Waiting': 'text-yellow-600',
      'Connecting': 'text-blue-600',
      'Connected': 'text-green-600',
      'Failed': 'text-red-600'
    }
    return classes[connectionStatus.value] || 'text-gray-600'
  })

  // Hotspot configuration
  const hotspotConfig = reactive({
    ssid: '',
    password: '',
    interface: 'wlan1',
    addressPool: '192.168.1.100-192.168.1.200',
  })

  // PPPoE configuration
  const pppoeConfig = reactive({
    interface: 'ether1',
    serviceName: 'pppoe-service',
    ipPool: '192.168.2.100-192.168.2.200',
  })

  // Computed properties
  const currentStageText = computed(() => {
    const stages = {
      1: 'Router Identity',
      2: 'VPN Connection',
      3: 'Provisioning Complete',
    }
    return stages[currentStage.value] || 'Unknown'
  })

  const deploymentStatusClass = computed(() => {
    const status = provisioningStatus.value.toLowerCase()
    if (status.includes('success') || status.includes('complete')) {
      return 'text-green-600'
    } else if (status.includes('error') || status.includes('fail')) {
      return 'text-red-600'
    } else if (status.includes('progress') || status.includes('deploying')) {
      return 'text-blue-600'
    }
    return 'text-gray-600'
  })

  const deploymentStatus = computed(() => provisioningStatus.value)

  // Combined script: Only show minimal fetch command (VPN is in the .rsc file)
  const combinedScript = computed(() => {
    return initialConfig.value || ''
  })

  // Methods
  const createRouterWithConfig = async () => {
    if (!routerName.value) {
      formSubmitted.value = true
      return
    }

    formSubmitting.value = true
    try {
      const response = await axios.post('/routers', {
        name: routerName.value,
      })

      // Backend returns minimal fetch command only (VPN is in the .rsc file)
      console.log('🔍 API Response:', response.data)
      
      if (response.data && response.data.id) {
        provisioningRouter.value = response.data
        initialConfig.value = response.data.connectivity_script || ''
        
        console.log('📝 Fetch command set:', initialConfig.value ? 'YES' : 'NO')
        
        // VPN is automatically included in the .rsc file - no need to display it
        addLog('success', 'Router created with VPN configuration (included in .rsc file)')
        provisioningProgress.value = 30
        provisioningStatus.value = 'Router ready - VPN included in configuration file'
        
        console.log('✅ Fetch command ready:', combinedScript.value ? 'READY' : 'EMPTY')
        
        // Stay on stage 1 to show the fetch command
        // User must click "Continue" button to proceed
      }
    } catch (error) {
      console.error('Error creating router:', error)
      provisioningStatus.value = 'Error creating router'
    } finally {
      formSubmitting.value = false
    }
  }

  const pollVpnConfiguration = () => {
    const maxAttempts = 30 // 30 attempts = 1 minute
    let attempts = 0
    
    const pollInterval = setInterval(async () => {
      attempts++
      
      try {
        // Get VPN configs for this router
        const response = await axios.get('/vpn')
        const configs = response.data.data || []
        const routerVpnConfig = configs.find(c => c.router_id === provisioningRouter.value.id)
        
        if (routerVpnConfig) {
          clearInterval(pollInterval)
          vpnConfig.value = routerVpnConfig
          
          // Fetch the full VPN config with scripts
          const detailResponse = await axios.get(`/vpn/${routerVpnConfig.id}`)
          if (detailResponse.data.success) {
            vpnScript.value = detailResponse.data.data.mikrotik_script
            addLog('success', 'VPN configuration ready!')
            provisioningRouter.value.vpn_ip = detailResponse.data.data.client_ip
            provisioningRouter.value.vpn_status = detailResponse.data.data.status
          }
        } else if (attempts >= maxAttempts) {
          clearInterval(pollInterval)
          addLog('warning', 'VPN configuration timeout - check manually')
        }
      } catch (error) {
        if (attempts >= maxAttempts) {
          clearInterval(pollInterval)
          addLog('error', 'Failed to fetch VPN configuration')
        }
      }
    }, 2000) // Check every 2 seconds
  }

  const continueToMonitoring = async () => {
    // VPN configuration is automatically included in the .rsc file
    // Backend dispatches VerifyVpnConnectivityJob which will broadcast events
    // Frontend listens for WebSocket events to know when VPN is ready
    
    addLog('success', '✅ Router created successfully!')
    addLog('info', '📋 Configuration script ready - copy and paste it to your MikroTik terminal')
    addLog('info', '⏱️ After applying the script, the router will:')
    addLog('info', '   1. Download the full configuration file (.rsc)')
    addLog('info', '   2. Establish VPN tunnel (30-60 seconds)')
    addLog('info', '   3. Connect to the management system')
    addLog('info', '🔄 Monitoring for VPN connection...')
    
    // Move to stage 2: VPN connectivity verification
    currentStage.value = 2
    provisioningProgress.value = 40
    provisioningStatus.value = 'Apply script on router - Waiting for VPN connection'
    vpnConnectivityStatus.value = 'checking'
    
    // Subscribe to VPN connectivity events
    subscribeToVpnEvents()
    
    // Note: No polling needed! Backend job will broadcast events via WebSocket
    // Continue button will be enabled when vpn.connectivity.verified event is received
  }

  const probeVpnConnectivity = async () => {
    try {
      connectionStatus.value = 'Connecting'
      addLog('info', 'Waiting for VPN tunnel to establish...')
      
      // Poll the VPN status
      const maxAttempts = 60 // 60 attempts = 2 minutes
      let attempts = 0
      
      const pollInterval = setInterval(async () => {
        attempts++
        
        try {
          // Check VPN configuration status
          const response = await axios.get(`/vpn/${vpnConfig.value.id}`)
          
          addLog('info', `VPN probe attempt ${attempts}/${maxAttempts}`)
          
          // Check if VPN is connected
          if (response.data.data.is_connected || response.data.data.status === 'active') {
            clearInterval(pollInterval)
            connectionStatus.value = 'Connected'
            vpnConnected.value = true
            
            addLog('success', `VPN connected! Router reachable at ${vpnConfig.value.client_ip}`)
            
            // Now verify router connectivity via VPN
            provisioningStatus.value = 'VPN connected - Verifying router access...'
            await probeRouterConnectivity()
            
          } else if (attempts >= maxAttempts) {
            clearInterval(pollInterval)
            connectionStatus.value = 'Failed'
            addLog('error', 'VPN connection timeout - Please verify VPN script was applied')
            provisioningStatus.value = 'VPN connection timeout - Check VPN configuration'
          } else {
            provisioningStatus.value = `Waiting for VPN connection... (${attempts}/${maxAttempts})`
          }
        } catch (error) {
          if (attempts >= maxAttempts) {
            clearInterval(pollInterval)
            connectionStatus.value = 'Failed'
            addLog('error', 'Failed to verify VPN connectivity')
            provisioningStatus.value = 'VPN verification failed - Please check configuration'
          }
        }
      }, 2000) // Check every 2 seconds
      
    } catch (error) {
      console.error('Error probing VPN:', error)
      connectionStatus.value = 'Failed'
      addLog('error', 'Error during VPN connectivity probe')
      provisioningStatus.value = 'Error verifying VPN connectivity'
    }
  }

  const probeRouterConnectivity = async () => {
    try {
      connectionStatus.value = 'Connecting'
      addLog('info', 'Starting connectivity probe...')
      
      // Poll the router status endpoint
      const maxAttempts = 30 // 30 attempts = 1 minute
      let attempts = 0
      
      const pollInterval = setInterval(async () => {
        attempts++
        
        try {
          const response = await axios.get(`/routers/${provisioningRouter.value.id}/status`)
          
          addLog('info', `Probe attempt ${attempts}/${maxAttempts}`)
          
          // Check if router is connected (accepts 'connected' or 'online')
          if (response.data.status === 'connected' || response.data.status === 'online') {
            clearInterval(pollInterval)
            connectionStatus.value = 'Connected'
            
            // Update router status in local state
            if (provisioningRouter.value) {
              provisioningRouter.value.status = response.data.status
            }
            
            // Fetch available interfaces
            const interfacesResponse = await axios.get(`/routers/${provisioningRouter.value.id}/interfaces`)
            
            if (interfacesResponse.data.interfaces) {
              availableInterfaces.value = interfacesResponse.data.interfaces
              addLog('success', `Connected! Found ${interfacesResponse.data.interfaces.length} interfaces`)
              
              // Move to stage 3 with interfaces
              currentStage.value = 3
              provisioningProgress.value = 75
              provisioningStatus.value = 'Router connected - Configure services'
            }
          } else if (attempts >= maxAttempts) {
            clearInterval(pollInterval)
            connectionStatus.value = 'Failed'
            addLog('error', 'Connection timeout - Router not responding')
            provisioningStatus.value = 'Connection timeout - Please verify the script was applied'
          } else {
            provisioningStatus.value = `Waiting for router... (${attempts}/${maxAttempts})`
          }
        } catch (error) {
          if (attempts >= maxAttempts) {
            clearInterval(pollInterval)
            connectionStatus.value = 'Failed'
            addLog('error', 'Failed to connect to router')
            provisioningStatus.value = 'Connection failed - Please check router configuration'
          }
        }
      }, 2000) // Check every 2 seconds
      
    } catch (error) {
      console.error('Error probing router:', error)
      connectionStatus.value = 'Failed'
      addLog('error', 'Error during connectivity probe')
      provisioningStatus.value = 'Error verifying connectivity'
    }
  }

  const previousStage = () => {
    if (currentStage.value > 1) {
      currentStage.value--
      provisioningProgress.value = (currentStage.value - 1) * 25
    }
  }

  const generateServiceConfig = async () => {
    formSubmitting.value = true
    try {
      const payload = {
        enable_hotspot: enableHotspot.value,
        enable_pppoe: enablePPPoE.value,
      }

      // Add hotspot configuration if enabled
      if (enableHotspot.value) {
        payload.hotspot_interfaces = selectedHotspotInterfaces.value
        payload.portal_title = hotspotConfig.portalTitle || 'WiFi Hotspot'
        payload.login_method = hotspotConfig.loginMethod || 'mac'
      }

      // Add PPPoE configuration if enabled
      if (enablePPPoE.value) {
        payload.pppoe_interfaces = selectedPPPoEInterfaces.value
        payload.pppoe_service_name = pppoeConfig.serviceName || 'pppoe-service'
        payload.pppoe_ip_pool = pppoeConfig.ipPool || '192.168.2.100-192.168.2.200'
      }

      const response = await axios.post(`/routers/${provisioningRouter.value.id}/generate-service-config`, payload)

      if (response.data.success) {
        serviceScript.value = response.data.service_script || response.data.script
        currentStage.value = 4
        provisioningProgress.value = 90
        provisioningStatus.value = 'Configuration generated - Ready to deploy'
        addLog('success', 'Service configuration generated successfully')
        addLog('info', `Script length: ${serviceScript.value?.length || 0} characters`)
      }
    } catch (error) {
      console.error('Error generating config:', error)
      const errorMsg = error.response?.data?.error || 'Error generating configuration'
      provisioningStatus.value = errorMsg
      addLog('error', errorMsg)
    } finally {
      formSubmitting.value = false
    }
  }

  const deployConfiguration = async () => {
    deploymentFailed.value = false
    deploymentTimedOut.value = false
    formSubmitting.value = true
    waitingForJobCompletion.value = true
    provisioningStatus.value = 'Deploying configuration...'
    addLog('info', 'Starting deployment...')

    try {
      // Determine service type based on what's enabled
      let serviceType = 'hotspot'
      if (enablePPPoE.value && !enableHotspot.value) {
        serviceType = 'pppoe'
      } else if (enableHotspot.value && enablePPPoE.value) {
        serviceType = 'hotspot' // Default to hotspot if both enabled
      }

      // Convert script to commands array (split by newlines, filter empty)
      const commands = serviceScript.value
        .split('\n')
        .map(cmd => cmd.trim())
        .filter(cmd => cmd.length > 0 && !cmd.startsWith('#'))

      const response = await axios.post(`/routers/${provisioningRouter.value.id}/deploy-service-config`, {
        service_type: serviceType,
        commands: commands,
      })

      if (response.data.success) {
        addLog('success', 'Deployment job dispatched')
        provisioningStatus.value = 'Deployment in progress...'
        currentStage.value = 5
        provisioningProgress.value = 95  // Not 100% until confirmed complete
        
        // Poll router provisioning status instead of job status
        pollProvisioningStatus()
      }
    } catch (error) {
      console.error('Error deploying config:', error)
      const errorMsg = error.response?.data?.error || 'Deployment failed'
      provisioningStatus.value = errorMsg
      addLog('error', errorMsg)
      waitingForJobCompletion.value = false
      formSubmitting.value = false
    }
  }

  const pollProvisioningStatus = () => {
    const maxAttempts = 30 // 30 attempts = 1 minute
    let attempts = 0
    
    const pollInterval = setInterval(async () => {
      attempts++
      
      try {
        const response = await axios.get(`/routers/${provisioningRouter.value.id}/provisioning-status`)
        
        addLog('info', `Checking deployment status... (${attempts}/${maxAttempts})`)
        
        if (response.data.status === 'completed') {
          clearInterval(pollInterval)
          provisioningProgress.value = 100  // Set to 100% only when complete
          provisioningStatus.value = 'Deployment completed successfully'
          addLog('success', 'Router provisioned successfully!')
          waitingForJobCompletion.value = false
          formSubmitting.value = false
          
          // Update router status
          if (provisioningRouter.value) {
            provisioningRouter.value.status = 'online'
          }
          
          // Refresh router list
          emit('refresh-routers')
        } else if (response.data.status === 'failed') {
          clearInterval(pollInterval)
          deploymentFailed.value = true
          provisioningStatus.value = 'Deployment failed'
          addLog('error', response.data.error || 'Deployment failed')
          waitingForJobCompletion.value = false
          formSubmitting.value = false
        } else if (attempts >= maxAttempts) {
          clearInterval(pollInterval)
          deploymentTimedOut.value = true
          provisioningStatus.value = 'Deployment timeout - check router manually'
          addLog('warning', 'Deployment status check timed out')
          waitingForJobCompletion.value = false
          formSubmitting.value = false
        }
      } catch (error) {
        console.error('Error checking deployment status:', error)
        if (attempts >= maxAttempts) {
          clearInterval(pollInterval)
          addLog('error', 'Failed to check deployment status')
          waitingForJobCompletion.value = false
          formSubmitting.value = false
        }
      }
    }, 2000) // Check every 2 seconds
  }

  const pollJobStatus = async (jobId) => {
    const pollInterval = setInterval(async () => {
      try {
        const response = await axios.get(`/jobs/${jobId}/status`)
        const status = response.data.status

        provisioningStatus.value = response.data.message || status

        if (status === 'completed') {
          clearInterval(pollInterval)
          provisioningProgress.value = 100
          provisioningStatus.value = 'Deployment completed successfully'
          waitingForJobCompletion.value = false
          formSubmitting.value = false
          emit('refresh-routers')
        } else if (status === 'failed') {
          clearInterval(pollInterval)
          provisioningStatus.value = 'Deployment failed'
          waitingForJobCompletion.value = false
          formSubmitting.value = false
        }
      } catch (error) {
        clearInterval(pollInterval)
        console.error('Error polling job status:', error)
        provisioningStatus.value = 'Error checking deployment status'
        waitingForJobCompletion.value = false
        formSubmitting.value = false
      }
    }, 2000)
  }

  const addLog = (level, message) => {
    provisioningLogs.value.push({
      timestamp: new Date(),
      level,
      message,
    })
  }

  const clearLogs = () => {
    provisioningLogs.value = []
  }

  const formatLogTime = (timestamp) => {
    return new Date(timestamp).toLocaleTimeString()
  }

  const getLogLevelClass = (level) => {
    const classes = {
      info: 'text-blue-600',
      success: 'text-green-600',
      warning: 'text-yellow-600',
      error: 'text-red-600',
    }
    return classes[level] || 'text-gray-600'
  }

  const copyToClipboard = async (text) => {
    try {
      const payload = typeof text === 'string' ? text : (text ?? '').toString()
      if (!payload || payload.trim().length === 0) {
        addLog('warning', 'Nothing to copy yet. Script not ready.')
        return false
      }

      // Primary: modern async clipboard API
      if (navigator.clipboard && window.isSecureContext) {
        await navigator.clipboard.writeText(payload)
        addLog('success', 'Script copied to clipboard')
        return true
      }

      // Fallback: hidden textarea and execCommand
      const el = document.createElement('textarea')
      el.value = payload
      el.setAttribute('readonly', '')
      el.style.position = 'absolute'
      el.style.left = '-9999px'
      document.body.appendChild(el)
      el.select()
      const ok = document.execCommand('copy')
      document.body.removeChild(el)
      if (ok) {
        addLog('success', 'Script copied to clipboard')
        return true
      }

      addLog('error', 'Copy operation failed')
      return false
    } catch (error) {
      console.error('Failed to copy:', error)
      addLog('error', 'Failed to copy script')
      return false
    }
  }

  const toggleInterfaceSelection = (serviceType, interfaceName) => {
    if (serviceType === 'hotspot') {
      const index = selectedHotspotInterfaces.value.indexOf(interfaceName)
      if (index > -1) {
        selectedHotspotInterfaces.value.splice(index, 1)
      } else {
        selectedHotspotInterfaces.value.push(interfaceName)
      }
    } else if (serviceType === 'pppoe') {
      const index = selectedPPPoEInterfaces.value.indexOf(interfaceName)
      if (index > -1) {
        selectedPPPoEInterfaces.value.splice(index, 1)
      } else {
        selectedPPPoEInterfaces.value.push(interfaceName)
      }
    }
  }

  const retryDeployment = () => {
    addLog('info', 'Retrying deployment...')
    deploymentFailed.value = false
    deploymentTimedOut.value = false
    
    if (deploymentTimedOut.value) {
      // If timed out, just retry the status check
      pollProvisioningStatus()
    } else {
      // If failed, retry the entire deployment
      deployConfiguration()
    }
  }

  // Subscribe to VPN connectivity events via WebSocket
  const subscribeToVpnEvents = () => {
    const user = JSON.parse(localStorage.getItem('user'))
    if (!user || !user.tenant_id) {
      addLog('error', 'Cannot subscribe to VPN events: No tenant ID')
      return
    }

    const channelName = `tenant.${user.tenant_id}.vpn`
    addLog('info', `Subscribing to VPN events on channel: ${channelName}`)

    try {
      const channel = pusher.subscribe(channelName)

      // Listen for connectivity checking events (progress updates)
      channel.bind('vpn.connectivity.checking', (data) => {
        console.log('VPN connectivity checking:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          vpnConnectivityAttempts.value = data.attempt
          const progress = data.progress || 0
          
          // Only log every 5th attempt to avoid spam
          if (data.attempt % 5 === 0 || data.attempt === 1) {
            addLog('info', `🔍 Checking VPN connectivity... Attempt ${data.attempt}/${data.max_attempts}`)
          }
          
          // Update progress bar
          provisioningProgress.value = 40 + (progress * 0.2) // 40% to 60%
          provisioningStatus.value = `Verifying VPN connection (${progress.toFixed(0)}%) - Attempt ${data.attempt}/${data.max_attempts}`
        }
      })

      // Listen for connectivity verified event (SUCCESS!)
      channel.bind('vpn.connectivity.verified', (data) => {
        console.log('VPN connectivity verified:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          vpnConnectivityStatus.value = 'verified'
          vpnConnected.value = true
          vpnLatencyMs.value = data.connectivity.latency_ms
          
          addLog('success', `✅ VPN connectivity verified! Latency: ${data.connectivity.latency_ms.toFixed(1)}ms`)
          addLog('success', `Router is reachable via VPN at ${data.client_ip}`)
          
          provisioningProgress.value = 60
          provisioningStatus.value = 'VPN connected - Discovering interfaces...'
          connectionStatus.value = 'Connected'
          
          // Don't unsubscribe yet - wait for interfaces.discovered event
          addLog('info', 'Waiting for interface discovery...')
        }
      })

      // Listen for router interfaces discovered event (AUTO-DISCOVERY!)
      channel.bind('router.interfaces.discovered', (data) => {
        console.log('Router interfaces discovered:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          availableInterfaces.value = data.interfaces || []
          
          addLog('success', `✅ Discovered ${data.interfaces.length} interfaces`)
          addLog('info', `Router: ${data.router_info.model} (${data.router_info.version})`)
          
          // Update router info
          if (provisioningRouter.value) {
            provisioningRouter.value.status = 'online'
            provisioningRouter.value.model = data.router_info.model
            provisioningRouter.value.os_version = data.router_info.version
          }
          
          // Provisioning complete - show SSH access instructions
          currentStage.value = 3
          provisioningProgress.value = 100
          provisioningStatus.value = 'Router provisioned successfully - SSH access ready'
          
          // Unsubscribe from VPN channel
          pusher.unsubscribe(channelName)
          
          addLog('success', '🎉 Router provisioning complete!')
          addLog('info', '🔐 You can now SSH to the router for configuration')
          addLog('info', `📍 SSH Address: ${provisioningRouter.value.vpn_ip || data.router_info.vpn_ip}`)
          addLog('info', `👤 Username: admin`)
          addLog('info', `🔑 Password: [Your router admin password]`)
          addLog('info', '💡 Use WinBox or SSH client to configure services (Hotspot, PPPoE, etc.)')
        }
      })

      // Listen for connectivity failed event (TIMEOUT/ERROR)
      channel.bind('vpn.connectivity.failed', (data) => {
        console.log('VPN connectivity failed:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          vpnConnectivityStatus.value = 'failed'
          vpnConnected.value = false
          
          addLog('error', `❌ VPN connectivity verification failed`)
          addLog('error', data.reason || 'Connection timeout after 120 seconds')
          addLog('warning', '⚠️ Troubleshooting steps:')
          addLog('warning', '1. Verify you copied and pasted the FULL script to the router')
          addLog('warning', '2. Check router has active internet connectivity')
          addLog('warning', '3. Ensure firewall allows UDP traffic on the VPN port')
          addLog('warning', '4. Check router terminal for any error messages')
          addLog('info', '💡 You can retry by clicking "Continue" again')
          
          provisioningStatus.value = 'VPN connectivity failed - Check troubleshooting steps'
          connectionStatus.value = 'Failed'
          
          // Unsubscribe from channel
          pusher.unsubscribe(channelName)
        }
      })

      addLog('success', 'Subscribed to VPN connectivity events')
    } catch (error) {
      console.error('Failed to subscribe to VPN events:', error)
      addLog('error', 'Failed to subscribe to VPN events: ' + error.message)
    }
  }

  const resetForm = () => {
    routerName.value = ''
    formSubmitted.value = false
    formSubmitting.value = false
    currentStage.value = 1
    provisioningProgress.value = 0
    provisioningStatus.value = 'Initializing'
    provisioningRouter.value = null
    initialConfig.value = ''
    waitingForJobCompletion.value = false
    provisioningLogs.value = []
    enableVpn.value = true // Always true
    vpnConfig.value = null
    vpnScript.value = ''
    vpnConnected.value = false
    vpnConnectivityStatus.value = 'pending'
    vpnConnectivityAttempts.value = 0
    vpnLatencyMs.value = null
    enableHotspot.value = false
    enablePPPoE.value = false
    serviceScript.value = ''
    Object.assign(hotspotConfig, {
      ssid: '',
      password: '',
      interface: 'wlan1',
      addressPool: '192.168.1.100-192.168.1.200',
    })
    Object.assign(pppoeConfig, {
      interface: 'ether1',
      serviceName: 'pppoe-service',
      ipPool: '192.168.2.100-192.168.2.200',
    })
  }

  // Watch for overlay close
  watch(() => props.showFormOverlay, (newVal) => {
    if (!newVal) {
      resetForm()
    }
  })

  return {
    // State
    routerName,
    formSubmitted,
    formSubmitting,
    currentStage,
    provisioningProgress,
    provisioningStatus,
    provisioningRouter,
    initialConfig,
    waitingForJobCompletion,
    provisioningLogs,
    enableVpn,
    vpnConfig,
    vpnScript,
    vpnConnected,
    vpnConnectivityStatus,
    vpnConnectivityAttempts,
    vpnLatencyMs,
    enableHotspot,
    enablePPPoE,
    serviceScript,
    availableInterfaces,
    selectedHotspotInterfaces,
    selectedPPPoEInterfaces,
    connectionStatus,
    deploymentFailed,
    deploymentTimedOut,
    hotspotConfig,
    pppoeConfig,
    
    // Computed
    currentStageText,
    deploymentStatusClass,
    deploymentStatus,
    connectionStatusClass,
    connectionStatusTextClass,
    combinedScript,
    
    // Methods
    createRouterWithConfig,
    continueToMonitoring,
    previousStage,
    generateServiceConfig,
    deployConfiguration,
    addLog,
    clearLogs,
    formatLogTime,
    getLogLevelClass,
    copyToClipboard,
    toggleInterfaceSelection,
    retryDeployment,
    resetForm,
  }
}
