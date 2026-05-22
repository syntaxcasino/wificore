import { defineStore } from 'pinia'
import axios from 'axios'

export const useAuthStore = defineStore('auth', {
  state: () => ({
    user: null,
    token: localStorage.getItem('authToken') || null,
    isAuthenticated: !!localStorage.getItem('authToken'),
    role: localStorage.getItem('userRole') || null,
    tenantId: localStorage.getItem('tenantId') || null,
    dashboardRoute: localStorage.getItem('dashboardRoute') || '/dashboard',
  }),
  
  getters: {
    isSystemAdmin: (state) => state.role === 'system_admin',
    isTenantAdmin: (state) => state.role === 'admin',
    isHotspotUser: (state) => state.role === 'hotspot_user',
    currentUser: (state) => state.user,
  },
  
  actions: {
    async login(credentials) {
      try {
        const response = await axios.post('/login', {
          username: credentials.username || credentials.email,
          password: credentials.password,
          remember: credentials.remember || false,
        })

        if (response.data.success) {
          const { user, token, dashboard_route, abilities } = response.data.data
          
          this.user = user
          this.token = token
          this.role = user.role
          this.tenantId = user.tenant_id
          this.dashboardRoute = dashboard_route
          this.isAuthenticated = true
          
          // Store in localStorage
          localStorage.setItem('authToken', token)
          localStorage.setItem('userRole', user.role)
          localStorage.setItem('tenantId', user.tenant_id || '')
          localStorage.setItem('dashboardRoute', dashboard_route)
          localStorage.setItem('user', JSON.stringify(user))
          
          // Set axios default header
          axios.defaults.headers.common['Authorization'] = `Bearer ${token}`
          
          // Initialize WebSocket connection
          this.initializeWebSocket()
          
          return { success: true, user, dashboardRoute: dashboard_route }
        }
        
        return { success: false, error: response.data.message }
      } catch (error) {
        console.error('Login error:', error)
        return { 
          success: false, 
          error: error.response?.data?.message || 'Login failed. Please try again.' 
        }
      }
    },
    
    async logout() {
      try {
        // Disconnect WebSocket before logout
        this.disconnectWebSocket()
        
        if (this.token) {
          await axios.post('/logout', {}, {
            headers: { Authorization: `Bearer ${this.token}` }
          })
        }
      } catch (error) {
        console.error('Logout error:', error)
      } finally {
        this.clearAuth()
      }
    },
    
    clearAuth() {
      this.user = null
      this.token = null
      this.role = null
      this.tenantId = null
      this.isAuthenticated = false
      this.dashboardRoute = '/dashboard'
      
      // Clear all localStorage items
      localStorage.removeItem('authToken')
      localStorage.removeItem('userRole')
      localStorage.removeItem('tenantId')
      localStorage.removeItem('dashboardRoute')
      localStorage.removeItem('sidebar-active-menu')
      
      // Clear all sessionStorage items
      sessionStorage.clear()
      
      // Clear axios default headers
      delete axios.defaults.headers.common['Authorization']
      localStorage.removeItem('dashboardRoute')
      localStorage.removeItem('user')
      
      delete axios.defaults.headers.common['Authorization']
    },
    
    async fetchUser() {
      try {
        const response = await axios.get('/me', {
          headers: { Authorization: `Bearer ${this.token}` }
        })
        
        if (response.data.success) {
          this.user = response.data.user
          localStorage.setItem('user', JSON.stringify(response.data.user))
          return response.data.user
        }
      } catch (error) {
        console.error('Fetch user error:', error)
        if (error.response?.status === 401) {
          this.clearAuth()
        }
      }
    },
    
    async changePassword(currentPassword, newPassword, newPasswordConfirmation) {
      try {
        const response = await axios.post('/change-password', {
          current_password: currentPassword,
          new_password: newPassword,
          new_password_confirmation: newPasswordConfirmation,
        }, {
          headers: { Authorization: `Bearer ${this.token}` }
        })
        
        return { success: true, message: response.data.message }
      } catch (error) {
        return { 
          success: false, 
          error: error.response?.data?.message || 'Password change failed' 
        }
      }
    },
    
    initializeAuth() {
      const token = localStorage.getItem('authToken')
      const user = localStorage.getItem('user')
      
      if (token && user) {
        this.token = token
        this.user = JSON.parse(user)
        this.role = this.user.role
        this.tenantId = this.user.tenant_id
        this.isAuthenticated = true
        this.dashboardRoute = localStorage.getItem('dashboardRoute') || '/dashboard'
        
        axios.defaults.headers.common['Authorization'] = `Bearer ${token}`
        
        // Initialize WebSocket if user is authenticated
        this.initializeWebSocket()
      }
    },
    
    initializeWebSocket() {
      if (!this.user || typeof window === 'undefined' || !window.Echo) {
        return
      }

      const connection = window.Echo.connector?.pusher?.connection
      if (!connection) {
        return
      }

      const state = connection.state
      if (state === 'connected' || state === 'connecting') {
        return
      }

      try {
        window.Echo.connect()
      } catch (error) {
        console.error('Failed to connect Echo:', error)
      }
    },
    
    disconnectWebSocket() {
      if (typeof window === 'undefined' || !window.Echo) {
        return
      }

      try {
        window.Echo.disconnect()
      } catch (error) {
        console.error('Failed to disconnect Echo:', error)
      }
    },
    
    /**
     * Handle page visibility changes - reconnect WebSocket when tab becomes active
     */
    handleVisibilityChange() {
      if (document.visibilityState === 'visible' && this.isAuthenticated) {
        const wsState = window.Echo?.connector?.pusher?.connection?.state
        if (wsState !== 'connected' && wsState !== 'connecting') {
          this.initializeWebSocket()
        }
      }
    },
    
    /**
     * Setup visibility change listener for WebSocket reconnection
     */
    setupVisibilityListener() {
      if (typeof document !== 'undefined') {
        document.addEventListener('visibilitychange', () => this.handleVisibilityChange())
        console.log('✅ Page visibility listener registered for WebSocket reconnection')
      }
    },
    
    /**
     * Check and restore WebSocket connection if needed
     */
    restoreWebSocketIfNeeded() {
      if (!this.isAuthenticated) return

      const wsState = window.Echo?.connector?.pusher?.connection?.state
      if (wsState !== 'connected' && wsState !== 'connecting') {
        this.initializeWebSocket()
      }
    },
  },
})
