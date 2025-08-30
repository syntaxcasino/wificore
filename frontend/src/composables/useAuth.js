import { ref } from 'vue'
import { useRouter } from 'vue-router'

export function useAuth() {
  const router = useRouter()
  const user = ref(null)
  const isAuthenticated = ref(false)

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

  // Login function
  const login = async (credentials) => {
    try {
      // In a real app, this would be an API call
      if (credentials.username === 'admin' && credentials.password === 'password') {
        const mockUser = {
          id: 1,
          name: 'Admin User',
          email: 'admin@tradinet.com',
        }

        localStorage.setItem('authToken', 'mock-token-123')
        localStorage.setItem('user', JSON.stringify(mockUser))

        user.value = mockUser
        isAuthenticated.value = true

        return { success: true }
      } else {
        throw new Error('Invalid credentials')
      }
    } catch (error) {
      return { success: false, error: error.message }
    }
  }

  // Logout function
  const logout = () => {
    localStorage.removeItem('authToken')
    localStorage.removeItem('user')
    user.value = null
    isAuthenticated.value = false
    router.push('/login')
  }

  return {
    user,
    isAuthenticated,
    checkAuth,
    login,
    logout,
  }
}

export default useAuth
