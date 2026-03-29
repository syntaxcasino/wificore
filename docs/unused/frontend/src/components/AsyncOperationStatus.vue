<template>
  <div v-if="isProcessing || isComplete || hasError" class="async-operation-status">
    <!-- Processing State -->
    <div v-if="isProcessing" class="status-card processing">
      <div class="status-icon">
        <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>
      <div class="status-content">
        <h3 class="status-title">{{ processingMessage }}</h3>
        <p class="status-description">{{ processingDescription }}</p>
        
        <!-- Progress Bar -->
        <div v-if="showProgress" class="progress-bar-container">
          <div class="progress-bar">
            <div class="progress-fill" :style="{ width: `${progress}%` }"></div>
          </div>
          <span class="progress-text">{{ progress }}%</span>
        </div>
      </div>
    </div>

    <!-- Success State -->
    <div v-else-if="isComplete" class="status-card success">
      <div class="status-icon">
        <svg class="h-8 w-8 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
        </svg>
      </div>
      <div class="status-content">
        <h3 class="status-title">{{ successMessage }}</h3>
        <p v-if="successDescription" class="status-description">{{ successDescription }}</p>
      </div>
      <button v-if="showCloseButton" @click="$emit('close')" class="close-button">
        <svg class="h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Error State -->
    <div v-else-if="hasError" class="status-card error">
      <div class="status-icon">
        <svg class="h-8 w-8 text-red-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </div>
      <div class="status-content">
        <h3 class="status-title">{{ errorTitle }}</h3>
        <p class="status-description">{{ errorMessage }}</p>
      </div>
      <button v-if="showRetryButton" @click="$emit('retry')" class="retry-button">
        Retry
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  isProcessing: {
    type: Boolean,
    default: false
  },
  isComplete: {
    type: Boolean,
    default: false
  },
  hasError: {
    type: Boolean,
    default: false
  },
  errorMessage: {
    type: String,
    default: 'An error occurred'
  },
  progress: {
    type: Number,
    default: 0
  },
  processingMessage: {
    type: String,
    default: 'Processing...'
  },
  processingDescription: {
    type: String,
    default: 'Please wait while we process your request.'
  },
  successMessage: {
    type: String,
    default: 'Success!'
  },
  successDescription: {
    type: String,
    default: ''
  },
  errorTitle: {
    type: String,
    default: 'Error'
  },
  showProgress: {
    type: Boolean,
    default: true
  },
  showCloseButton: {
    type: Boolean,
    default: true
  },
  showRetryButton: {
    type: Boolean,
    default: true
  }
})

defineEmits(['close', 'retry'])
</script>

<style scoped>
.async-operation-status {
  margin: 1rem 0;
}

.status-card {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 1rem 1.5rem;
  border-radius: 0.5rem;
  box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1);
  position: relative;
}

.status-card.processing {
  background-color: #eff6ff;
  border: 1px solid #bfdbfe;
}

.status-card.success {
  background-color: #f0fdf4;
  border: 1px solid #bbf7d0;
}

.status-card.error {
  background-color: #fef2f2;
  border: 1px solid #fecaca;
}

.status-icon {
  flex-shrink: 0;
}

.status-content {
  flex: 1;
}

.status-title {
  font-size: 1rem;
  font-weight: 600;
  margin: 0 0 0.25rem 0;
}

.status-card.processing .status-title {
  color: #1e40af;
}

.status-card.success .status-title {
  color: #15803d;
}

.status-card.error .status-title {
  color: #b91c1c;
}

.status-description {
  font-size: 0.875rem;
  color: #6b7280;
  margin: 0;
}

.progress-bar-container {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.progress-bar {
  flex: 1;
  height: 0.5rem;
  background-color: #e5e7eb;
  border-radius: 9999px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background-color: #3b82f6;
  border-radius: 9999px;
  transition: width 0.3s ease;
}

.progress-text {
  font-size: 0.75rem;
  font-weight: 600;
  color: #6b7280;
  min-width: 3rem;
  text-align: right;
}

.close-button,
.retry-button {
  padding: 0.5rem;
  border-radius: 0.375rem;
  border: none;
  background: transparent;
  cursor: pointer;
  transition: background-color 0.2s;
}

.close-button:hover {
  background-color: rgba(0, 0, 0, 0.05);
}

.retry-button {
  padding: 0.5rem 1rem;
  background-color: #ef4444;
  color: white;
  font-weight: 500;
}

.retry-button:hover {
  background-color: #dc2626;
}

@keyframes spin {
  from {
    transform: rotate(0deg);
  }
  to {
    transform: rotate(360deg);
  }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
