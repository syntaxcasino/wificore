import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

export function useAuth() {
  const router = useRouter()
  const user = ref(null)
  const isAuthenticated = ref(false)
  const token = ref(null)

  // Check if user is logged in (e.g., on app load)
  const checkAuth = () => {
    const token = localStorage.getItem('authToken')
    const userData = localStorage.getItem('user')

    if (token && userData) {
      try {
        user.value = JSON.parse(userData)
        isAuthenticated.value = true
      } catch (error) {
        console.warn('Failed to parse user data from localStorage:', error)
        localStorage.removeItem('user') // Prevent repeated errors
        user.value = null
        isAuthenticated.value = false
      }
    }
  }

  // Login function with RADIUS authentication via Laravel backend
  const login = async (username, password) => {
    try {
      // Call Laravel backend which validates via RADIUS and returns Sanctum token
      const response = await axios.post('login', {
        username,
        password,
      })

      if (response.data.success && response.data.token) {
        const sanctumToken = response.data.token
        const userData = response.data.user

        // Store token and user data
        localStorage.setItem('authToken', sanctumToken)
        localStorage.setItem('user', JSON.stringify(userData))

        user.value = userData
        token.value = sanctumToken
        isAuthenticated.value = true

        // Token will be automatically added by axios interceptor for protected routes
        
        // Reconnect Echo with the new token
        if (window.Echo) {
          window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${sanctumToken}`
        }

        return { success: true, user: userData }
      } else {
        throw new Error(response.data.message || 'Authentication failed')
      }
    } catch (error) {
      console.error('Login error:', error)
      
      // Check if verification is required
      const errorData = error.response?.data
      if (errorData?.requires_verification) {
        return {
          success: false,
          error: errorData.message,
          requires_verification: true,
          email: errorData.email
        }
      }
      
      return { 
        success: false, 
        error: error.response?.data?.message || error.message || 'Login failed'
      }
    }
  }

  // Register function
  const register = async (userData) => {
    try {
      const response = await axios.post('register', userData)

      if (response.data.success) {
        // Check if email verification is required
        if (response.data.requires_verification) {
          return { 
            success: true, 
            requires_verification: true,
            message: response.data.message,
            user: response.data.user
          }
        }
        
        // If token is provided (email already verified or auto-login)
        if (response.data.token) {
          const sanctumToken = response.data.token
          const user = response.data.user

          // Store token and user data
          localStorage.setItem('authToken', sanctumToken)
          localStorage.setItem('user', JSON.stringify(user))

          user.value = user
          token.value = sanctumToken
          isAuthenticated.value = true

          // Reconnect Echo with the new token
          if (window.Echo) {
            window.Echo.connector.options.auth.headers['Authorization'] = `Bearer ${sanctumToken}`
          }

          return { success: true, user }
        }
        
        return { success: true, message: response.data.message }
      } else {
        throw new Error(response.data.message || 'Registration failed')
      }
    } catch (error) {
      console.error('Registration error:', error)
      return { 
        success: false, 
        error: error.response?.data?.message || error.message || 'Registration failed'
      }
    }
  }

  // Resend verification email
  const resendVerification = async (email) => {
    try {
      const response = await axios.post('email/resend', { email })
      
      return {
        success: response.data.success,
        message: response.data.message
      }
    } catch (error) {
      console.error('Resend verification error:', error)
      return {
        success: false,
        error: error.response?.data?.message || 'Failed to resend verification email'
      }
    }
  }

  // Logout function
  const logout = async () => {
    try {
      // Call backend to revoke token
      await axios.post('logout')
    } catch (error) {
      console.error('Logout error:', error)
    } finally {
      // Clear local storage and state
      localStorage.removeItem('authToken')
      localStorage.removeItem('user')
      delete axios.defaults.headers.common['Authorization']
      
      user.value = null
      token.value = null
      isAuthenticated.value = false
      
      // Disconnect Echo
      if (window.Echo) {
        window.Echo.disconnect()
      }
      
      router.push('/login')
    }
  }

  // Initialize auth state (don't set axios header globally, let interceptor handle it)
  const initializeAuth = () => {
    const storedToken = localStorage.getItem('authToken')
    if (storedToken) {
      token.value = storedToken
      // Don't set axios.defaults here - the interceptor in main.js handles it per-request
    }
    // Load user data from localStorage
    checkAuth()
  }

  // Initialize on composable creation
  initializeAuth()

  return {
    user,
    token,
    isAuthenticated,
    checkAuth,
    login,
    register,
    resendVerification,
    logout,
    initializeAuth,
  }
}

export default useAuth
