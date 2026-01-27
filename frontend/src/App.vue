<template>
  <div class="h-screen flex flex-col bg-gray-100">
    <router-view class="flex-1 overflow-y-auto" />
    <PWAUpdatePrompt />
    
    <!-- Notification Toast (Global) -->
    <NotificationToast />

    <ConfirmDialog />
    
    <!-- Event Monitor (Development Only) -->
    <EventMonitor v-if="isDevelopment && showEventMonitor" @close="showEventMonitor = false" />
    
    <!-- Toggle Button for Event Monitor -->
    <button v-if="isDevelopment && !showEventMonitor"
            @click="showEventMonitor = true"
            class="fixed bottom-4 right-4 p-3 bg-gray-900 text-white rounded-full shadow-lg hover:bg-gray-800 transition-colors z-50"
            title="Show Event Monitor">
      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
              d="M13 10V3L4 14h7v7l9-11h-7z" />
      </svg>
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import EventMonitor from '@/modules/common/components/debug/EventMonitor.vue'
import PWAUpdatePrompt from '@/components/PWAUpdatePrompt.vue'
import NotificationToast from '@/components/NotificationToast.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const isDevelopment = import.meta.env.DEV
const showEventMonitor = ref(isDevelopment)
</script>
