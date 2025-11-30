import { onMounted, onUnmounted, ref } from 'vue'

/**
 * Composable for managing Laravel Echo private channel subscriptions
 * Requires user to be authenticated with Sanctum token
 */
export function useBroadcasting() {
  const isConnected = ref(false)
  const channels = ref(new Map())

  /**
   * Subscribe to a private channel
   * @param {string} channelName - Name of the channel (without 'private-' prefix)
   * @param {Object} events - Object mapping event names to handler functions
   * @returns {Object} Channel instance
   */
  const subscribeToPrivateChannel = (channelName, events = {}) => {
    if (!window.Echo) {
      console.error('Echo is not initialized')
      return null
    }

    const channel = window.Echo.private(channelName)
    
    // Register event listeners
    Object.entries(events).forEach(([eventName, handler]) => {
      channel.listen(eventName, handler)
    })

    channels.value.set(channelName, channel)
    
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
    if (window.Echo && channels.value.has(channelName)) {
      window.Echo.leave(channelName)
      channels.value.delete(channelName)
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
    if (window.Echo?.connector?.pusher?.connection) {
      const state = window.Echo.connector.pusher.connection.state
      isConnected.value = state === 'connected'
      return state
    }
    return 'disconnected'
  }

  // Monitor connection status
  onMounted(() => {
    if (window.Echo?.connector?.pusher?.connection) {
      window.Echo.connector.pusher.connection.bind('connected', () => {
        isConnected.value = true
      })

      window.Echo.connector.pusher.connection.bind('disconnected', () => {
        isConnected.value = false
      })

      // Check initial state
      checkConnection()
    }
  })

  // Cleanup on unmount
  onUnmounted(() => {
    unsubscribeAll()
  })

  return {
    isConnected,
    channels,
    subscribeToPrivateChannel,
    subscribeToPresenceChannel,
    unsubscribe,
    unsubscribeAll,
    checkConnection,
  }
}

export default useBroadcasting
