<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-50 via-indigo-50 to-purple-50">
    <div class="bg-white p-8 rounded-2xl shadow-2xl w-full max-w-md text-center">
      <!-- Loading State -->
      <div v-if="verifying" class="space-y-4">
        <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto">
          <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
        </div>
        <h2 class="text-2xl font-bold text-gray-800">Verifying Email...</h2>
        <p class="text-gray-600">Please wait while we verify your email address.</p>
      </div>

      <!-- Success State -->
      <div v-else-if="verified" class="text-center">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6 animate-bounce">
          <svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path>
          </svg>
        </div>
        <h2 class="text-2xl font-bold text-gray-900 mb-2">✓ Email Verified Successfully!</h2>
        <p class="text-gray-600 mb-4">
          Your email has been verified and your workspace is being created.
        </p>
        <div class="flex items-center justify-center space-x-2 text-sm text-gray-500">
          <svg class="animate-spin h-4 w-4 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>Redirecting to registration page...</span>
        </div>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="space-y-4">
        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto">
          <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </div>
        <h2 class="text-2xl font-bold text-red-600">Verification Failed</h2>
        <p class="text-gray-600 px-4">{{ errorMessage }}</p>
        <div class="flex gap-3 justify-center mt-6">
          <button 
            @click="goToRegister"
            class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors font-medium"
          >
            Register Again
          </button>
          <button 
            @click="goToLogin"
            class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium"
          >
            Go to Login
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const route = useRoute()

const verifying = ref(true)
const verified = ref(false)
const error = ref(false)
const errorMessage = ref('')

onMounted(async () => {
  // Check if this is a tenant registration verification (token-based)
  const token = route.params.token || route.query.token
  
  if (token) {
    // Tenant registration verification
    try {
      const response = await axios.get(`/register/verify/${token}`)
      
      if (response.data.success) {
        verified.value = true
        
        // Redirect back to registration page after 2 seconds
        setTimeout(() => {
          window.location.href = '/register'
        }, 2000)
      } else {
        error.value = true
        errorMessage.value = response.data.message || 'Verification failed'
      }
    } catch (err) {
      error.value = true
      
      // Handle specific error cases
      if (err.response?.status === 404) {
        errorMessage.value = 'This verification link is invalid or has already been used. Please register again or contact support if you need assistance.'
      } else if (err.response?.status === 422) {
        errorMessage.value = 'This verification link has expired. Please register again to receive a new verification email.'
      } else {
        errorMessage.value = err.response?.data?.message || 'An error occurred during verification. Please try again or contact support.'
      }
      
      console.error('Verification error:', err)
    } finally {
      verifying.value = false
    }
  } else {
    // Legacy email verification (id/hash based)
    const { id, hash } = route.params
    
    try {
      const response = await axios.get(`/email/verify/${id}/${hash}`)
      
      if (response.data.success) {
        verified.value = true
        
        // If token is provided, store it and auto-login
        if (response.data.token) {
          localStorage.setItem('authToken', response.data.token)
          localStorage.setItem('user', JSON.stringify(response.data.user))
          
          // Redirect to dashboard after 2 seconds
          setTimeout(() => {
            router.push('/dashboard')
          }, 2000)
        } else {
          // Redirect to login after 2 seconds
          setTimeout(() => {
            router.push('/login')
          }, 2000)
        }
      } else {
        error.value = true
        errorMessage.value = response.data.message || 'Verification failed'
      }
    } catch (err) {
      error.value = true
      errorMessage.value = err.response?.data?.message || 'An error occurred during verification'
      console.error('Verification error:', err)
    } finally {
      verifying.value = false
    }
  }
})

const goToLogin = () => {
  router.push('/login')
}

const goToRegister = () => {
  router.push({ name: 'register' })
}
</script>
