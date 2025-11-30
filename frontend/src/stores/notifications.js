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
      'TenantCreated': {
        type: 'success',
        title: 'New Tenant Registered',
        message: `${data.tenant?.name} has been registered successfully.`
      },
      'UserCreated': {
        type: 'success',
        title: 'User Created',
        message: `${data.user?.name} has been added to the system.`
      },
      'UserUpdated': {
        type: 'info',
        title: 'User Updated',
        message: `${data.user?.name}'s information has been updated.`
      },
      'UserDeleted': {
        type: 'warning',
        title: 'User Deleted',
        message: `User ${data.username} has been removed.`
      },
      'PasswordChanged': {
        type: 'success',
        title: 'Password Changed',
        message: 'Your password has been updated successfully.'
      },
      'PaymentCompleted': {
        type: 'success',
        title: 'Payment Received',
        message: `Payment of ${data.amount} completed successfully.`
      },
      'HotspotUserCreated': {
        type: 'success',
        title: 'Hotspot User Created',
        message: `User ${data.username} has been provisioned.`
      },
      'AccountSuspended': {
        type: 'error',
        title: 'Account Suspended',
        message: data.reason || 'Your account has been suspended.'
      }
    }

    const notification = eventNotifications[eventName]
    if (notification) {
      add(notification)
    } else {
      // Generic notification for unknown events
      add({
        type: 'info',
        title: eventName,
        message: data.message || 'Event received'
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
