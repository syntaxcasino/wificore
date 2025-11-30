<template>
  <div class="error-message">
    <div class="flex items-center px-4 py-2 bg-red-50 text-red-700 rounded-md">
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
        />
      </svg>
      <span>{{ message }}</span>
      <button
        v-if="showRetry"
        @click="$emit('retry')"
        class="ml-auto px-2 py-1 text-xs bg-red-100 hover:bg-red-200 rounded transition-colors"
      >
        Retry
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  message: {
    type: String,
    required: true,
  },
  showRetry: {
    type: Boolean,
    default: false,
  },
})

defineEmits(['retry'])
</script>

<style scoped>
.error-message {
  font-family: inherit;
}
</style>
