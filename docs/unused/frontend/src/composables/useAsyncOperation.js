/**
 * Composable for handling async operations with 202 Accepted responses
 * Provides state management, WebSocket listening, and progress tracking
 */
import { ref, onMounted, onUnmounted } from 'vue'
import Echo from '@/plugins/echo'

export function useAsyncOperation(options = {}) {
  const {
    channel = null,
    event = null,
    timeout = 30000, // 30 seconds default timeout
    onSuccess = null,
    onError = null,
    onTimeout = null,
  } = options

  // State
  const isProcessing = ref(false)
  const isComplete = ref(false)
  const hasError = ref(false)
  const errorMessage = ref('')
  const result = ref(null)
  const progress = ref(0)

  // WebSocket listener
  let echoChannel = null
  let timeoutId = null

  /**
   * Start listening for completion event
   */
  const startListening = (channelName, eventName) => {
    if (!channelName || !eventName) return

    console.log(`🎧 Listening for ${eventName} on ${channelName}`)

    // Listen on channel
    echoChannel = Echo.channel(channelName)
      .listen(`.${eventName}`, (data) => {
        console.log(`✅ Event received: ${eventName}`, data)
        
        isProcessing.value = false
        isComplete.value = true
        result.value = data
        progress.value = 100

        // Clear timeout
        if (timeoutId) {
          clearTimeout(timeoutId)
          timeoutId = null
        }

        // Call success callback
        if (onSuccess) {
          onSuccess(data)
        }
      })

    // Set timeout
    if (timeout > 0) {
      timeoutId = setTimeout(() => {
        if (isProcessing.value) {
          console.warn(`⏱️ Operation timed out after ${timeout}ms`)
          
          isProcessing.value = false
          hasError.value = true
          errorMessage.value = 'Operation timed out. Please refresh to check status.'

          if (onTimeout) {
            onTimeout()
          }
        }
      }, timeout)
    }
  }

  /**
   * Stop listening and cleanup
   */
  const stopListening = () => {
    if (echoChannel) {
      Echo.leave(echoChannel.name)
      echoChannel = null
    }

    if (timeoutId) {
      clearTimeout(timeoutId)
      timeoutId = null
    }
  }

  /**
   * Handle async operation
   */
  const execute = async (apiCall, listenChannel = channel, listenEvent = event) => {
    try {
      // Reset state
      isProcessing.value = true
      isComplete.value = false
      hasError.value = false
      errorMessage.value = ''
      result.value = null
      progress.value = 10

      // Start listening if channel and event provided
      if (listenChannel && listenEvent) {
        startListening(listenChannel, listenEvent)
      }

      // Execute API call
      const response = await apiCall()

      progress.value = 30

      // Handle 202 Accepted (async operation)
      if (response.status === 202) {
        console.log('📋 Operation accepted, waiting for completion...')
        progress.value = 50
        // Keep isProcessing true, wait for WebSocket event
        return { accepted: true, data: response.data }
      }

      // Handle immediate success (200, 201)
      if (response.status >= 200 && response.status < 300) {
        console.log('✅ Operation completed immediately')
        isProcessing.value = false
        isComplete.value = true
        result.value = response.data
        progress.value = 100

        if (onSuccess) {
          onSuccess(response.data)
        }

        return { accepted: false, data: response.data }
      }

      // Handle error
      throw new Error(response.data?.message || 'Operation failed')

    } catch (error) {
      console.error('❌ Operation error:', error)
      
      isProcessing.value = false
      hasError.value = true
      errorMessage.value = error.response?.data?.message || error.message || 'Operation failed'
      progress.value = 0

      if (onError) {
        onError(error)
      }

      throw error
    }
  }

  /**
   * Reset state
   */
  const reset = () => {
    isProcessing.value = false
    isComplete.value = false
    hasError.value = false
    errorMessage.value = ''
    result.value = null
    progress.value = 0
    stopListening()
  }

  // Cleanup on unmount
  onUnmounted(() => {
    stopListening()
  })

  return {
    // State
    isProcessing,
    isComplete,
    hasError,
    errorMessage,
    result,
    progress,

    // Methods
    execute,
    reset,
    startListening,
    stopListening,
  }
}
