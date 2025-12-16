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
  // WebSocket configuration
  const config = {
    broadcaster: 'pusher',
    key: env.VITE_PUSHER_APP_KEY || 'app-key',
    wsHost: env.VITE_PUSHER_HOST || window.location.hostname,
    wsPort: env.VITE_PUSHER_PORT || window.location.port || 8070,
    wssPort: 443,
    forceTLS: false, // Disable forceTLS for development
    encrypted: false, // Disable encryption for development
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: env.VITE_PUSHER_APP_CLUSTER || 'mt1',
    
    // Path configuration - Pusher adds /app automatically, so we use empty path
    // The connection will be: ws://localhost:80/app/...
    path: env.VITE_PUSHER_PATH || '/app',
    
    // Authentication - Use API route for Sanctum-based auth
    authEndpoint: env.VITE_PUSHER_AUTH_ENDPOINT || '/api/broadcasting/auth',
    auth: {
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': getCSRFToken(),
        'Authorization': authToken ? `Bearer ${authToken}` : '',
        'Accept': 'application/json',
      },
    },
    
    // Additional Pusher options
    authorizer: (channel, options) => {
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
          .then(response => response.json())
          .then(data => {
            if (IS_DEV) {
              console.log('🔑 Channel auth response:', { channel: channel.name, data, endpoint: authEndpoint });
            }
            callback(false, data);
          })
          .catch(error => {
            console.error('🔴 Channel auth error:', error);
            callback(true, error);
          });
        }
      };
    }
  };

  // Development logging
  if (IS_DEV) {
    console.log('🔧 Echo WebSocket Configuration:', {
      host: config.wsHost,
      port: config.wsPort,
      secure: config.forceTLS,
      authEndpoint: config.authEndpoint,
      key: config.key
    });
    
    // Enable Pusher debug logging
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

