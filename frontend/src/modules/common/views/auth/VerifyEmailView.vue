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
      <div v-else-if="verified" class="space-y-4">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto animate-bounce">
          <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
          </svg>
        </div>
        <h2 class="text-2xl font-bold text-green-600">Email Verified Successfully! ✓</h2>
        <p class="text-gray-600">Your email has been verified.</p>
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mt-4">
          <p class="text-sm text-blue-800 font-semibold">Next Step:</p>
          <p class="text-sm text-blue-700 mt-1">We're now creating your workspace and setting up your account...</p>
        </div>
        <p class="text-sm text-gray-500 flex items-center justify-center gap-2">
          <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Redirecting to registration page...
        </p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="space-y-4">
        <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto">
          <svg class="w-10 h-10 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </div>
        <h2 class="text-2xl font-bold text-red-600">Verification Failed</h2>
        <p class="text-gray-600">{{ errorMessage }}</p>
        <button 
          @click="goToLogin"
          class="mt-4 px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-colors"
        >
          Go to Login
        </button>
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
        
        // Show success message and redirect with token
        setTimeout(() => {
          router.push({
            name: 'register',
            query: { 
              verified: 'true', 
              token: token,
              message: 'Email verified! Your workspace is being created.' 
            }
          })
        }, 2000)
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
</script>
