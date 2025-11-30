<template>
  <div class="fixed bottom-4 right-4 w-96 bg-gray-900 rounded-lg shadow-2xl border border-gray-700 overflow-hidden z-50">
    <!-- Header -->
    <div class="flex items-center justify-between px-4 py-3 bg-gray-800 border-b border-gray-700">
      <div class="flex items-center gap-2">
        <div :class="connectionStatusClass" class="w-2 h-2 rounded-full"></div>
        <h3 class="text-sm font-semibold text-gray-100">WebSocket Events</h3>
        <span class="text-xs text-gray-400">({{ events.length }})</span>
      </div>
      <div class="flex items-center gap-2">
        <button @click="clearEvents" class="text-xs text-gray-400 hover:text-gray-200 transition-colors">
          Clear
        </button>
        <button @click="isMinimized = !isMinimized" class="text-gray-400 hover:text-gray-200 transition-colors">
          <svg v-if="!isMinimized" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
          <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
          </svg>
        </button>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-200 transition-colors">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Content -->
    <div v-if="!isMinimized" class="max-h-96 overflow-y-auto bg-gray-900">
      <!-- Connection Info -->
      <div class="px-4 py-2 bg-gray-800 border-b border-gray-700 text-xs">
        <div class="flex justify-between text-gray-400">
          <span>Socket ID:</span>
          <span class="font-mono text-gray-300">{{ socketId || 'Not connected' }}</span>
        </div>
        <div class="flex justify-between text-gray-400 mt-1">
          <span>Channels:</span>
          <span class="text-gray-300">{{ channelCount }}</span>
        </div>
      </div>

      <!-- Events List -->
      <div class="divide-y divide-gray-800">
        <div v-for="event in events.slice(0, 50)" :key="event.id" 
             class="px-4 py-2 hover:bg-gray-800 transition-colors">
          <div class="flex items-start gap-2">
            <span class="text-xs text-gray-500 font-mono flex-shrink-0 w-16">
              {{ event.time }}
            </span>
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <span :class="getEventTypeClass(event.type)" class="text-xs font-medium">
                  {{ event.type }}
                </span>
                <span class="text-xs text-gray-400 truncate">{{ event.channel }}</span>
              </div>
              <p class="text-xs text-gray-300 mt-1">{{ event.event }}</p>
              <p v-if="event.message" class="text-xs text-gray-400 mt-1">{{ event.message }}</p>
              <div v-if="event.progress !== undefined" class="mt-2">
                <div class="flex justify-between text-xs text-gray-400 mb-1">
                  <span>{{ event.stage }}</span>
                  <span>{{ event.progress }}%</span>
                </div>
                <div class="w-full bg-gray-700 rounded-full h-1">
                  <div class="bg-blue-500 h-1 rounded-full transition-all duration-300" 
                       :style="{ width: event.progress + '%' }"></div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div v-if="events.length === 0" class="px-4 py-8 text-center text-gray-500 text-sm">
          <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" 
                  d="M8 12h.01M12 12h.01M16 12h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <p>No events yet</p>
          <p class="text-xs mt-1">Events will appear here in real-time</p>
        </div>
      </div>
    </div>

    <!-- Footer -->
    <div v-if="!isMinimized" class="px-4 py-2 bg-gray-800 border-t border-gray-700 text-xs text-gray-400">
      Last update: {{ lastUpdate }}
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'

const events = ref([])
const isMinimized = ref(false)
const connectionStatus = ref('disconnected')
const socketId = ref('')
const lastUpdate = ref('Never')

let eventId = 0

const connectionStatusClass = computed(() => {
  return {
    'bg-green-500 animate-pulse': connectionStatus.value === 'connected',
    'bg-yellow-500 animate-pulse': connectionStatus.value === 'connecting',
    'bg-red-500': connectionStatus.value === 'disconnected',
  }
})

const channelCount = computed(() => {
  if (!window.Echo?.connector?.channels) return 0
  return Object.keys(window.Echo.connector.channels).length
})

const addEvent = (type, channel, event, data = {}) => {
  const now = new Date()
  events.value.unshift({
    id: ++eventId,
    time: now.toLocaleTimeString(),
    type,
    channel,
    event,
    message: data.message || '',
    stage: data.stage || '',
    progress: data.progress,
    data: data
  })
  
  lastUpdate.value = now.toLocaleTimeString()
  
  // Keep only last 100 events
  if (events.value.length > 100) {
    events.value = events.value.slice(0, 100)
  }
}

const clearEvents = () => {
  events.value = []
  lastUpdate.value = 'Cleared'
}

const getEventTypeClass = (type) => {
  const classes = {
    'connected': 'text-green-400',
    'subscribed': 'text-blue-400',
    'event': 'text-purple-400',
    'error': 'text-red-400',
    'disconnected': 'text-yellow-400'
  }
  return classes[type] || 'text-gray-400'
}

const updateConnectionStatus = () => {
  if (!window.Echo) return
  
  const state = window.Echo.connector.pusher.connection.state
  connectionStatus.value = state
  socketId.value = window.Echo.socketId() || ''
}

onMounted(() => {
  if (!window.Echo) {
    console.warn('Echo not available')
    return
  }

  // Monitor connection state
  updateConnectionStatus()
  
  const connection = window.Echo.connector.pusher.connection
  
  connection.bind('connecting', () => {
    connectionStatus.value = 'connecting'
    addEvent('connecting', 'system', 'Connecting to WebSocket server')
  })
  
  connection.bind('connected', () => {
    connectionStatus.value = 'connected'
    updateConnectionStatus()
    addEvent('connected', 'system', 'Connected to WebSocket server', {
      message: `Socket ID: ${socketId.value}`
    })
  })
  
  connection.bind('disconnected', () => {
    connectionStatus.value = 'disconnected'
    addEvent('disconnected', 'system', 'Disconnected from WebSocket server')
  })
  
  connection.bind('error', (error) => {
    addEvent('error', 'system', 'Connection error', {
      message: error.message || 'Unknown error'
    })
  })

  // Intercept all channel subscriptions
  const originalPrivate = window.Echo.private.bind(window.Echo)
  const originalChannel = window.Echo.channel.bind(window.Echo)
  
  window.Echo.private = function(channel) {
    addEvent('subscribed', channel, 'Subscribing to private channel')
    const channelInstance = originalPrivate(channel)
    
    // Wrap listen to capture events
    const originalListen = channelInstance.listen.bind(channelInstance)
    channelInstance.listen = function(event, callback) {
      return originalListen(event, (data) => {
        addEvent('event', channel, event, data)
        callback(data)
      })
    }
    
    return channelInstance
  }
  
  window.Echo.channel = function(channel) {
    addEvent('subscribed', channel, 'Subscribing to public channel')
    const channelInstance = originalChannel(channel)
    
    // Wrap listen to capture events
    const originalListen = channelInstance.listen.bind(channelInstance)
    channelInstance.listen = function(event, callback) {
      return originalListen(event, (data) => {
        addEvent('event', channel, event, data)
        callback(data)
      })
    }
    
    return channelInstance
  }
})

onUnmounted(() => {
  // Cleanup if needed
})
</script>

<style scoped>
/* Custom scrollbar */
.overflow-y-auto::-webkit-scrollbar {
  width: 6px;
}

.overflow-y-auto::-webkit-scrollbar-track {
  background: #1f2937;
}

.overflow-y-auto::-webkit-scrollbar-thumb {
  background: #4b5563;
  border-radius: 3px;
}

.overflow-y-auto::-webkit-scrollbar-thumb:hover {
  background: #6b7280;
}
</style>
