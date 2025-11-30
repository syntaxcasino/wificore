import { defineStore } from 'pinia'
import axios from 'axios'

const API_URL = import.meta.env.VITE_API_URL || 'http://localhost:8000/api'

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
        const response = await axios.post(`${API_URL}/login`, {
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
        if (this.token) {
          await axios.post(`${API_URL}/logout`, {}, {
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
        const response = await axios.get(`${API_URL}/me`, {
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
        const response = await axios.post(`${API_URL}/change-password`, {
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
      }
    },
  },
})
