/**
 * Composable for accessing Pusher/Echo instance
 * Provides direct access to Pusher for custom channel subscriptions
 */
import { ref, computed } from 'vue'
import Echo from '@/plugins/echo'

export function usePusher() {
  // Check if Echo is properly initialized
  const isConnected = computed(() => {
    try {
      return Echo?.connector?.pusher?.connection?.state === 'connected'
    } catch (error) {
      console.error('Error checking Pusher connection:', error)
      return false
    }
  })

  // Get the Pusher instance
  const pusher = computed(() => {
    try {
      return Echo?.connector?.pusher
    } catch (error) {
      console.error('Error accessing Pusher instance:', error)
      return null
    }
  })

  // Get connection state
  const connectionState = computed(() => {
    try {
      return Echo?.connector?.pusher?.connection?.state || 'disconnected'
    } catch (error) {
      return 'error'
    }
  })

  return {
    pusher: pusher.value,
    isConnected,
    connectionState,
    Echo
  }
}
