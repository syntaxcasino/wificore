import { createApp, defineAsyncComponent } from 'vue'
import { createPinia } from 'pinia'
import axios from 'axios'
import App from './App.vue'
import router from './router'
import { useAuthStore } from './stores/auth'
import { useConfirmStore } from './stores/confirm'
import echo from './plugins/echo';
import { registerSW } from 'virtual:pwa-register'

import './assets/main.css'

let piniaRef = null

// Register Service Worker for PWA
const updateSW = registerSW({
  onNeedRefresh() {
    try {
      if (piniaRef) {
        const confirmStore = useConfirmStore(piniaRef)
        confirmStore
          .open({
            title: 'Update Available',
            message: 'New content is available. Reload to update?',
            confirmText: 'Reload',
            cancelText: 'Later',
            variant: 'primary',
          })
          .then((ok) => {
            if (ok) updateSW(true)
          })
        return
      }
    } catch (e) {
    }

    if (confirm('New content available. Reload to update?')) {
      updateSW(true)
    }
  },
  onOfflineReady() {
    console.log('App ready to work offline')
  },
})
// Configure axios
axios.defaults.baseURL = import.meta.env.VITE_API_BASE_URL

// List of public endpoints that don't require authentication
// Format: 'method:endpoint' or just 'endpoint' for all methods
const publicEndpoints = [
  'login',
  'GET:packages',  // Only GET /packages is public
  'payments/initiate',
  'mpesa/callback',
  'hotspot/login',
  'hotspot/logout',
  'hotspot/check-session'
]

// Request interceptor - conditionally add auth header
axios.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('authToken')
    const method = config.method?.toUpperCase()
    const url = config.url
    
    // Check if this is a public endpoint
    const isPublicEndpoint = publicEndpoints.some(endpoint => {
      // Check for method-specific endpoints (e.g., 'GET:packages')
      if (endpoint.includes(':')) {
        const [endpointMethod, endpointPath] = endpoint.split(':')
        return method === endpointMethod && url?.includes(endpointPath)
      }
      // Check for general endpoints (any method)
      return url?.includes(endpoint)
    })
    
    // Only add Authorization header if:
    // 1. Token exists
    // 2. Not a public endpoint OR user is intentionally authenticated
    if (token && !isPublicEndpoint) {
      config.headers.Authorization = `Bearer ${token}`
    }
    
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor - handle authentication errors
let isRedirecting = false

axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    console.error('API Error:', error)
    
    // Handle 401 Unauthorized errors
    if (error.response?.status === 401) {
      const method = error.config?.method?.toUpperCase()
      const url = error.config?.url
      
      // Check if this is a public endpoint (same logic as request interceptor)
      const isPublicEndpoint = publicEndpoints.some(endpoint => {
        if (endpoint.includes(':')) {
          const [endpointMethod, endpointPath] = endpoint.split(':')
          return method === endpointMethod && url?.includes(endpointPath)
        }
        return url?.includes(endpoint)
      })
      
      if (isPublicEndpoint) {
        // Public endpoint - just clear stale token
        console.warn('Received 401 on public endpoint, clearing stale token')
        delete axios.defaults.headers.common['Authorization']
      } else if (!isRedirecting) {
        // Protected endpoint - token is invalid/expired
        isRedirecting = true
        console.warn('Authentication failed - token expired or invalid. Redirecting to login...')
        
        // Clear all auth data
        localStorage.removeItem('authToken')
        localStorage.removeItem('user')
        delete axios.defaults.headers.common['Authorization']
        
        // Disconnect WebSocket
        if (window.Echo) {
          try {
            window.Echo.disconnect()
          } catch (e) {
            console.warn('Error disconnecting Echo:', e)
          }
        }
        
        // Redirect to login with return URL
        const currentPath = window.location.pathname + window.location.search
        const redirectPath = currentPath !== '/login' ? currentPath : '/dashboard'
        
        // Use router if available, otherwise use window.location
        if (router) {
          await router.push({
            name: 'login',
            query: { redirect: redirectPath }
          })
        } else {
          window.location.href = `/login?redirect=${encodeURIComponent(redirectPath)}`
        }
        
        // Reset flag after redirect
        setTimeout(() => { isRedirecting = false }, 1000)
      }
    }
    
    return Promise.reject(error)
  },
)



const app = createApp(App)

app.config.globalProperties.$echo = echo;
// Initialize Pinia
const pinia = createPinia()
app.use(pinia)
piniaRef = pinia

// Register global components dynamically
const requireComponent = import.meta.glob('./components/ui/*.vue')
Object.entries(requireComponent).forEach(([path, component]) => {
  const componentName = path
    .split('/')
    .pop()
    .replace(/\.\w+$/, '')
  app.component(componentName, defineAsyncComponent(component))
})

app.use(router)

// Initialize auth from localStorage
const authStore = useAuthStore()
authStore.initializeAuth()

// Mount the app after router is ready
router
  .isReady()
  .then(() => {
    app.mount('#app')
  })
  .catch((error) => {
    console.error('Router initialization failed:', error)
  })



