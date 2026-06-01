import { ref, reactive, computed, watch, onUnmounted } from 'vue'
import axios from '@/modules/common/services/api/axios'

export function useRouterProvisioning(props, emit) {
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
  const lastDryRunSummary = ref(null)
  const availableInterfaces = ref([])
  const selectedHotspotInterfaces = ref([])
  const selectedPPPoEInterfaces = ref([])
  const serviceMappings = ref({})
  const mappingDeploying = ref(false)
  const mappingStatus = ref('')
  const mappingErrors = ref([])
  const mappingDeployedServices = ref([])
  const connectionStatus = ref('Waiting')
  const deploymentFailed = ref(false)
  const deploymentTimedOut = ref(false)
  // Active WS channel refs — cleaned up on unmount / reset
  let _provisioningChannel = null
  let _serviceDeployChannel = null
  // VPN/routers channels opened by subscribeToVpnEvents() — also cleaned up
  let _vpnChannelName = null
  let _routersChannelName = null
  // Catch-up fallback timer for VPN stage (cleared when WS event arrives)
  let _vpnCatchupTimer = null
  let _vpnFallbackInterval = null

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
    
    // Subscribe to VPN connectivity events — backend broadcasts all state transitions
    subscribeToVpnEvents()
  }


  const previousStage = () => {
    if (currentStage.value > 1) {
      // Leaving stage 2 (VPN wait) — clean up VPN channel subscriptions and catch-up timers
      if (currentStage.value === 2) {
        _stopVpnFallback()
        if (_vpnChannelName) { window.Echo?.leave(_vpnChannelName); _vpnChannelName = null }
        if (_routersChannelName) { window.Echo?.leave(_routersChannelName); _routersChannelName = null }
      }
      currentStage.value--
      provisioningProgress.value = (currentStage.value - 1) * 25
    }
  }

  const setServiceMapping = (interfaceName, serviceType) => {
    serviceMappings.value = {
      ...(serviceMappings.value || {}),
      [interfaceName]: serviceType,
    }
  }

  /**
   * Subscribe to provisioning.progress events on a per-router private WS channel.
   * Resolves/rejects a Promise when the terminal stage is reached.
   * The backend (DeployRouterServiceJob) broadcasts RouterProvisioningProgress
   * with stage 'service_deploy_completed' or 'service_deploy_failed'.
   */
  const waitForServiceDeploymentViaWs = (routerId, serviceCount) => {
    return new Promise((resolve, reject) => {
      const channelName = `router-provisioning.${routerId}`
      let deployedCount = 0

      _serviceDeployChannel = window.Echo?.private(channelName)
      if (!_serviceDeployChannel) {
        // Echo not available — fall back to success immediately (job runs server-side)
        resolve()
        return
      }

      // 2 minute hard timeout in case a WS message is missed
      const timeout = setTimeout(() => {
        _serviceDeployChannel = null
        window.Echo?.leave(channelName)
        deploymentTimedOut.value = true
        mappingDeploying.value = false
        mappingStatus.value = 'Deployment status check timed out'
        addLog('warning', 'Deployment status check timed out — check routers page')
        resolve()
      }, 120_000)

      _serviceDeployChannel.listen('.provisioning.progress', (data) => {
        const stage = data.stage || ''
        const prog = typeof data.progress === 'number' && !isNaN(data.progress) ? data.progress : 0
        provisioningProgress.value = Math.min(99, 85 + prog * 0.15)
        if (data.message) addLog('info', data.message)

        if (stage.endsWith('_completed')) {
          deployedCount++
          if (deployedCount >= serviceCount) {
            clearTimeout(timeout)
            window.Echo?.leave(channelName)
            _serviceDeployChannel = null
            resolve()
          }
        } else if (stage.endsWith('_failed')) {
          clearTimeout(timeout)
          window.Echo?.leave(channelName)
          _serviceDeployChannel = null
          reject(new Error(data.message || 'Service deployment failed'))
        }
      })

      _serviceDeployChannel.listen('.provisioning.failed', (data) => {
        clearTimeout(timeout)
        window.Echo?.leave(channelName)
        _serviceDeployChannel = null
        reject(new Error(data.message || 'Provisioning failed'))
      })
    })
  }

  const confirmServiceMappingAndDeploy = async () => {
    if (!provisioningRouter.value?.id) {
      addLog('error', 'Router is not ready for service deployment')
      return
    }

    const routerId = provisioningRouter.value.id
    mappingErrors.value = []
    mappingDeployedServices.value = []

    const entries = Object.entries(serviceMappings.value || {})
    const selected = entries.filter(([, type]) => type && type !== 'none')

    const selectedTypes = [...new Set(selected.map(([, type]) => type))]

    if (!selected.length) {
      addLog('warning', 'Select at least one service to deploy')
      return
    }

    mappingDeploying.value = true
    provisioningProgress.value = 85
    provisioningStatus.value = 'Configuring services...'
    mappingStatus.value = 'Configuring services...'
    addLog('info', `Configuring ${selected.length} interface mapping(s) (${selectedTypes.join(', ')})...`)

    try {
      const configured = []
      for (const [iface, type] of selected) {
        const resp = await axios.post(`/routers/${routerId}/services/configure`, {
          interface: iface,
          service_type: type,
          advanced_options: {},
        })

        if (!resp.data?.success) {
          const msg = resp.data?.message || ''
          const alreadyDeployed = /already.deployed|already.exists|already.configured/i.test(msg)
          if (alreadyDeployed && resp.data?.service) {
            configured.push(resp.data.service)
            addLog('info', `${type} on ${iface} is already deployed — skipping`)
            continue
          }
          throw new Error(msg || `Failed to configure ${type} on ${iface}`)
        }

        const validation = resp.data.validation
        if (validation && validation.valid === false) {
          const errorDetails = validation.errors && validation.errors.length > 0 
            ? validation.errors.join(', ') 
            : `Validation failed for ${type} on ${iface}`
          throw new Error(errorDetails)
        }

        configured.push(resp.data.service)
        addLog('success', `Configured ${type} on ${iface}`)
      }

      mappingDeployedServices.value = configured

      provisioningProgress.value = 85
      provisioningStatus.value = 'Deploying services...'
      mappingStatus.value = `Deploying ${configured.length} service(s)...`
      addLog('info', `Deploying ${configured.length} configured service instance(s)...`)

      // Dispatch all deploy requests, then wait for WS events to confirm completion
      let actuallyDispatched = 0
      for (const service of configured) {
        const serviceLabel = `${service.service_type || 'service'} on ${service.interface_name || service.interface || 'unknown interface'}`
        try {
          const deployResp = await axios.post(`/routers/${routerId}/services/${service.id}/deploy`)
          if (!deployResp.data?.success) {
            const deployMsg = deployResp.data?.message || ''
            const alreadyDeployed = /already.deployed|already.exists|already.configured/i.test(deployMsg)
            if (!alreadyDeployed) {
              throw new Error(deployMsg || `Failed to deploy service ${service.id}`)
            }
            addLog('info', `${serviceLabel} is already deployed — skipping`)
          } else {
            actuallyDispatched++
            addLog('info', `Queued ${serviceLabel}`)
          }
        } catch (deployErr) {
          const httpStatus = deployErr.response?.status
          const deployMsg = deployErr.response?.data?.message || deployErr.message || ''
          const alreadyDeployed = httpStatus === 409 || /already.deployed|already.exists|already.configured/i.test(deployMsg)
          if (!alreadyDeployed) throw deployErr
          addLog('info', `${serviceLabel} is already deployed — skipping`)
        }
      }

      // If every service was already deployed, nothing to wait for — resolve immediately
      if (actuallyDispatched === 0) {
        addLog('info', 'All services already deployed')
      } else {
        // Wait for WS provisioning.progress events to confirm all dispatched services complete
        await waitForServiceDeploymentViaWs(routerId, actuallyDispatched)
      }

      mappingDeploying.value = false
      provisioningProgress.value = 100
      provisioningStatus.value = 'Deployment completed successfully'
      mappingStatus.value = 'Deployment completed successfully'
      addLog('success', 'All mapped services deployed successfully')
    } catch (e) {
      mappingDeploying.value = false
      const msg = e.response?.data?.message || e.message || 'Service deployment failed'
      mappingStatus.value = msg
      mappingErrors.value = [msg]
      provisioningStatus.value = msg
      addLog('error', msg)
    }
  }

  const generateServiceConfig = async (options = {}) => {
    formSubmitting.value = true
    try {
      const dryRun = Boolean(options.dryRun)

      const payload = {
        enable_hotspot: enableHotspot.value,
        enable_pppoe: enablePPPoE.value,
        dry_run: dryRun,
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

      if (response.data?.success) {
        serviceScript.value = response.data?.service_script || response.data?.script

        const dryRunSummary = response.data?.dry_run_summary || {}
        lastDryRunSummary.value = dryRunSummary
        const preflightWarnings = Array.isArray(response.data?.preflight?.warnings)
          ? response.data.preflight.warnings
          : Array.isArray(dryRunSummary.warnings)
            ? dryRunSummary.warnings
            : []

        if (dryRun) {
          provisioningStatus.value = 'Dry-run completed successfully'
          addLog('success', 'Service configuration dry-run completed successfully')
          addLog('info', `Dry-run script length: ${serviceScript.value?.length || 0} characters`)
          if (preflightWarnings.length > 0) {
            addLog('warning', `Dry-run warnings: ${preflightWarnings.join(' | ')}`)
          }
          if (dryRunSummary.missing_interfaces?.length > 0) {
            addLog('warning', `Missing interfaces: ${dryRunSummary.missing_interfaces.join(', ')}`)
          }
        } else {
          currentStage.value = 4
          provisioningProgress.value = 90
          provisioningStatus.value = 'Configuration generated - Ready to deploy'
          addLog('success', 'Service configuration generated successfully')
          addLog('info', `Script length: ${serviceScript.value?.length || 0} characters`)
          if (preflightWarnings.length > 0) {
            addLog('warning', `Preflight warnings: ${preflightWarnings.join(' | ')}`)
          }
        }
      }

      return response.data
    } catch (error) {
      console.error('Error generating config:', error)
      const errorMsg = error.response?.data?.error || 'Error generating configuration'
      provisioningStatus.value = errorMsg
      addLog('error', errorMsg)
    } finally {
      formSubmitting.value = false
    }
  }

  const previewServiceConfig = async () => generateServiceConfig({ dryRun: true })

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

      if (response.data?.success) {
        addLog('success', 'Deployment job dispatched')
        provisioningStatus.value = 'Deployment in progress...'
        currentStage.value = 5
        provisioningProgress.value = 95

        // Listen for RouterProvisioningProgress WS events on the per-router channel
        const routerId = provisioningRouter.value.id
        const channelName = `router-provisioning.${routerId}`
        _provisioningChannel = window.Echo?.private(channelName)

        if (!_provisioningChannel) {
          // Echo unavailable — deployment is running server-side; just mark done
          addLog('warning', 'Real-time updates unavailable — check routers page for status')
          provisioningProgress.value = 100
          waitingForJobCompletion.value = false
          formSubmitting.value = false
          return
        }

        // Hard timeout: 2 minutes
        const timeout = setTimeout(() => {
          window.Echo?.leave(channelName)
          _provisioningChannel = null
          deploymentTimedOut.value = true
          waitingForJobCompletion.value = false
          formSubmitting.value = false
          provisioningStatus.value = 'Deployment timeout - check router manually'
          addLog('warning', 'Deployment status check timed out')
        }, 120_000)

        let _deployResolved = false
        const _resolveDeployment = (success, message) => {
          if (_deployResolved) return
          _deployResolved = true
          clearTimeout(timeout)
          window.Echo?.leave(channelName)
          _provisioningChannel = null
          waitingForJobCompletion.value = false
          formSubmitting.value = false
          if (success) {
            provisioningProgress.value = 100
            provisioningStatus.value = 'Deployment completed successfully'
            addLog('success', message || 'Router provisioned successfully!')
            if (provisioningRouter.value) provisioningRouter.value.status = 'online'
            emit('refresh-routers')
          } else {
            deploymentFailed.value = true
            provisioningStatus.value = 'Deployment failed'
            addLog('error', message || 'Deployment failed')
          }
        }

        _provisioningChannel.listen('.provisioning.progress', (data) => {
          provisioningProgress.value = Math.min(99, data.progress ?? 95)
          provisioningStatus.value = data.message || provisioningStatus.value
          if (data.message) addLog('info', data.message)

          const stage = data.stage || ''
          if (stage.endsWith('_completed') || stage === 'completed') {
            _resolveDeployment(true, data.message)
          } else if (stage.endsWith('_failed') || stage === 'failed') {
            _resolveDeployment(false, data.message)
          }
        })

        _provisioningChannel.listen('.provisioning.failed', (data) => {
          _resolveDeployment(false, data.message || 'Provisioning failed')
        })

        // Catch-up: check once after 5s in case WS event was missed (race / fast job)
        setTimeout(async () => {
          if (_deployResolved) return
          try {
            const res = await axios.get(`/routers/${routerId}/provisioning-status`)
            if (res.data.status === 'completed' || res.data.router_status === 'online') {
              _resolveDeployment(true, 'Deployment completed (catch-up)')
            } else if (res.data.status === 'failed' || res.data.router_status === 'connection_failed') {
              _resolveDeployment(false, res.data.error || 'Deployment failed (catch-up)')
            }
          } catch (_) { /* non-fatal */ }
        }, 5_000)
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
    deployConfiguration()
  }

  /**
   * One-shot HTTP catch-up after WS subscription — handles the race condition
   * where VpnConnectivityVerified fired before the frontend subscribed.
   * If router is already online, advance the UI immediately.
   * If still pending, start a sparse fallback poll (max 6 × 10s = 60s).
   */
  const _startVpnCatchup = (vpnChannelName, routersChannelName) => {
    let _catchupResolved = false

    const _tryResolveViaHttp = async () => {
      if (_catchupResolved || currentStage.value !== 2 || !provisioningRouter.value?.id) return
      try {
        const res = await axios.get(`/routers/${provisioningRouter.value.id}/provisioning-status`)
        const isFailed = res.data.status === 'failed' || res.data.router_status === 'failed' || res.data.router_status === 'connection_failed'
        if (isFailed) {
          _catchupResolved = true
          _stopVpnFallback()
          deploymentFailed.value = true
          addLog('error', '❌ Router provisioning failed (catch-up detected)')
          return
        }
        const isOnline = res.data.status === 'completed' || res.data.router_status === 'online' || res.data.router_status === 'active'
        if (!isOnline) return

        _catchupResolved = true
        _stopVpnFallback()
        addLog('info', '✅ Router already online (catch-up detected) — fetching interfaces...')

        // Live SSH fetch — only safe here because router_status confirmed online
        const ifRes = await axios.get(`/routers/${provisioningRouter.value.id}/interfaces`)
        const ifaces = ifRes.data.interfaces || []
        if (!ifaces.length) {
          // SSH succeeded but returned nothing — don't silently advance; let WS or user retry
          addLog('warning', 'Interface fetch returned no interfaces — waiting for WS event')
          _catchupResolved = false
          return
        }
        _handleInterfacesDiscovered(ifaces, {}, vpnChannelName, routersChannelName)
      } catch (_) { /* non-fatal — WS will deliver the event */ }
    }

    // First check after 3s
    _vpnCatchupTimer = setTimeout(async () => {
      await _tryResolveViaHttp()
      if (_catchupResolved) return

      // Sparse fallback poll — WS is primary, this is last resort (max 6 × 10s = 60s)
      let attempts = 0
      _vpnFallbackInterval = setInterval(async () => {
        attempts++
        if (currentStage.value !== 2 || _catchupResolved) { _stopVpnFallback(); return }
        await _tryResolveViaHttp()
        if (attempts >= 6) _stopVpnFallback()
      }, 10_000)
    }, 3_000)
  }

  const _stopVpnFallback = () => {
    if (_vpnCatchupTimer) { clearTimeout(_vpnCatchupTimer); _vpnCatchupTimer = null }
    if (_vpnFallbackInterval) { clearInterval(_vpnFallbackInterval); _vpnFallbackInterval = null }
  }

  /**
   * Shared handler — called by WS events OR catch-up HTTP for interface-discovered.
   */
  const _handleInterfacesDiscovered = (interfaces, routerInfo, vpnChanName, routersChanName) => {
    _stopVpnFallback()
    const allInterfaces = interfaces
    availableInterfaces.value = allInterfaces.filter(iface => {
      const name = (iface.name || '').toLowerCase()
      return name !== 'lo' && !name.startsWith('wg-') && !name.startsWith('wg')
    })
    if (routerInfo?.model) {
      addLog('success', `✅ Discovered ${allInterfaces.length} interfaces`)
      addLog('info', `Router: ${routerInfo.model} (${routerInfo.version})`)
      if (provisioningRouter.value) {
        provisioningRouter.value.status = 'online'
        provisioningRouter.value.model = routerInfo.model
        provisioningRouter.value.os_version = routerInfo.version
      }
    } else {
      addLog('success', `✅ Discovered ${allInterfaces.length} interfaces`)
      if (provisioningRouter.value) provisioningRouter.value.status = 'online'
    }
    serviceMappings.value = Object.fromEntries(
      (availableInterfaces.value || []).map((iface) => [iface.name, 'none']),
    )
    currentStage.value = 3
    provisioningProgress.value = 75
    provisioningStatus.value = 'Router connected - Map services to interfaces'
    if (vpnChanName) { window.Echo?.leave(vpnChanName); _vpnChannelName = null }
    if (routersChanName) { window.Echo?.leave(routersChanName); _routersChannelName = null }
    addLog('success', '🎉 Router provisioning complete!')
    addLog('info', 'Map services to interfaces, then confirm to deploy')
  }

  // Subscribe to VPN connectivity events via WebSocket
  const subscribeToVpnEvents = () => {
    const user = JSON.parse(localStorage.getItem('user'))
    if (!user || !user.tenant_id) {
      addLog('error', 'Cannot subscribe to VPN events: No tenant ID')
      return
    }

    // Use Echo's .private() method - it automatically adds 'private-' prefix
    const vpnChannelName = `tenant.${user.tenant_id}.vpn`
    const routersChannelName = `tenant.${user.tenant_id}.routers`

    // Leave any existing subscriptions before re-subscribing to prevent listener accumulation
    // (can happen if user navigates back to stage 1 and clicks Continue again)
    if (_vpnChannelName) { window.Echo?.leave(_vpnChannelName); _vpnChannelName = null }
    if (_routersChannelName) { window.Echo?.leave(_routersChannelName); _routersChannelName = null }

    _vpnChannelName = vpnChannelName
    _routersChannelName = routersChannelName
    addLog('info', `Subscribing to provisioning events on private channels: ${vpnChannelName}, ${routersChannelName}`)

    // Start catch-up regardless of Echo status — if Echo is unavailable the
    // catch-up HTTP path is the only way to advance the UI.
    _startVpnCatchup(vpnChannelName, routersChannelName)

    if (!window.Echo) {
      addLog('warning', 'WebSocket unavailable — using HTTP catch-up only')
      return
    }

    try {
      // Subscribe to PRIVATE VPN channel for connectivity events (requires auth)
      const vpnChannel = window.Echo.private(vpnChannelName)
      // Subscribe to PRIVATE routers channel for interface discovery events (requires auth)
      const routersChannel = window.Echo.private(routersChannelName)

      // Listen for connectivity checking events (progress updates)
      vpnChannel.listen('.vpn.connectivity.checking', (data) => {
        console.log('VPN connectivity checking:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          // WS is alive — stop catch-up timer (not needed)
          _stopVpnFallback()

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
      vpnChannel.listen('.vpn.connectivity.verified', (data) => {
        console.log('VPN connectivity verified:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          _stopVpnFallback()

          vpnConnectivityStatus.value = 'verified'
          vpnConnected.value = true
          vpnLatencyMs.value = data.connectivity?.latency_ms || 0
          
          addLog('success', `✅ VPN connectivity verified! Latency: ${(data.connectivity?.latency_ms || 0).toFixed(1)}ms`)
          addLog('success', `Router is reachable via VPN at ${data.client_ip}`)
          
          provisioningProgress.value = 60
          provisioningStatus.value = 'VPN connected - Discovering interfaces...'
          connectionStatus.value = 'Connected'
          
          // Don't unsubscribe yet - wait for interfaces.discovered event
          addLog('info', 'Waiting for interface discovery...')
        }
      })

      // Listen for router interfaces discovered event on ROUTERS channel (AUTO-DISCOVERY!)
      routersChannel.listen('.router.interfaces.discovered', (data) => {
        console.log('Router interfaces discovered:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          _handleInterfacesDiscovered(
            data.interfaces || [],
            data.router_info || {},
            vpnChannelName,
            routersChannelName,
          )
        }
      })

      // Listen for connectivity failed event (TIMEOUT/ERROR)
      vpnChannel.listen('.vpn.connectivity.failed', (data) => {
        console.log('VPN connectivity failed:', data)
        
        if (data.router_id === provisioningRouter.value?.id) {
          _stopVpnFallback()

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
          
          // Unsubscribe from both channels
          window.Echo.leave(vpnChannelName); _vpnChannelName = null
          window.Echo.leave(routersChannelName); _routersChannelName = null
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
    lastDryRunSummary.value = null
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

    // Capture router id BEFORE nulling provisioningRouter below
    const _resetRouterId = provisioningRouter.value?.id

    // Leave any open WS channels and cancel timers
    _stopVpnFallback()
    if (_provisioningChannel) {
      if (_resetRouterId) window.Echo?.leave(`router-provisioning.${_resetRouterId}`)
      _provisioningChannel = null
    }
    if (_serviceDeployChannel) {
      if (_resetRouterId) window.Echo?.leave(`router-provisioning.${_resetRouterId}`)
      _serviceDeployChannel = null
    }
    if (_vpnChannelName) {
      window.Echo?.leave(_vpnChannelName)
      _vpnChannelName = null
    }
    if (_routersChannelName) {
      window.Echo?.leave(_routersChannelName)
      _routersChannelName = null
    }

    provisioningRouter.value = null
  }

  onUnmounted(() => {
    const _unmountRouterId = provisioningRouter.value?.id
    _stopVpnFallback()
    if (_provisioningChannel) {
      if (_unmountRouterId) window.Echo?.leave(`router-provisioning.${_unmountRouterId}`)
      _provisioningChannel = null
    }
    if (_serviceDeployChannel) {
      if (_unmountRouterId) window.Echo?.leave(`router-provisioning.${_unmountRouterId}`)
      _serviceDeployChannel = null
    }
    if (_vpnChannelName) {
      window.Echo?.leave(_vpnChannelName)
      _vpnChannelName = null
    }
    if (_routersChannelName) {
      window.Echo?.leave(_routersChannelName)
      _routersChannelName = null
    }
  })

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
    lastDryRunSummary,
    availableInterfaces,
    selectedHotspotInterfaces,
    selectedPPPoEInterfaces,
    serviceMappings,
    mappingDeploying,
    mappingStatus,
    mappingErrors,
    mappingDeployedServices,
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
    previewServiceConfig,
    deployConfiguration,
    addLog,
    clearLogs,
    formatLogTime,
    getLogLevelClass,
    copyToClipboard,
    toggleInterfaceSelection,
    setServiceMapping,
    confirmServiceMappingAndDeploy,
    retryDeployment,
    resetForm,
  }
}
