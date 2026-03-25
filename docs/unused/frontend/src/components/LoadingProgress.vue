<template>
  <div v-if="show" class="loading-overlay">
    <div class="loading-card">
      <!-- Icon/Logo -->
      <div class="loading-icon">
        <svg class="spinner" viewBox="0 0 50 50">
          <circle class="path" cx="25" cy="25" r="20" fill="none" stroke-width="4"></circle>
        </svg>
      </div>

      <!-- Title -->
      <h3 class="loading-title">{{ title }}</h3>

      <!-- Message -->
      <p class="loading-message">{{ message }}</p>

      <!-- Progress Bar -->
      <div class="progress-container">
        <div class="progress-bar">
          <div 
            class="progress-fill" 
            :style="{ width: `${progress}%` }"
          ></div>
        </div>
        <span class="progress-text">{{ progress }}%</span>
      </div>

      <!-- Status Message -->
      <p v-if="statusMessage" class="status-message">{{ statusMessage }}</p>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  },
  title: {
    type: String,
    default: '🔄 Processing...'
  },
  message: {
    type: String,
    default: 'Please wait while we process your request'
  },
  progress: {
    type: Number,
    default: 0,
    validator: (value) => value >= 0 && value <= 100
  },
  statusMessage: {
    type: String,
    default: ''
  }
})
</script>

<style scoped>
.loading-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.5);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 9998;
  animation: fadeIn 0.3s ease;
}

@keyframes fadeIn {
  from {
    opacity: 0;
  }
  to {
    opacity: 1;
  }
}

.loading-card {
  background: white;
  border-radius: 1rem;
  padding: 2rem;
  max-width: 28rem;
  width: 90%;
  box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
  text-align: center;
  animation: slideUp 0.3s ease;
}

@keyframes slideUp {
  from {
    transform: translateY(20px);
    opacity: 0;
  }
  to {
    transform: translateY(0);
    opacity: 1;
  }
}

.loading-icon {
  margin: 0 auto 1.5rem;
  width: 4rem;
  height: 4rem;
}

.spinner {
  animation: rotate 2s linear infinite;
  width: 100%;
  height: 100%;
}

.spinner .path {
  stroke: #10b981;
  stroke-linecap: round;
  animation: dash 1.5s ease-in-out infinite;
}

@keyframes rotate {
  100% {
    transform: rotate(360deg);
  }
}

@keyframes dash {
  0% {
    stroke-dasharray: 1, 150;
    stroke-dashoffset: 0;
  }
  50% {
    stroke-dasharray: 90, 150;
    stroke-dashoffset: -35;
  }
  100% {
    stroke-dasharray: 90, 150;
    stroke-dashoffset: -124;
  }
}

.loading-title {
  font-size: 1.5rem;
  font-weight: 700;
  color: #111827;
  margin: 0 0 0.5rem 0;
}

.loading-message {
  font-size: 0.875rem;
  color: #6b7280;
  margin: 0 0 1.5rem 0;
}

.progress-container {
  margin-bottom: 1rem;
}

.progress-bar {
  width: 100%;
  height: 0.5rem;
  background: #e5e7eb;
  border-radius: 9999px;
  overflow: hidden;
  margin-bottom: 0.5rem;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #10b981, #059669);
  border-radius: 9999px;
  transition: width 0.3s ease;
  animation: shimmer 2s infinite;
}

@keyframes shimmer {
  0% {
    background-position: -100% 0;
  }
  100% {
    background-position: 100% 0;
  }
}

.progress-text {
  font-size: 0.875rem;
  font-weight: 600;
  color: #10b981;
}

.status-message {
  font-size: 0.75rem;
  color: #9ca3af;
  margin: 0.5rem 0 0 0;
  font-style: italic;
}

/* Responsive */
@media (max-width: 640px) {
  .loading-card {
    padding: 1.5rem;
  }

  .loading-title {
    font-size: 1.25rem;
  }
}
</style>
