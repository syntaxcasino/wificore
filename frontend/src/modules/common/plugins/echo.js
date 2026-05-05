// plugins/echo.js - WebSocket Configuration for Laravel Echo
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const IS_DEV = import.meta.env.DEV;

// Helper functions for authentication
export const getAuthToken = () => {
  return localStorage.getItem('authToken') || '';
};

export const getCSRFToken = () => {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
};

// Create Echo configuration
const createEchoConfig = () => {
  const env = import.meta.env;
  const authToken = getAuthToken();
  
  // Detect if we're in production (HTTPS)
  const isProduction = window.location.protocol === 'https:';
  const wsHost = env.VITE_PUSHER_HOST || window.location.hostname;
  
  // WebSocket configuration
  const config = {
    broadcaster: 'pusher',
    key: env.VITE_PUSHER_APP_KEY || 'app-key',
    wsHost: wsHost,
    wsPort: isProduction ? 443 : (env.VITE_PUSHER_PORT || 80),
    wssPort: 443,
    forceTLS: isProduction, // Use TLS in production
    encrypted: isProduction, // Encrypt in production
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: env.VITE_PUSHER_APP_CLUSTER || 'mt1',
    
    // Path configuration - empty string so Pusher appends /app (not //app)
    path: env.VITE_PUSHER_PATH || '',
    
    // Authentication - Use broadcasting auth endpoint (nginx routes to backend)
    authEndpoint: env.VITE_PUSHER_AUTH_ENDPOINT || '/api/broadcasting/auth',
    auth: {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCSRFToken(),
        'Authorization': authToken ? `Bearer ${authToken}` : '',
        'Accept': 'application/json',
      },
    },
    
    // Custom authorizer for private and presence channels
    // All tenant channels use PrivateChannel and require authentication
    authorizer: (channel, options) => {
      // Skip auth only for truly public channels (no prefix)
      // Private channels have 'private-' prefix, presence channels have 'presence-' prefix
      if (!channel.name.startsWith('private-') && !channel.name.startsWith('presence-')) {
        if (IS_DEV) {
          console.log('📢 Public channel, skipping auth:', channel.name);
        }
        return null;
      }
      
      if (IS_DEV) {
        console.log('🔐 Authenticating channel:', channel.name);
      }
      
      return {
        authorize: (socketId, callback) => {
          const headers = {
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': getCSRFToken(),
            'Accept': 'application/json',
            'Content-Type': 'application/json',
          };
          
          const authToken = getAuthToken();
          if (authToken) {
            headers['Authorization'] = `Bearer ${authToken}`;
          }
          
          // Use API endpoint for Sanctum-based authentication
          const authEndpoint = env.VITE_PUSHER_AUTH_ENDPOINT || '/api/broadcasting/auth';
          
          fetch(authEndpoint, {
            method: 'POST',
            headers: headers,
            body: JSON.stringify({
              socket_id: socketId,
              channel_name: channel.name
            }),
            credentials: 'same-origin',
          })
          .then(response => {
            if (!response.ok) {
              throw new Error(`Auth failed with status ${response.status}`);
            }
            return response.json();
          })
          .then(data => {
            if (IS_DEV) {
              console.log('🔑 Channel auth response:', { channel: channel.name, data, endpoint: authEndpoint });
            }
            callback(false, data);
          })
          .catch(error => {
            console.error('🔴 Channel auth error:', { channel: channel.name, error: error.message });
            callback(true, error);
          });
        }
      };
    }
  };

  // Logging
  console.log('🔧 Echo WebSocket Configuration:', {
    mode: isProduction ? 'PRODUCTION' : 'DEVELOPMENT',
    protocol: isProduction ? 'wss://' : 'ws://',
    host: config.wsHost,
    port: config.wsPort,
    secure: config.forceTLS,
    authEndpoint: config.authEndpoint,
    key: config.key,
    path: config.path,
    fullUrl: `${isProduction ? 'wss' : 'ws'}://${config.wsHost}:${config.wsPort}${config.path}`
  });
  
  // Enable Pusher debug logging in development
  if (IS_DEV) {
    Pusher.logToConsole = true;
  }

  return config;
};

// Initialize Pusher with console logging in development
Pusher.logToConsole = IS_DEV;

// Create Echo instance
const echoInstance = new Echo(createEchoConfig());

// Reconnection state
let reconnectTimer = null;
let reconnectAttempts = 0;
const MAX_RECONNECT_ATTEMPTS = 10;
const BASE_RECONNECT_DELAY = 2000;
const MAX_RECONNECT_DELAY = 30000;
let intentionalDisconnect = false;

/**
 * Schedule reconnection with exponential backoff
 */
const scheduleReconnect = () => {
  if (reconnectTimer) return; // Already scheduled
  
  if (reconnectAttempts >= MAX_RECONNECT_ATTEMPTS) {
    console.error('❌ Max reconnection attempts reached for Echo instance');
    return;
  }
  
  const delay = Math.min(
    BASE_RECONNECT_DELAY * Math.pow(2, reconnectAttempts),
    MAX_RECONNECT_DELAY
  );
  
  reconnectAttempts++;
  
  console.log(`🔄 Echo: Scheduling reconnection in ${delay}ms (attempt ${reconnectAttempts}/${MAX_RECONNECT_ATTEMPTS})`);
  
  reconnectTimer = setTimeout(() => {
    reconnectTimer = null;
    console.log(`🔄 Echo: Attempting to reconnect...`);
    
    // Disconnect without marking as intentional so further failures can retry
    try {
      originalDisconnect();
    } catch (e) {
      // Ignore disconnect errors
    }
    
    // Reset flag before reconnecting so error/disconnected events can trigger further retries
    intentionalDisconnect = false;
    echoInstance.connect();
  }, delay);
};

/**
 * Clear reconnection timer
 */
const clearReconnectTimer = () => {
  if (reconnectTimer) {
    clearTimeout(reconnectTimer);
    reconnectTimer = null;
  }
};

// Clean disconnect method for logout - declared before event handlers so it's available in callbacks
const originalDisconnect = echoInstance.disconnect.bind(echoInstance);
echoInstance.disconnect = () => {
  intentionalDisconnect = true;
  clearReconnectTimer();
  originalDisconnect();
};

// Connection event handlers
echoInstance.connector.pusher.connection.bind('connecting', () => {
  if (IS_DEV) {
    console.log('🔌 Connecting to Soketi via Nginx proxy...');
  }
});

echoInstance.connector.pusher.connection.bind('connected', () => {
  if (IS_DEV) {
    console.log('✅ Connected to Soketi successfully!');
    console.log('📡 Socket ID:', echoInstance.socketId());
  }
  // Reset reconnection state on successful connection
  reconnectAttempts = 0;
  clearReconnectTimer();
});

echoInstance.connector.pusher.connection.bind('error', (error) => {
  if (IS_DEV) {
    console.error('💥 Connection error:', error);
  }
  if (!intentionalDisconnect) {
    scheduleReconnect();
  }
});

echoInstance.connector.pusher.connection.bind('disconnected', () => {
  if (IS_DEV) {
    console.warn('⚠️ Disconnected from Soketi');
  }
  if (!intentionalDisconnect) {
    scheduleReconnect();
  }
});

// Handle page visibility change - reconnect when tab becomes active
if (typeof document !== 'undefined') {
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      const state = echoInstance.connector.pusher.connection.state;
      if (state !== 'connected') {
        console.log('📱 Page visible, Echo not connected - reconnecting...');
        reconnectAttempts = 0; // Reset counter for manual reconnect
        clearReconnectTimer();
        intentionalDisconnect = false; // Ensure reconnect loop is active
        try {
          originalDisconnect();
        } catch (e) {
          // Ignore
        }
        echoInstance.connect();
      }
    }
  });
}

window.Echo = echoInstance;
export default echoInstance;

