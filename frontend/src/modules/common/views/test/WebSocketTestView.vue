<template>
  <div class="p-6">
    <h1 class="text-2xl font-bold mb-4">WebSocket Connection Test</h1>
    
    <div class="bg-white rounded-lg shadow p-6 mb-6">
      <h2 class="text-xl font-semibold mb-4">Connection Status</h2>
      <div class="space-y-2">
        <div class="flex items-center">
          <div 
            :class="[
              'w-3 h-3 rounded-full mr-2',
              connectionStatus === 'connected' ? 'bg-green-500' : 
              connectionStatus === 'connecting' ? 'bg-yellow-500' : 'bg-red-500'
            ]"
          ></div>
          <span>Status: {{ connectionStatus }}</span>
        </div>
        <div v-if="error" class="text-red-500 text-sm">{{ error }}</div>
      </div>
    </div>

    <div class="bg-white rounded-lg shadow p-6 mb-6">
      <h2 class="text-xl font-semibold mb-4">Test Private Channel</h2>
      <div class="space-y-4">
        <button 
          @click="subscribeToPrivateChannel" 
          :disabled="!isAuthenticated || isSubscribed" 
          class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 disabled:opacity-50"
        >
          {{ isSubscribed ? 'Subscribed' : 'Subscribe to Private Channel' }}
        </button>
        
        <button 
          @click="sendTestEvent" 
          :disabled="!isAuthenticated || !isSubscribed" 
          class="ml-4 px-4 py-2 bg-green-500 text-white rounded hover:bg-green-600 disabled:opacity-50"
        >
          Send Test Event
        </button>
        
        <div v-if="events.length > 0" class="mt-4">
          <h3 class="font-medium mb-2">Received Events:</h3>
          <div v-for="(event, index) in events" :key="index" class="bg-gray-50 p-3 rounded mb-2 text-sm">
            <div class="font-mono">{{ event.timestamp }} - {{ event.channel }}: {{ event.event }}</div>
            <div class="mt-1 text-gray-600">{{ JSON.stringify(event.data, null, 2) }}</div>
          </div>
        </div>
      </div>
    </div>
    
    <div class="bg-white rounded-lg shadow p-6">
      <h2 class="text-xl font-semibold mb-4">Logs</h2>
      <div class="bg-black text-green-400 p-4 rounded font-mono text-sm h-64 overflow-auto">
        <div v-for="(log, index) in logs" :key="index" class="mb-1">{{ log }}</div>
      </div>
    </div>
  </div>
</template>

<script>
import { ref, onMounted, onUnmounted } from 'vue';
import { useAuthStore } from '@/stores/auth';
import Echo from '@/plugins/echo';

export default {
  name: 'WebSocketTest',
  
  setup() {
    const authStore = useAuthStore();
    const connectionStatus = ref('disconnected');
    const isSubscribed = ref(false);
    const events = ref([]);
    const logs = ref([]);
    const error = ref('');
    let channel = null;
    let privateChannel = null;

    const log = (message) => {
      const timestamp = new Date().toISOString().substr(11, 12);
      logs.value.unshift(`[${timestamp}] ${message}`);
      if (logs.value.length > 100) logs.value.pop();
      console.log(`[WebSocketTest] ${message}`);
    };

    const updateConnectionStatus = () => {
      if (Echo.connector.pusher.connection.state === 'connected') {
        connectionStatus.value = 'connected';
        log('Connected to WebSocket server');
      } else if (Echo.connector.pusher.connection.state === 'connecting') {
        connectionStatus.value = 'connecting';
        log('Connecting to WebSocket server...');
      } else {
        connectionStatus.value = 'disconnected';
        log('Disconnected from WebSocket server');
      }
    };

    const subscribeToPrivateChannel = () => {
      if (!authStore.isAuthenticated) {
        error.value = 'You must be logged in to subscribe to private channels';
        log('❌ Authentication required for private channels');
        return;
      }

      try {
        log(`🔐 Subscribing to private-test-channel.${authStore.user.id}...`);
        
        // Subscribe to a private channel
        privateChannel = Echo.private(`test-channel.${authStore.user.id}`);
        
        privateChannel
          .listen('.test.event', (data) => {
            log('✅ Received test.event');
            const event = {
              timestamp: new Date().toLocaleTimeString(),
              channel: `private-test-channel.${authStore.user.id}`,
              event: 'test.event',
              data: data
            };
            events.value.unshift(event);
          })
          .listenForWhisper('typing', (e) => {
            log(`💬 Whisper received: ${JSON.stringify(e)}`);
          })
          .subscribed(() => {
            isSubscribed.value = true;
            log(`✅ Successfully subscribed to private-test-channel.${authStore.user.id}`);
          })
          .error((err) => {
            error.value = `Channel error: ${err.message || 'Unknown error'}`;
            log(`❌ Channel error: ${err.message || 'Unknown error'}`);
            console.error('Channel error:', err);
          });

      } catch (err) {
        error.value = `Failed to subscribe to channel: ${err.message}`;
        log(`❌ Subscription failed: ${err.message}`);
        console.error('Subscription error:', err);
      }
    };

    const sendTestEvent = async () => {
      if (!isSubscribed.value) return;
      
      try {
        const response = await fetch('/test/websocket', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'Accept': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
            'Authorization': `Bearer ${authStore.token}`
          },
          body: JSON.stringify({
            message: 'Hello from the client!',
            userId: authStore.user?.id
          })
        });
        
        const data = await response.json();
        log(`Test event sent: ${JSON.stringify(data)}`);
      } catch (err) {
        error.value = `Failed to send test event: ${err.message}`;
        console.error('Send test event error:', err);
      }
    };

    // Set up event listeners when component mounts
    onMounted(() => {
      log('WebSocket test component mounted');
      
      // Set initial connection status
      updateConnectionStatus();
      
      // Listen for connection state changes
      Echo.connector.pusher.connection.bind('state_change', (states) => {
        updateConnectionStatus();
        log(`Connection state changed: ${states.previous} -> ${states.current}`);
      });
      
      // Log all Pusher events for debugging
      if (process.env.NODE_ENV === 'development') {
        Echo.connector.pusher.connection.bind('connected', () => {
          log('Pusher connection established');
        });
        
        Echo.connector.pusher.connection.bind('error', (err) => {
          error.value = `Connection error: ${err.message}`;
          log(`Pusher error: ${err.message}`);
        });
      }
    });

    // Clean up when component is unmounted
    onUnmounted(() => {
      log('🛑 Component unmounting, cleaning up channels');
      
      if (privateChannel) {
        Echo.leave(`test-channel.${authStore.user?.id}`);
        log(`Left private channel: test-channel.${authStore.user?.id}`);
      }
      
      if (channel) {
        Echo.leave(channel.name);
        log(`Left channel: ${channel.name}`);
      }
    });

    return {
      isAuthenticated: authStore.isAuthenticated,
      connectionStatus,
      isSubscribed,
      events,
      logs,
      error,
      subscribeToPrivateChannel,
      sendTestEvent
    };
  }
};
</script>
