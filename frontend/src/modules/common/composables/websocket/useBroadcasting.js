import { onMounted, onUnmounted, ref } from 'vue'

/**
 * Composable for managing Laravel Echo private channel subscriptions
 * Requires user to be authenticated with Sanctum token
 */
export function useBroadcasting() {
  const isConnected = ref(false)
  const channels = ref(new Map())
  const listeners = ref(new Map())
  
  // Store bound handlers for cleanup
  const boundHandlers = new Map()

  /**
   * Subscribe to a private channel
   * @param {string} channelName - Name of the channel (without 'private-' prefix)
   * @param {Object} events - Object mapping event names to handler functions
   * @returns {Object} Channel instance
   */
  const subscribeToPrivateChannel = (channelName, events = {}) => {
    if (typeof window === 'undefined' || !window.Echo) {
      console.error('Echo is not initialized')
      return null
    }

    let channel = channels.value.get(channelName)
    if (!channel) {
      channel = window.Echo.private(channelName)
      channels.value.set(channelName, channel)
    }

    // Register listeners once per channel/event key
    Object.entries(events).forEach(([eventName, handler]) => {
      const key = `${channelName}:${eventName}`
      if (listeners.value.has(key)) return
      channel.listen(eventName, handler)
      listeners.value.set(key, handler)
    })
    
    return channel
  }

  /**
   * Subscribe to a presence channel
   * @param {string} channelName - Name of the presence channel
   * @param {Object} callbacks - here, joining, leaving event callbacks
   * @returns {Object} Channel instance
   */
  const subscribeToPresenceChannel = (channelName, callbacks = {}) => {
    if (!window.Echo) {
      console.error('Echo is not initialized')
      return null
    }

    const channel = window.Echo.join(channelName)

    if (callbacks.here) {
      channel.here(callbacks.here)
    }
    if (callbacks.joining) {
      channel.joining(callbacks.joining)
    }
    if (callbacks.leaving) {
      channel.leaving(callbacks.leaving)
    }

    // Listen to custom events
    if (callbacks.events) {
      Object.entries(callbacks.events).forEach(([eventName, handler]) => {
        channel.listen(eventName, handler)
      })
    }

    channels.value.set(channelName, channel)
    
    return channel
  }

  /**
   * Unsubscribe from a channel
   * @param {string} channelName - Name of the channel to leave
   */
  const unsubscribe = (channelName) => {
    if (typeof window === 'undefined' || !window.Echo) return
    if (channels.value.has(channelName)) {
      window.Echo.leave(channelName)
      channels.value.delete(channelName)
      Array.from(listeners.value.keys())
        .filter((k) => k.startsWith(`${channelName}:`))
        .forEach((k) => listeners.value.delete(k))
    }
  }

  /**
   * Unsubscribe from all channels
   */
  const unsubscribeAll = () => {
    channels.value.forEach((_, channelName) => {
      unsubscribe(channelName)
    })
  }

  /**
   * Check Echo connection status
   */
  const checkConnection = () => {
    if (typeof window === 'undefined') return 'disconnected'
    if (window.Echo?.connector?.pusher?.connection) {
      const state = window.Echo.connector.pusher.connection.state
      isConnected.value = state === 'connected'
      return state
    }
    return 'disconnected'
  }

  // Connection event handlers
  const handleConnected = () => {
    isConnected.value = true
  }
  
  const handleDisconnected = () => {
    isConnected.value = false
  }

  // Monitor connection status - only in browser environment
  onMounted(() => {
    if (typeof window !== 'undefined' && window.Echo?.connector?.pusher?.connection) {
      // Store bound handlers for cleanup
      boundHandlers.set('connected', handleConnected)
      boundHandlers.set('disconnected', handleDisconnected)
      
      window.Echo.connector.pusher.connection.bind('connected', handleConnected)
      window.Echo.connector.pusher.connection.bind('disconnected', handleDisconnected)

      // Check initial state
      checkConnection()
    }
  })

  // Cleanup on unmount
  onUnmounted(() => {
    // Unbind connection event listeners
    if (typeof window !== 'undefined' && window.Echo?.connector?.pusher?.connection) {
      boundHandlers.forEach((handler, eventName) => {
        window.Echo.connector.pusher.connection.unbind(eventName, handler)
      })
      boundHandlers.clear()
    }
    
    // Unsubscribe all channels
    unsubscribeAll()
  })

  return {
    isConnected,
    channels,
    subscribeToPrivateChannel,
    subscribeToPresenceChannel,
    unsubscribe,
    unsubscribeFromChannel: unsubscribe, // alias used by several composables
    unsubscribeAll,
    checkConnection,
  }
}

export default useBroadcasting
