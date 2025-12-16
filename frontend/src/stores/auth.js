import { defineStore } from 'pinia'
import axios from 'axios'
import { websocketService } from '@/services/websocket'

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
      if (!this.user) {
        console.warn('Cannot initialize WebSocket: No user authenticated')
        return
      }
      
      try {
        console.log('🔌 Initializing WebSocket for user:', this.user.username)
        
        // Initialize WebSocket service
        websocketService.initialize()
        
        // Subscribe to tenant channel if user has tenant
        if (this.tenantId) {
          websocketService.subscribeTenantChannel(this.tenantId)
        }
        
        // Subscribe to user private channel
        if (this.user.id) {
          websocketService.subscribeUserChannel(this.user.id)
        }
        
        // Subscribe to system admin channel if user is system admin
        if (this.isSystemAdmin) {
          websocketService.subscribeSystemAdminChannel()
        }
        
        console.log('✅ WebSocket initialized successfully')
      } catch (error) {
        console.error('❌ Failed to initialize WebSocket:', error)
      }
    },
    
    disconnectWebSocket() {
      try {
        console.log('🔌 Disconnecting WebSocket')
        websocketService.disconnect()
        console.log('✅ WebSocket disconnected')
      } catch (error) {
        console.error('❌ Failed to disconnect WebSocket:', error)
      }
    },
  },
})
