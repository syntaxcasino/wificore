import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useNotificationStore } from '@/stores/notifications'

// Make Pusher available globally
window.Pusher = Pusher

/**
 * WebSocket Service
 * Manages real-time connections and event listeners
 */
class WebSocketService {
  constructor() {
    this.echo = null
    this.channels = new Map()
    this.notificationStore = null
  }

  /**
   * Initialize WebSocket connection
   */
  initialize(config = {}) {
    if (this.echo) {
      console.warn('WebSocket already initialized')
      return this.echo
    }

    this.notificationStore = useNotificationStore()

    const defaultConfig = {
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY || 'local-key',
      wsHost: import.meta.env.VITE_PUSHER_HOST || 'localhost',
      wsPort: import.meta.env.VITE_PUSHER_PORT || 6001,
      wssPort: import.meta.env.VITE_PUSHER_PORT || 6001,
      forceTLS: false,
      encrypted: true,
      disableStats: true,
      enabledTransports: ['ws', 'wss'],
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
      authEndpoint: '/api/broadcasting/auth',
      auth: {
        headers: {
          Authorization: `Bearer ${localStorage.getItem('authToken')}`,
          Accept: 'application/json',
        },
      },
    }

    this.echo = new Echo({ ...defaultConfig, ...config })

    console.log('✅ WebSocket initialized', {
      host: defaultConfig.wsHost,
      port: defaultConfig.wsPort,
    })

    return this.echo
  }

  /**
   * Subscribe to tenant channel
   */
  subscribeTenantChannel(tenantId) {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = `tenant.${tenantId}`
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    console.log(`📡 Subscribing to tenant channel: ${channelName}`)

    const channel = this.echo.channel(channelName)

    // Tenant-related events
    channel
      .listen('TenantCreated', (event) => {
        console.log('🎉 TenantCreated event:', event)
        this.notificationStore.fromWebSocketEvent('TenantCreated', event)
      })
      .listen('TenantUpdated', (event) => {
        console.log('📝 TenantUpdated event:', event)
        this.notificationStore.info(
          'Tenant Updated',
          'Tenant information has been updated.',
          5000
        )
      })

    // User management events
    channel
      .listen('UserCreated', (event) => {
        console.log('👤 UserCreated event:', event)
        this.notificationStore.fromWebSocketEvent('UserCreated', event)
      })
      .listen('UserUpdated', (event) => {
        console.log('✏️ UserUpdated event:', event)
        this.notificationStore.fromWebSocketEvent('UserUpdated', event)
      })
      .listen('UserDeleted', (event) => {
        console.log('🗑️ UserDeleted event:', event)
        this.notificationStore.fromWebSocketEvent('UserDeleted', event)
      })

    // Payment events
    channel
      .listen('PaymentCompleted', (event) => {
        console.log('💰 PaymentCompleted event:', event)
        this.notificationStore.fromWebSocketEvent('PaymentCompleted', event)
      })
      .listen('PaymentFailed', (event) => {
        console.log('❌ PaymentFailed event:', event)
        this.notificationStore.error(
          'Payment Failed',
          event.message || 'Payment processing failed.',
          7000
        )
      })

    // Hotspot user events
    channel
      .listen('HotspotUserCreated', (event) => {
        console.log('📶 HotspotUserCreated event:', event)
        this.notificationStore.fromWebSocketEvent('HotspotUserCreated', event)
      })
      .listen('HotspotUserExpired', (event) => {
        console.log('⏰ HotspotUserExpired event:', event)
        this.notificationStore.warning(
          'User Session Expired',
          `User ${event.username} session has expired.`,
          6000
        )
      })

    // System events
    channel
      .listen('SystemNotification', (event) => {
        console.log('🔔 SystemNotification event:', event)
        this.notificationStore.info(
          event.title || 'System Notification',
          event.message || 'You have a new notification.',
          event.duration || 5000
        )
      })

    this.channels.set(channelName, channel)
    return channel
  }

  /**
   * Subscribe to user private channel
   */
  subscribeUserChannel(userId) {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = `user.${userId}`
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    console.log(`📡 Subscribing to user private channel: ${channelName}`)

    const channel = this.echo.private(channelName)

    // User-specific events
    channel
      .listen('PasswordChanged', (event) => {
        console.log('🔒 PasswordChanged event:', event)
        this.notificationStore.fromWebSocketEvent('PasswordChanged', event)
      })
      .listen('AccountSuspended', (event) => {
        console.log('⛔ AccountSuspended event:', event)
        this.notificationStore.fromWebSocketEvent('AccountSuspended', event)
      })
      .listen('AccountActivated', (event) => {
        console.log('✅ AccountActivated event:', event)
        this.notificationStore.success(
          'Account Activated',
          'Your account has been activated.',
          5000
        )
      })
      .listen('ProfileUpdated', (event) => {
        console.log('👤 ProfileUpdated event:', event)
        this.notificationStore.info(
          'Profile Updated',
          'Your profile information has been updated.',
          5000
        )
      })

    this.channels.set(channelName, channel)
    return channel
  }

  /**
   * Subscribe to system admin channel
   */
  subscribeSystemAdminChannel() {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = 'system.admin'
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    console.log(`📡 Subscribing to system admin channel: ${channelName}`)

    const channel = this.echo.private(channelName)

    // System admin events
    channel
      .listen('TenantRegistered', (event) => {
        console.log('🏢 TenantRegistered event:', event)
        this.notificationStore.info(
          'New Tenant Registration',
          `${event.tenant?.name} has registered and is pending approval.`,
          8000
        )
      })
      .listen('TenantApproved', (event) => {
        console.log('✅ TenantApproved event:', event)
        this.notificationStore.success(
          'Tenant Approved',
          `${event.tenant?.name} has been approved.`,
          5000
        )
      })
      .listen('SystemAlert', (event) => {
        console.log('🚨 SystemAlert event:', event)
        this.notificationStore.warning(
          event.title || 'System Alert',
          event.message || 'System alert received.',
          event.duration || 8000
        )
      })

    this.channels.set(channelName, channel)
    return channel
  }

  /**
   * Unsubscribe from a channel
   */
  unsubscribe(channelName) {
    if (this.channels.has(channelName)) {
      console.log(`📴 Unsubscribing from ${channelName}`)
      this.echo.leave(channelName)
      this.channels.delete(channelName)
    }
  }

  /**
   * Unsubscribe from all channels
   */
  unsubscribeAll() {
    console.log('📴 Unsubscribing from all channels')
    this.channels.forEach((channel, channelName) => {
      this.echo.leave(channelName)
    })
    this.channels.clear()
  }

  /**
   * Disconnect WebSocket
   */
  disconnect() {
    if (this.echo) {
      console.log('🔌 Disconnecting WebSocket')
      this.unsubscribeAll()
      this.echo.disconnect()
      this.echo = null
    }
  }

  /**
   * Get Echo instance
   */
  getEcho() {
    return this.echo
  }

  /**
   * Check if connected
   */
  isConnected() {
    return this.echo !== null
  }
}

// Export singleton instance
export const websocketService = new WebSocketService()

// Export class for testing
export { WebSocketService }

// Helper function for easy initialization
export const initializeWebSocket = (tenantId, userId, isSystemAdmin = false) => {
  websocketService.initialize()

  // Subscribe to tenant channel
  if (tenantId) {
    websocketService.subscribeTenantChannel(tenantId)
  }

  // Subscribe to user private channel
  if (userId) {
    websocketService.subscribeUserChannel(userId)
  }

  // Subscribe to system admin channel
  if (isSystemAdmin) {
    websocketService.subscribeSystemAdminChannel()
  }

  return websocketService
}
