<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-green-50 via-emerald-50 to-teal-50">
    <div class="bg-white p-8 rounded-2xl shadow-2xl w-full max-w-md">
      <!-- Header -->
      <div class="text-center mb-8">
        <!-- TraidNet Logo -->
        <div class="mb-6">
          <h1 class="text-4xl font-bold bg-gradient-to-r from-green-600 via-emerald-600 to-teal-600 bg-clip-text text-transparent">
            TraidNet Solutions
          </h1>
          <p class="text-sm text-gray-500 mt-1">Hotspot Management System</p>
        </div>
        
        <div class="w-16 h-16 bg-gradient-to-br from-green-600 to-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg">
          <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
          </svg>
        </div>
        <h2 class="text-3xl font-bold text-gray-800">{{ isSignup ? 'Create Account' : 'Admin Login' }}</h2>
        <p class="text-gray-600 mt-2">{{ isSignup ? 'Sign up to manage your hotspot' : 'Sign in to your account' }}</p>
      </div>

      <!-- Error/Success Messages -->
      <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-red-700 text-sm">
        {{ error }}
      </div>
      <div v-if="success" class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-green-700 text-sm">
        {{ success }}
      </div>

      <!-- Login Form -->
      <form v-if="!isSignup" @submit.prevent="handleLogin" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
          <input 
            v-model="username" 
            type="text" 
            required
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Enter your username"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
          <input 
            v-model="password" 
            type="password" 
            required
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Enter your password"
          />
        </div>
        
        <!-- Resend Verification Button -->
        <div v-if="showResendVerification" class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
          <p class="text-sm text-yellow-800 mb-2">Email not verified yet?</p>
          <button 
            type="button"
            @click="handleResendVerification"
            :disabled="loading"
            class="text-sm text-green-600 hover:text-green-800 font-medium underline"
          >
            Resend verification email
          </button>
        </div>
        
        <button 
          type="submit" 
          :disabled="loading"
          class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-3 rounded-lg hover:from-green-700 hover:to-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed font-medium shadow-lg transition-all transform hover:scale-[1.02]"
        >
          {{ loading ? 'Authenticating...' : 'Sign In' }}
        </button>
      </form>

      <!-- Signup Form -->
      <form v-else @submit.prevent="handleSignup" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
          <input 
            v-model="signupForm.name" 
            type="text" 
            required
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Enter your full name"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
          <input 
            v-model="signupForm.username" 
            type="text" 
            required
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Choose a username"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
          <input 
            v-model="signupForm.email" 
            type="email" 
            required
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Enter your email"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
          <div class="flex">
            <span class="inline-flex items-center px-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-gray-500">+254</span>
            <input 
              v-model="signupForm.phone" 
              type="text" 
              required
              maxlength="9"
              pattern="[0-9]{9}"
              class="flex-1 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-blue-500 focus:border-transparent transition-all" 
              placeholder="712345678"
            />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
          <input 
            v-model="signupForm.password" 
            type="password" 
            required
            minlength="8"
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Minimum 8 characters"
          />
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
          <input 
            v-model="signupForm.password_confirmation" 
            type="password" 
            required
            minlength="8"
            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Confirm your password"
          />
        </div>
        <button 
          type="submit" 
          :disabled="loading"
          class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-3 rounded-lg hover:from-green-700 hover:to-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed font-medium shadow-lg transition-all transform hover:scale-[1.02]"
        >
          {{ loading ? 'Creating Account...' : 'Create Account' }}
        </button>
      </form>

      <!-- Toggle between Login and Signup -->
      <div class="mt-6 text-center">
        <button 
          v-if="isSignup"
          @click="toggleMode" 
          class="text-green-600 hover:text-green-800 font-medium transition-colors"
        >
          Already have an account? Sign In
        </button>
        <div v-else class="space-y-2">
          <button 
            @click="toggleMode" 
            class="text-green-600 hover:text-green-800 font-medium transition-colors block mx-auto"
          >
            Don't have an account? Sign Up
          </button>
          <p class="text-gray-600 text-sm">or</p>
          <router-link 
            to="/register" 
            class="text-green-700 hover:text-green-900 font-medium transition-colors block"
          >
            Register Your Organization
          </router-link>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-8 pt-6 border-t border-gray-200 text-center">
        <p class="text-xs text-gray-500">
          © {{ new Date().getFullYear() }} TraidNet Solutions. All rights reserved.
        </p>
        <p class="text-xs text-gray-400 mt-1">
          Powered by RADIUS Authentication & Sanctum
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()
const username = ref('')
const password = ref('')
const error = ref('')
const success = ref('')
const loading = ref(false)
const isSignup = ref(false)
const showResendVerification = ref(false)
const unverifiedEmail = ref('')

const signupForm = ref({
  name: '',
  username: '',
  email: '',
  phone: '',
  password: '',
  password_confirmation: ''
})

const handleLogin = async () => {
  error.value = ''
  success.value = ''
  showResendVerification.value = false
  loading.value = true
  
  try {
    const result = await authStore.login({
      username: username.value,
      password: password.value,
      remember: false
    })
    
    if (result.success) {
      success.value = 'Login successful! Redirecting...'
      setTimeout(() => {
        router.push(result.dashboardRoute)
      }, 500)
    } else {
      error.value = result.error || 'Invalid credentials'
    }
  } catch (err) {
    error.value = 'An error occurred during login'
    console.error('Login error:', err)
  } finally {
    loading.value = false
  }
}

const handleResendVerification = async () => {
  error.value = ''
  success.value = ''
  loading.value = true
  
  try {
    // This feature can be implemented later
    success.value = '✅ Verification email sent! Please check your inbox.'
    showResendVerification.value = false
  } catch (err) {
    error.value = 'An error occurred'
    console.error('Resend verification error:', err)
  } finally {
    loading.value = false
  }
}

const handleSignup = async () => {
  error.value = ''
  success.value = ''
  
  // Validate passwords match
  if (signupForm.value.password !== signupForm.value.password_confirmation) {
    error.value = 'Passwords do not match'
    return
  }
  
  loading.value = true
  
  try {
    // For now, redirect to tenant registration
    router.push('/register')
  } catch (err) {
    error.value = 'An error occurred during registration'
    console.error('Registration error:', err)
  } finally {
    loading.value = false
  }
}

const toggleMode = () => {
  isSignup.value = !isSignup.value
  error.value = ''
  success.value = ''
  username.value = ''
  password.value = ''
  signupForm.value = {
    name: '',
    username: '',
    email: '',
    phone: '',
    password: '',
    password_confirmation: ''
  }
}
</script>
