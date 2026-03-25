/**
 * Composable for listening to WebSocket events
 * Provides easy event subscription and cleanup
 */
import { onMounted, onUnmounted } from 'vue'
import Echo from '@/plugins/echo'

export function useWebSocketEvents() {
  const listeners = []

  /**
   * Listen to a public channel event
   */
  const listenToChannel = (channelName, eventName, callback) => {
    console.log(`🎧 Subscribing to ${eventName} on ${channelName}`)

    const channel = Echo.channel(channelName)
      .listen(`.${eventName}`, (data) => {
        console.log(`📨 Event received: ${eventName}`, data)
        callback(data)
      })

    listeners.push({ channel: channelName, type: 'public' })
    return channel
  }

  /**
   * Listen to a private channel event
   */
  const listenToPrivateChannel = (channelName, eventName, callback) => {
    console.log(`🔐 Subscribing to ${eventName} on private-${channelName}`)

    const channel = Echo.private(channelName)
      .listen(`.${eventName}`, (data) => {
        console.log(`📨 Private event received: ${eventName}`, data)
        callback(data)
      })

    listeners.push({ channel: channelName, type: 'private' })
    return channel
  }

  /**
   * Listen to a presence channel event
   */
  const listenToPresenceChannel = (channelName, eventName, callback) => {
    console.log(`👥 Subscribing to ${eventName} on presence-${channelName}`)

    const channel = Echo.join(channelName)
      .listen(`.${eventName}`, (data) => {
        console.log(`📨 Presence event received: ${eventName}`, data)
        callback(data)
      })

    listeners.push({ channel: channelName, type: 'presence' })
    return channel
  }

  /**
   * Listen to multiple events on a channel
   */
  const listenToMultipleEvents = (channelName, events, isPrivate = false) => {
    const channel = isPrivate ? Echo.private(channelName) : Echo.channel(channelName)

    events.forEach(({ event, callback }) => {
      channel.listen(`.${event}`, (data) => {
        console.log(`📨 Event received: ${event}`, data)
        callback(data)
      })
    })

    listeners.push({ channel: channelName, type: isPrivate ? 'private' : 'public' })
    return channel
  }

  /**
   * Listen to tenant-specific events
   */
  const listenToTenantEvents = (tenantId, events) => {
    if (!tenantId) {
      console.warn('⚠️ No tenant ID provided for tenant events')
      return
    }

    const channels = []

    events.forEach(({ channel, event, callback }) => {
      const channelName = `tenant.${tenantId}.${channel}`
      
      const ch = Echo.private(channelName)
        .listen(`.${event}`, (data) => {
          console.log(`📨 Tenant event received: ${event}`, data)
          callback(data)
        })

      channels.push(ch)
      listeners.push({ channel: channelName, type: 'private' })
    })

    return channels
  }

  /**
   * Listen to user-specific events
   */
  const listenToUserEvents = (userId, events) => {
    if (!userId) {
      console.warn('⚠️ No user ID provided for user events')
      return
    }

    const channels = []

    events.forEach(({ event, callback }) => {
      const channelName = `user.${userId}`
      
      const ch = Echo.private(channelName)
        .listen(`.${event}`, (data) => {
          console.log(`📨 User event received: ${event}`, data)
          callback(data)
        })

      channels.push(ch)
      listeners.push({ channel: channelName, type: 'private' })
    })

    return channels
  }

  /**
   * Cleanup all listeners
   */
  const cleanup = () => {
    console.log(`🧹 Cleaning up ${listeners.length} WebSocket listeners`)

    listeners.forEach(({ channel }) => {
      Echo.leave(channel)
    })

    listeners.length = 0
  }

  // Auto cleanup on unmount
  onUnmounted(() => {
    cleanup()
  })

  return {
    listenToChannel,
    listenToPrivateChannel,
    listenToPresenceChannel,
    listenToMultipleEvents,
    listenToTenantEvents,
    listenToUserEvents,
    cleanup,
  }
}
