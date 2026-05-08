import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useAuthStore } from '@/stores/auth'

/**
 * SSE-based real-time router status updates.
 * No browser polling - pure event-driven with Server-Sent Events as WebSocket fallback.
 * Strictly tenant-isolated - verifies all data belongs to current tenant.
 */
export function useRouterStatusStream() {
  const authStore = useAuthStore()
  const isConnected = ref(false)
  const connectionType = ref(null) // 'websocket' | 'sse' | null
  const lastUpdate = ref(null)
  const error = ref(null)
  
  let eventSource = null
  let reconnectTimer = null
  let heartbeatTimer = null
  let reconnectAttempts = 0
  const MAX_RECONNECT_ATTEMPTS = 10
  const RECONNECT_BASE_DELAY = 2000

  /**
   * Verify data belongs to current tenant (security check)
   */
  const verifyTenantData = (data) => {
    if (!data) return false
    
    // If tenant_id is present in data, verify it matches current tenant
    if (data.tenant_id && data.tenant_id !== authStore.tenantId) {
      console.error('Tenant mismatch detected!', {
        expected: authStore.tenantId,
        received: data.tenant_id,
      })
      return false
    }
    
    return true
  }

  /**
   * Try WebSocket first, fall back to SSE if it fails
   */
  const connect = () => {
    if (!authStore.tenantId) return
    
    // Clear any existing connection
    disconnect()
    
    // Try WebSocket first
    if (window.Echo && tryWebSocket()) {
      return
    }
    
    // Fall back to SSE
    connectSSE()
  }

  /**
   * Attempt WebSocket connection
   */
  const tryWebSocket = () => {
    try {
      const channel = window.Echo.private(`tenant.${authStore.tenantId}.router-updates`)
        .listen('.RouterStatusUpdated', (event) => {
          // Verify tenant isolation
          if (!verifyTenantData(event)) {
            console.warn('Received data for wrong tenant via WebSocket, ignoring')
            return
          }
          handleStatusUpdate(event)
        })
        .error((err) => {
          console.warn('WebSocket error:', err)
          // WebSocket failed, will auto-fallback to SSE via error handler
        })
      
      if (channel) {
        isConnected.value = true
        connectionType.value = 'websocket'
        error.value = null
        reconnectAttempts = 0
        return true
      }
    } catch (err) {
      console.warn('WebSocket connection failed:', err)
    }
    return false
  }

  /**
   * Connect via Server-Sent Events
   */
  const connectSSE = () => {
    if (eventSource) {
      eventSource.close()
    }

    const baseUrl = import.meta.env.VITE_API_URL || import.meta.env.VITE_API_BASE_URL || '/api'
    const url = `${baseUrl}/routers/stream/status`
    
    try {
      eventSource = new EventSource(url, {
        withCredentials: true // Send auth cookies
      })

      eventSource.onopen = () => {
        console.log('SSE connection established')
        isConnected.value = true
        connectionType.value = 'sse'
        error.value = null
        reconnectAttempts = 0
      }

      eventSource.addEventListener('initial', (e) => {
        try {
          const data = JSON.parse(e.data)
          
          // Verify tenant isolation
          if (!verifyTenantData(data)) {
            console.error('Received initial state for wrong tenant, closing connection')
            eventSource.close()
            return
          }
          
          handleInitialState(data)
        } catch (err) {
          console.error('Failed to parse initial state:', err)
        }
      })

      eventSource.addEventListener('router.status.updated', (e) => {
        try {
          const data = JSON.parse(e.data)
          
          // Verify tenant isolation
          if (!verifyTenantData(data)) {
            console.warn('Received status update for wrong tenant, ignoring')
            return
          }
          
          handleStatusUpdate(data)
        } catch (err) {
          console.error('Failed to parse status update:', err)
        }
      })

      eventSource.addEventListener('heartbeat', (e) => {
        // Connection is alive, reset any timers
        lastUpdate.value = new Date()
      })

      eventSource.addEventListener('error', (e) => {
        console.error('SSE error event:', e)
        isConnected.value = false
        connectionType.value = null
        
        // Check if it was an auth error
        if (eventSource.readyState === EventSource.CLOSED) {
          error.value = 'Connection closed - may need re-authentication'
        } else {
          error.value = 'Connection lost'
        }
        
        eventSource.close()
        eventSource = null
        
        scheduleReconnect()
      })

    } catch (err) {
      console.error('Failed to create EventSource:', err)
      scheduleReconnect()
    }
  }

  /**
   * Handle initial full state from SSE
   */
  const handleInitialState = (data) => {
    if (data.routers && Array.isArray(data.routers)) {
      // Emit event for components to consume
      window.dispatchEvent(new CustomEvent('router:status:initial', {
        detail: { 
          routers: data.routers,
          tenantId: authStore.tenantId // Include for verification
        }
      }))
    }
  }

  /**
   * Handle individual status update
   */
  const handleStatusUpdate = (update) => {
    lastUpdate.value = new Date()
    
    // Emit event for components to consume
    window.dispatchEvent(new CustomEvent('router:status:update', {
      detail: {
        ...update,
        tenantId: authStore.tenantId // Include for verification
      }
    }))
  }

  /**
   * Schedule reconnection with exponential backoff
   */
  const scheduleReconnect = () => {
    if (reconnectTimer) return
    
    const delay = Math.min(
      RECONNECT_BASE_DELAY * Math.pow(2, reconnectAttempts),
      30000 // Max 30s
    )
    
    reconnectAttempts++
    
    if (reconnectAttempts <= MAX_RECONNECT_ATTEMPTS) {
      console.log(`Reconnecting in ${delay}ms (attempt ${reconnectAttempts})`)
      reconnectTimer = setTimeout(() => {
        reconnectTimer = null
        connect()
      }, delay)
    } else {
      console.error('Max reconnection attempts reached')
      error.value = 'Unable to establish connection'
    }
  }

  /**
   * Disconnect all connections
   */
  const disconnect = () => {
    // Clear timers
    if (reconnectTimer) {
      clearTimeout(reconnectTimer)
      reconnectTimer = null
    }
    
    if (heartbeatTimer) {
      clearInterval(heartbeatTimer)
      heartbeatTimer = null
    }

    // Close SSE
    if (eventSource) {
      eventSource.close()
      eventSource = null
    }

    // Leave WebSocket channel
    if (window.Echo && connectionType.value === 'websocket') {
      window.Echo.leave(`tenant.${authStore.tenantId}.router-updates`)
    }

    isConnected.value = false
    connectionType.value = null
  }

  // Auto-connect when authenticated
  watch(() => authStore.tenantId, (tenantId) => {
    if (tenantId) {
      connect()
    } else {
      disconnect()
    }
  }, { immediate: true })

  // Handle page visibility - reconnect when tab becomes active
  const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible' && !isConnected.value) {
      console.log('Page visible, reconnecting...')
      reconnectAttempts = 0 // Reset counter on manual reconnect
      connect()
    }
  }

  onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibilityChange)
  })

  onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    disconnect()
  })

  return {
    isConnected,
    connectionType,
    lastUpdate,
    error,
    connect,
    disconnect
  }
}
