import { defineStore } from 'pinia'
import { ref } from 'vue'

export const useNotificationStore = defineStore('notifications', () => {
  const notifications = ref([])
  const nextId = ref(1)

  /**
   * Add a notification
   */
  const add = (notification) => {
    const id = nextId.value++
    const item = {
      id,
      type: notification.type || 'info', // success, error, warning, info
      title: notification.title || '',
      message: notification.message || '',
      duration: notification.duration || 5000,
      timestamp: new Date(),
      ...notification
    }

    notifications.value.push(item)

    // Auto-remove after duration
    if (item.duration > 0) {
      setTimeout(() => {
        remove(id)
      }, item.duration)
    }

    return id
  }

  /**
   * Remove a notification
   */
  const remove = (id) => {
    const index = notifications.value.findIndex(n => n.id === id)
    if (index > -1) {
      notifications.value.splice(index, 1)
    }
  }

  /**
   * Clear all notifications
   */
  const clear = () => {
    notifications.value = []
  }

  /**
   * Shorthand methods
   */
  const success = (title, message, duration = 5000) => {
    return add({ type: 'success', title, message, duration })
  }

  const error = (title, message, duration = 7000) => {
    return add({ type: 'error', title, message, duration })
  }

  const warning = (title, message, duration = 6000) => {
    return add({ type: 'warning', title, message, duration })
  }

  const info = (title, message, duration = 5000) => {
    return add({ type: 'info', title, message, duration })
  }

  /**
   * WebSocket event notifications
   */
  const fromWebSocketEvent = (eventName, data) => {
    const eventNotifications = {
      // Tenant events
      'TenantCreated': {
        type: 'success',
        title: 'New Tenant Registered',
        message: `${data.tenant?.name} has been registered successfully.`,
        duration: 6000
      },
      'TenantUpdated': {
        type: 'info',
        title: 'Tenant Updated',
        message: `${data.tenant?.name} information has been updated.`,
        duration: 5000
      },
      'TenantApproved': {
        type: 'success',
        title: 'Tenant Approved',
        message: `${data.tenant?.name} has been approved and activated.`,
        duration: 6000
      },
      'TenantSuspended': {
        type: 'warning',
        title: 'Tenant Suspended',
        message: `${data.tenant?.name} has been suspended.`,
        duration: 7000
      },
      
      // User events
      'UserCreated': {
        type: 'success',
        title: 'User Created',
        message: `${data.user?.name} has been added to the system.`,
        duration: 5000
      },
      'UserUpdated': {
        type: 'info',
        title: 'User Updated',
        message: `${data.user?.name}'s information has been updated.`,
        duration: 5000
      },
      'UserDeleted': {
        type: 'warning',
        title: 'User Deleted',
        message: `User ${data.username || data.user?.name} has been removed.`,
        duration: 6000
      },
      
      // Authentication events
      'PasswordChanged': {
        type: 'success',
        title: 'Password Changed',
        message: 'Your password has been updated successfully.',
        duration: 5000
      },
      'AccountSuspended': {
        type: 'error',
        title: 'Account Suspended',
        message: data.reason || 'Your account has been suspended.',
        duration: 8000
      },
      'AccountActivated': {
        type: 'success',
        title: 'Account Activated',
        message: 'Your account has been activated.',
        duration: 5000
      },
      
      // Payment events
      'PaymentCompleted': {
        type: 'success',
        title: 'Payment Received',
        message: `Payment of ${data.amount || 'amount'} completed successfully.`,
        duration: 6000
      },
      'PaymentFailed': {
        type: 'error',
        title: 'Payment Failed',
        message: data.message || 'Payment processing failed.',
        duration: 7000
      },
      
      // Hotspot events
      'HotspotUserCreated': {
        type: 'success',
        title: 'Hotspot User Created',
        message: `User ${data.username} has been provisioned.`,
        duration: 5000
      },
      'HotspotUserExpired': {
        type: 'warning',
        title: 'User Session Expired',
        message: `User ${data.username} session has expired.`,
        duration: 6000
      },
      
      // System events
      'SystemNotification': {
        type: data.type || 'info',
        title: data.title || 'System Notification',
        message: data.message || 'You have a new notification.',
        duration: data.duration || 5000
      },
      'SystemAlert': {
        type: 'warning',
        title: data.title || 'System Alert',
        message: data.message || 'System alert received.',
        duration: data.duration || 8000
      },
      
      // Admin events
      'TenantRegistered': {
        type: 'info',
        title: 'New Tenant Registration',
        message: `${data.tenant?.name} has registered and is pending approval.`,
        duration: 8000
      }
    }

    const notification = eventNotifications[eventName]
    if (notification) {
      add(notification)
    } else {
      // Generic notification for unknown events
      add({
        type: 'info',
        title: eventName.replace(/([A-Z])/g, ' $1').trim(),
        message: data.message || 'Event received',
        duration: 5000
      })
    }
  }

  return {
    notifications,
    add,
    remove,
    clear,
    success,
    error,
    warning,
    info,
    fromWebSocketEvent
  }
})
