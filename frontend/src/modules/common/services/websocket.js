import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useNotificationStore } from '@/stores/notifications'

// Make Pusher available globally
window.Pusher = Pusher

/**
 * WebSocket Service
 * Manages real-time connections and event listeners with auto-reconnection
 */
class WebSocketService {
  constructor() {
    this.echo = null
    this.channels = new Map()
    this.notificationStore = null
    
    // Reconnection state
    this.reconnectTimer = null
    this.reconnectAttempts = 0
    this.maxReconnectAttempts = 10
    this.baseReconnectDelay = 2000 // 2 seconds
    this.maxReconnectDelay = 30000 // 30 seconds
    this.intentionalDisconnect = false
    this.subscribedChannels = new Map() // Store channel subscriptions for reconnection
    this.connectionState = 'disconnected' // 'connected', 'connecting', 'disconnected'
  }

  /**
   * Initialize WebSocket connection with auto-reconnection
   */
  initialize(config = {}) {
    if (this.echo && this.connectionState === 'connected') {
      console.warn('WebSocket already initialized and connected')
      return this.echo
    }
    
    // Clear any pending reconnection
    this.clearReconnectTimer()

    this.notificationStore = useNotificationStore()
    this.intentionalDisconnect = false

    // Detect if we're on HTTPS (ngrok, production) or HTTP (localhost)
    const isSecure = window.location.protocol === 'https:'
    const wsHost = import.meta.env.VITE_PUSHER_HOST || window.location.hostname
    const wsPort = isSecure ? (import.meta.env.VITE_PUSHER_WSS_PORT || 443) : (import.meta.env.VITE_PUSHER_PORT || window.location.port || 8070)
    
    const authEndpoint = import.meta.env.VITE_PUSHER_AUTH_ENDPOINT || '/api/broadcasting/auth'
    const defaultConfig = {
      broadcaster: 'pusher',
      key: import.meta.env.VITE_PUSHER_APP_KEY || 'app-key',
      wsHost: wsHost,
      wsPort: wsPort,
      wssPort: wsPort,
      wsPath: import.meta.env.VITE_PUSHER_PATH || '',
      forceTLS: isSecure,
      encrypted: isSecure,
      disableStats: true,
      enabledTransports: ['ws', 'wss'],
      cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1',
      authEndpoint,
      // Dynamic authorizer: reads token from localStorage at auth time so reconnects
      // after a token refresh always use the current token (not the stale one from init).
      authorizer: (channel) => ({
        authorize: (socketId, callback) => {
          const token = localStorage.getItem('authToken')
          fetch(authEndpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'Accept': 'application/json',
              'Authorization': token ? `Bearer ${token}` : '',
              'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ socket_id: socketId, channel_name: channel.name }),
            credentials: 'same-origin',
          })
            .then(res => {
              if (!res.ok) throw new Error(`Auth failed: ${res.status}`)
              return res.json()
            })
            .then(data => callback(false, data))
            .catch(err => callback(true, err))
        },
      }),
    }

    this.echo = new Echo({ ...defaultConfig, ...config })
    
    // Set up connection event handlers for reconnection
    this.setupConnectionHandlers()

    console.log('✅ WebSocket initialized', {
      protocol: isSecure ? 'wss://' : 'ws://',
      host: defaultConfig.wsHost,
      port: defaultConfig.wsPort,
      path: defaultConfig.wsPath,
      attempt: this.reconnectAttempts + 1,
    })

    return this.echo
  }
  
  /**
   * Set up connection event handlers for monitoring and auto-reconnection
   */
  setupConnectionHandlers() {
    if (!this.echo?.connector?.pusher?.connection) return
    
    const connection = this.echo.connector.pusher.connection
    
    connection.bind('connecting', () => {
      console.log('🔌 WebSocket connecting...')
      this.connectionState = 'connecting'
    })
    
    connection.bind('connected', () => {
      console.log('✅ WebSocket connected')
      this.connectionState = 'connected'
      this.reconnectAttempts = 0 // Reset counter on successful connection
      this.clearReconnectTimer()
      
      // Re-subscribe to all channels that were previously subscribed
      this.resubscribeAllChannels()
    })
    
    connection.bind('disconnected', () => {
      console.log('⚠️ WebSocket disconnected')
      this.connectionState = 'disconnected'
      this.channels.clear() // Clear active channels
      
      // Trigger reconnection if not intentionally disconnected
      if (!this.intentionalDisconnect) {
        this.scheduleReconnect()
      }
    })
    
    connection.bind('error', (error) => {
      console.error('💥 WebSocket connection error:', error)
      this.connectionState = 'disconnected'
      
      // Trigger reconnection on error if not intentional
      if (!this.intentionalDisconnect) {
        this.scheduleReconnect()
      }
    })
  }
  
  /**
   * Schedule reconnection with exponential backoff
   */
  scheduleReconnect() {
    if (this.reconnectTimer) return // Already scheduled
    
    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
      console.error('❌ Max reconnection attempts reached. Giving up.')
      this.notificationStore?.error(
        'Connection Lost',
        'Unable to reconnect to real-time updates. Please refresh the page.',
        10000
      )
      return
    }
    
    const delay = Math.min(
      this.baseReconnectDelay * Math.pow(2, this.reconnectAttempts),
      this.maxReconnectDelay
    )
    
    this.reconnectAttempts++
    
    console.log(`🔄 Scheduling reconnection in ${delay}ms (attempt ${this.reconnectAttempts}/${this.maxReconnectAttempts})`)
    
    this.reconnectTimer = setTimeout(() => {
      this.reconnectTimer = null
      console.log(`🔄 Attempting to reconnect... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`)
      this.reconnect()
    }, delay)
  }
  
  /**
   * Clear any pending reconnection timer
   */
  clearReconnectTimer() {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer)
      this.reconnectTimer = null
    }
  }
  
  /**
   * Reconnect WebSocket
   */
  reconnect() {
    // Clean up existing connection
    if (this.echo) {
      try {
        this.echo.disconnect()
      } catch (e) {
        // Ignore disconnect errors
      }
      this.echo = null
    }
    
    // Re-initialize with a fresh token (token may have been refreshed since last connect)
    this.initialize()
    
    // Re-subscribe to module channels for the stored tenant subscription
    const tenantEntry = Array.from(this.subscribedChannels.entries()).find(([, v]) => v.type === 'tenant')
    if (tenantEntry) {
      this.subscribeModuleChannels(tenantEntry[1].params.tenantId)
    }
  }

  /**
   * Store channel subscription configuration for reconnection
   */
  storeChannelSubscription(channelName, subscriptionType, params = {}) {
    this.subscribedChannels.set(channelName, { type: subscriptionType, params })
  }
  
  /**
   * Re-subscribe to all stored channels after reconnection
   */
  resubscribeAllChannels() {
    if (this.subscribedChannels.size === 0) return
    
    console.log(`🔄 Re-subscribing to ${this.subscribedChannels.size} channels after reconnection`)
    
    // Convert entries to array to avoid issues during iteration
    const channels = Array.from(this.subscribedChannels.entries())
    
    channels.forEach(([channelName, { type, params }]) => {
      console.log(`🔄 Re-subscribing to ${channelName}`)
      
      switch (type) {
        case 'tenant':
          this.subscribeTenantChannel(params.tenantId, true)
          break
        case 'user':
          this.subscribeUserChannel(params.userId, true)
          break
        case 'systemAdmin':
          this.subscribeSystemAdminChannel(true)
          break
        case 'module':
          this.subscribeModuleChannels(params.tenantId)
          break
        default:
          console.warn(`Unknown channel type: ${type}`)
      }
    })
  }

  /**
   * Subscribe to tenant channel
   */
  subscribeTenantChannel(tenantId, isReconnect = false) {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = `tenant.${tenantId}`
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    if (!isReconnect) {
      console.log(`📡 Subscribing to tenant channel: ${channelName}`)
    }
    
    // Store subscription for reconnection
    this.storeChannelSubscription(channelName, 'tenant', { tenantId })

    const channel = this.echo.private(channelName)

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

    // Todo events
    channel
      .listen('.todo.created', (event) => {
        console.log('✅ TodoCreated event:', event)
        this.notificationStore.success(
          'New Todo Created',
          `Todo "${event.todo?.title}" has been created.`,
          5000
        )
        window.dispatchEvent(new CustomEvent('todo-created', { detail: event }))
      })
      .listen('.todo.updated', (event) => {
        console.log('📝 TodoUpdated event:', event)
        this.notificationStore.info(
          'Todo Updated',
          `Todo "${event.todo?.title}" has been updated.`,
          4000
        )
        window.dispatchEvent(new CustomEvent('todo-updated', { detail: event }))
      })
      .listen('.todo.deleted', (event) => {
        console.log('🗑️ TodoDeleted event:', event)
        this.notificationStore.info('Todo Deleted', 'A todo has been deleted.', 4000)
        window.dispatchEvent(new CustomEvent('todo-deleted', { detail: event }))
      })
      .listen('.todo.activity.created', (event) => {
        console.log('📋 TodoActivityCreated event:', event)
        window.dispatchEvent(new CustomEvent('todo-activity-created', { detail: event }))
      })

    // HR Module - Department events
    channel
      .listen('.department.created', (event) => {
        console.log('🏢 DepartmentCreated event:', event)
        this.notificationStore.success('Department Created', `Department "${event.department?.name}" has been created.`, 5000)
        window.dispatchEvent(new CustomEvent('department-created', { detail: event }))
      })
      .listen('.department.updated', (event) => {
        console.log('🏢 DepartmentUpdated event:', event)
        window.dispatchEvent(new CustomEvent('department-updated', { detail: event }))
      })
      .listen('.departmentdeleted', (event) => {
        console.log('🏢 DepartmentDeleted event:', event)
        window.dispatchEvent(new CustomEvent('department-deleted', { detail: event }))
      })

    // HR Module - Position events
    channel
      .listen('.position.created', (event) => {
        console.log('💼 PositionCreated event:', event)
        this.notificationStore.success('Position Created', `Position "${event.position?.title}" has been created.`, 5000)
        window.dispatchEvent(new CustomEvent('position-created', { detail: event }))
      })
      .listen('.position.updated', (event) => {
        console.log('💼 PositionUpdated event:', event)
        window.dispatchEvent(new CustomEvent('position-updated', { detail: event }))
      })
      .listen('.position.deleted', (event) => {
        console.log('💼 PositionDeleted event:', event)
        window.dispatchEvent(new CustomEvent('position-deleted', { detail: event }))
      })

    // HR Module - Employee events
    channel
      .listen('.employee.created', (event) => {
        console.log('👤 EmployeeCreated event:', event)
        this.notificationStore.success('Employee Created', `Employee has been added.`, 5000)
        window.dispatchEvent(new CustomEvent('employee-created', { detail: event }))
      })
      .listen('.employee.updated', (event) => {
        console.log('👤 EmployeeUpdated event:', event)
        window.dispatchEvent(new CustomEvent('employee-updated', { detail: event }))
      })
      .listen('.employee.deleted', (event) => {
        console.log('👤 EmployeeDeleted event:', event)
        window.dispatchEvent(new CustomEvent('employee-deleted', { detail: event }))
      })

    // Finance Module - Expense events
    channel
      .listen('.expense.created', (event) => {
        console.log('💰 ExpenseCreated event:', event)
        this.notificationStore.success('Expense Created', `Expense ${event.expense?.expense_number} has been created.`, 5000)
        window.dispatchEvent(new CustomEvent('expense-created', { detail: event }))
      })
      .listen('.expense.updated', (event) => {
        console.log('💰 ExpenseUpdated event:', event)
        window.dispatchEvent(new CustomEvent('expense-updated', { detail: event }))
      })
      .listen('.expense.deleted', (event) => {
        console.log('💰 ExpenseDeleted event:', event)
        window.dispatchEvent(new CustomEvent('expense-deleted', { detail: event }))
      })

    // Finance Module - Revenue events
    channel
      .listen('.revenue.created', (event) => {
        console.log('💵 RevenueCreated event:', event)
        this.notificationStore.success('Revenue Created', `Revenue ${event.revenue?.revenue_number} has been created.`, 5000)
        window.dispatchEvent(new CustomEvent('revenue-created', { detail: event }))
      })
      .listen('.revenue.updated', (event) => {
        console.log('💵 RevenueUpdated event:', event)
        window.dispatchEvent(new CustomEvent('revenue-updated', { detail: event }))
      })
      .listen('.revenue.deleted', (event) => {
        console.log('💵 RevenueDeleted event:', event)
        window.dispatchEvent(new CustomEvent('revenue-deleted', { detail: event }))
      })

    this.channels.set(channelName, channel)
    return channel
  }

  /**
   * Subscribe to all tenant resource sub-channels.
   * Backend events broadcast on tenant.{id}.{resource} private channels,
   * NOT on the flat tenant.{id} channel, so we must subscribe individually.
   */
  subscribeModuleChannels(tenantId) {
    // Store so resubscribeAllChannels can replay this after reconnection
    this.storeChannelSubscription(`__module__${tenantId}`, 'module', { tenantId })
    if (!this.echo || !tenantId) return

    // ── HR: Departments ──────────────────────────────────────────────────────
    const deptChannel = `tenant.${tenantId}.departments`
    if (!this.channels.has(deptChannel)) {
      const ch = this.echo.private(deptChannel)
        .listen('.department.created', (event) => {
          this.notificationStore.success('Department Created', `"${event.department?.name}" created.`, 5000)
          window.dispatchEvent(new CustomEvent('department-created', { detail: event }))
        })
        .listen('.department.updated', (event) => {
          window.dispatchEvent(new CustomEvent('department-updated', { detail: event }))
        })
        .listen('.departmentdeleted', (event) => {
          window.dispatchEvent(new CustomEvent('department-deleted', { detail: event }))
        })
      this.channels.set(deptChannel, ch)
    }

    // ── HR: Employees ─────────────────────────────────────────────────────────
    const empChannel = `tenant.${tenantId}.employees`
    if (!this.channels.has(empChannel)) {
      const ch = this.echo.private(empChannel)
        .listen('.employeecreated', (event) => {
          this.notificationStore.success('Employee Created', 'A new employee has been added.', 5000)
          window.dispatchEvent(new CustomEvent('employee-created', { detail: event }))
        })
        .listen('.employeeupdated', (event) => {
          window.dispatchEvent(new CustomEvent('employee-updated', { detail: event }))
        })
        .listen('.employeedeleted', (event) => {
          window.dispatchEvent(new CustomEvent('employee-deleted', { detail: event }))
        })
      this.channels.set(empChannel, ch)
    }

    // ── HR: Positions ─────────────────────────────────────────────────────────
    const posChannel = `tenant.${tenantId}.positions`
    if (!this.channels.has(posChannel)) {
      const ch = this.echo.private(posChannel)
        .listen('.positioncreated', (event) => {
          this.notificationStore.success('Position Created', `"${event.position?.title}" created.`, 5000)
          window.dispatchEvent(new CustomEvent('position-created', { detail: event }))
        })
        .listen('.positionupdated', (event) => {
          window.dispatchEvent(new CustomEvent('position-updated', { detail: event }))
        })
        .listen('.positiondeleted', (event) => {
          window.dispatchEvent(new CustomEvent('position-deleted', { detail: event }))
        })
      this.channels.set(posChannel, ch)
    }

    // ── Finance: Expenses ─────────────────────────────────────────────────────
    const expChannel = `tenant.${tenantId}.expenses`
    if (!this.channels.has(expChannel)) {
      const ch = this.echo.private(expChannel)
        .listen('.expensecreated', (event) => {
          this.notificationStore.success('Expense Created', `Expense created.`, 5000)
          window.dispatchEvent(new CustomEvent('expense-created', { detail: event }))
        })
        .listen('.expenseupdated', (event) => {
          window.dispatchEvent(new CustomEvent('expense-updated', { detail: event }))
        })
        .listen('.expensedeleted', (event) => {
          window.dispatchEvent(new CustomEvent('expense-deleted', { detail: event }))
        })
      this.channels.set(expChannel, ch)
    }

    // ── Finance: Revenues ─────────────────────────────────────────────────────
    const revChannel = `tenant.${tenantId}.revenues`
    if (!this.channels.has(revChannel)) {
      const ch = this.echo.private(revChannel)
        .listen('.revenuecreated', (event) => {
          this.notificationStore.success('Revenue Created', `Revenue created.`, 5000)
          window.dispatchEvent(new CustomEvent('revenue-created', { detail: event }))
        })
        .listen('.revenueupdated', (event) => {
          window.dispatchEvent(new CustomEvent('revenue-updated', { detail: event }))
        })
        .listen('.revenuedeleted', (event) => {
          window.dispatchEvent(new CustomEvent('revenue-deleted', { detail: event }))
        })
      this.channels.set(revChannel, ch)
    }

    // ── Todos ─────────────────────────────────────────────────────────────────
    const todoChannel = `tenant.${tenantId}.todos`
    if (!this.channels.has(todoChannel)) {
      const ch = this.echo.private(todoChannel)
        .listen('.todo.created', (event) => {
          this.notificationStore.success('Todo Created', `"${event.todo?.title}" created.`, 5000)
          window.dispatchEvent(new CustomEvent('todo-created', { detail: event }))
        })
        .listen('.todo.updated', (event) => {
          this.notificationStore.info('Todo Updated', `"${event.todo?.title}" updated.`, 4000)
          window.dispatchEvent(new CustomEvent('todo-updated', { detail: event }))
        })
        .listen('.todo.deleted', (event) => {
          this.notificationStore.info('Todo Deleted', 'A todo has been deleted.', 4000)
          window.dispatchEvent(new CustomEvent('todo-deleted', { detail: event }))
        })
        .listen('.todo.activity.created', (event) => {
          window.dispatchEvent(new CustomEvent('todo-activity-created', { detail: event }))
        })
      this.channels.set(todoChannel, ch)
    }

    // ── Packages ──────────────────────────────────────────────────────────────
    const pkgChannel = `tenant.${tenantId}.packages`
    if (!this.channels.has(pkgChannel)) {
      const ch = this.echo.private(pkgChannel)
        .listen('.PackageCreated', (event) => {
          window.dispatchEvent(new CustomEvent('package-created', { detail: event }))
        })
        .listen('.PackageUpdated', (event) => {
          window.dispatchEvent(new CustomEvent('package-updated', { detail: event }))
        })
        .listen('.PackageDeleted', (event) => {
          window.dispatchEvent(new CustomEvent('package-deleted', { detail: event }))
        })
      this.channels.set(pkgChannel, ch)
    }

    // ── Router status updates (RouterStatusUpdated → tenant.{id}.router-updates)
    // RouterCreated/RouterUpdated broadcast on tenant.{id}.routers (provisioning channel)
    // RouterDeleted event does not exist in the backend
    const routersChannel = `tenant.${tenantId}.router-updates`
    if (!this.channels.has(routersChannel)) {
      const ch = this.echo.private(routersChannel)
        .listen('.RouterStatusUpdated', (event) => {
          window.dispatchEvent(new CustomEvent('router-status-updated', { detail: event }))
        })
      this.channels.set(routersChannel, ch)
    }

    // ── Access Points ─────────────────────────────────────────────────────────
    const apChannel = `tenant.${tenantId}.access-points`
    if (!this.channels.has(apChannel)) {
      const ch = this.echo.private(apChannel)
        .listen('.access-point-created', (event) => {
          window.dispatchEvent(new CustomEvent('access-point-created', { detail: event }))
        })
        .listen('.access-point-updated', (event) => {
          window.dispatchEvent(new CustomEvent('access-point-updated', { detail: event }))
        })
        .listen('.access-point-deleted', (event) => {
          window.dispatchEvent(new CustomEvent('access-point-deleted', { detail: event }))
        })
      this.channels.set(apChannel, ch)
    }

    // ── Users ─────────────────────────────────────────────────────────────────
    const usersChannel = `tenant.${tenantId}.users`
    if (!this.channels.has(usersChannel)) {
      const ch = this.echo.private(usersChannel)
        .listen('.UserCreated', (event) => {
          window.dispatchEvent(new CustomEvent('user-created', { detail: event }))
        })
        .listen('.UserUpdated', (event) => {
          window.dispatchEvent(new CustomEvent('user-updated', { detail: event }))
        })
        .listen('.UserDeleted', (event) => {
          window.dispatchEvent(new CustomEvent('user-deleted', { detail: event }))
        })
      this.channels.set(usersChannel, ch)
    }

    // ── Vouchers ────────────────────────────────────────────────────────────────
    const vouchersChannel = `tenant.${tenantId}.vouchers`
    if (!this.channels.has(vouchersChannel)) {
      const ch = this.echo.private(vouchersChannel)
        .listen('.VoucherCreated', (event) => {
          window.dispatchEvent(new CustomEvent('voucher-created', { detail: event }))
        })
        .listen('.VoucherUpdated', (event) => {
          window.dispatchEvent(new CustomEvent('voucher-updated', { detail: event }))
        })
        .listen('.VoucherDeleted', (event) => {
          window.dispatchEvent(new CustomEvent('voucher-deleted', { detail: event }))
        })
      this.channels.set(vouchersChannel, ch)
    }
  }

  /**
   * Subscribe to user private channel
   */
  subscribeUserChannel(userId, isReconnect = false) {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = `user.${userId}`
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    if (!isReconnect) {
      console.log(`📡 Subscribing to user private channel: ${channelName}`)
    }
    
    // Store subscription for reconnection
    this.storeChannelSubscription(channelName, 'user', { userId })

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

    // Todo events (user-specific)
    channel
      .listen('.todo.created', (event) => {
        console.log('✅ TodoCreated (user) event:', event)
        // Dispatch custom event for composable to handle
        window.dispatchEvent(new CustomEvent('todo-created', { detail: event }))
      })
      .listen('.todo.updated', (event) => {
        console.log('📝 TodoUpdated (user) event:', event)
        // Dispatch custom event for composable to handle
        window.dispatchEvent(new CustomEvent('todo-updated', { detail: event }))
      })
      .listen('.todo.deleted', (event) => {
        console.log('🗑️ TodoDeleted (user) event:', event)
        // Dispatch custom event for composable to handle
        window.dispatchEvent(new CustomEvent('todo-deleted', { detail: event }))
      })

    this.channels.set(channelName, channel)
    return channel
  }

  /**
   * Subscribe to system admin channel
   */
  subscribeSystemAdminChannel(isReconnect = false) {
    if (!this.echo) {
      console.error('WebSocket not initialized')
      return null
    }

    const channelName = 'system.admin'
    
    if (this.channels.has(channelName)) {
      console.log(`Already subscribed to ${channelName}`)
      return this.channels.get(channelName)
    }

    if (!isReconnect) {
      console.log(`📡 Subscribing to system admin channel: ${channelName}`)
    }
    
    // Store subscription for reconnection
    this.storeChannelSubscription(channelName, 'systemAdmin', {})

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
    this.intentionalDisconnect = true
    this.clearReconnectTimer()
    
    if (this.echo) {
      console.log('🔌 Disconnecting WebSocket')
      this.unsubscribeAll()
      this.echo.disconnect()
      this.echo = null
    }
    
    this.connectionState = 'disconnected'
    this.subscribedChannels.clear() // Clear stored subscriptions on intentional disconnect
  }
  
  /**
   * Get current connection state
   */
  getConnectionState() {
    return this.connectionState
  }
  
  /**
   * Check if currently connected
   */
  isConnected() {
    return this.connectionState === 'connected'
  }
  
  /**
   * Get reconnect attempt count
   */
  getReconnectAttempts() {
    return this.reconnectAttempts
  }
  
  /**
   * Reset reconnection state (useful for manual reconnection)
   */
  resetReconnectState() {
    this.reconnectAttempts = 0
    this.clearReconnectTimer()
  }

  /**
   * Get Echo instance
   */
  getEcho() {
    return this.echo
  }
}

// Export singleton instance
export const websocketService = new WebSocketService()

// Export class for testing
export { WebSocketService }

// Helper function for easy initialization
export const initializeWebSocket = (tenantId, userId, isSystemAdmin = false) => {
  websocketService.initialize()

  // Subscribe to tenant notification channel and all resource sub-channels
  if (tenantId) {
    websocketService.subscribeTenantChannel(tenantId)
    websocketService.subscribeModuleChannels(tenantId)
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
