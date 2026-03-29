<template>
  <div v-if="hasError" class="error-boundary">
    <div class="error-container">
      <div class="error-icon">
        <svg class="w-16 h-16 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
      </div>
      
      <h2 class="error-title">Something went wrong</h2>
      
      <p class="error-message">{{ errorMessage }}</p>
      
      <div class="error-actions">
        <button @click="retry" class="btn-retry">
          Try Again
        </button>
        <button @click="goHome" class="btn-home">
          Go to Dashboard
        </button>
      </div>
      
      <details v-if="errorDetails" class="error-details">
        <summary>Error Details</summary>
        <pre>{{ errorDetails }}</pre>
      </details>
    </div>
  </div>
  
  <slot v-else />
</template>

<script setup>
import { ref, onErrorCaptured } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()

const hasError = ref(false)
const errorMessage = ref('')
const errorDetails = ref(null)

onErrorCaptured((err, instance, info) => {
  hasError.value = true
  errorMessage.value = err.message || 'An unexpected error occurred'
  
  // Store error details for debugging
  errorDetails.value = {
    error: err.toString(),
    info: info,
    stack: err.stack
  }
  
  console.error('Error captured by boundary:', err, info)
  
  // Prevent error from propagating
  return false
})

const retry = () => {
  hasError.value = false
  errorMessage.value = ''
  errorDetails.value = null
  
  // Reload current route
  router.go(0)
}

const goHome = () => {
  hasError.value = false
  errorMessage.value = ''
  errorDetails.value = null
  
  router.push('/dashboard')
}
</script>

<style scoped>
.error-boundary {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 20px;
  background-color: #f9fafb;
}

.error-container {
  max-width: 600px;
  width: 100%;
  background: white;
  border-radius: 12px;
  padding: 40px;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  text-align: center;
}

.error-icon {
  display: flex;
  justify-content: center;
  margin-bottom: 20px;
}

.error-title {
  font-size: 24px;
  font-weight: bold;
  color: #1f2937;
  margin-bottom: 12px;
}

.error-message {
  color: #6b7280;
  margin-bottom: 24px;
  line-height: 1.6;
}

.error-actions {
  display: flex;
  gap: 12px;
  justify-content: center;
  margin-bottom: 24px;
}

.btn-retry,
.btn-home {
  padding: 10px 24px;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s;
  border: none;
}

.btn-retry {
  background-color: #16a34a;
  color: white;
}

.btn-retry:hover {
  background-color: #15803d;
}

.btn-home {
  background-color: #e5e7eb;
  color: #374151;
}

.btn-home:hover {
  background-color: #d1d5db;
}

.error-details {
  margin-top: 24px;
  text-align: left;
  background-color: #f3f4f6;
  padding: 16px;
  border-radius: 8px;
  cursor: pointer;
}

.error-details summary {
  font-weight: 500;
  color: #374151;
  margin-bottom: 8px;
}

.error-details pre {
  font-size: 12px;
  color: #6b7280;
  overflow-x: auto;
  white-space: pre-wrap;
  word-wrap: break-word;
}
</style>
