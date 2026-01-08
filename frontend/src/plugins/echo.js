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
    
    // Custom authorizer - ONLY for private/presence channels
    // Public channels (like tenant.{id}.vpn) don't need authentication
    authorizer: (channel, options) => {
      // Only authenticate private and presence channels
      // Public channels should not trigger authentication
      if (!channel.name.startsWith('private-') && !channel.name.startsWith('presence-')) {
        if (IS_DEV) {
          console.log('📢 Public channel, skipping auth:', channel.name);
        }
        // Return null to let Pusher handle public channels without auth
        return null;
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

// Debug connection
if (IS_DEV) {
  echoInstance.connector.pusher.connection.bind('connecting', () => {
    console.log('🔌 Connecting to Soketi via Nginx proxy (ws://localhost/app)...');
  });
  
  echoInstance.connector.pusher.connection.bind('connected', () => {
    console.log('✅ Connected to Soketi successfully!');
    console.log('📡 Socket ID:', echoInstance.socketId());
  });
  
  echoInstance.connector.pusher.connection.bind('error', (error) => {
    console.error('💥 Connection error:', error);
  });
  
  echoInstance.connector.pusher.connection.bind('disconnected', () => {
    console.warn('⚠️ Disconnected from Soketi');
  });
}

window.Echo = echoInstance;
export default echoInstance;

