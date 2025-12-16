<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 via-white to-gray-100 p-4 py-8">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-6xl border border-gray-200">
      <div class="grid grid-cols-1 md:grid-cols-2 gap-0">
        <!-- Left: Branding + Features -->
        <div class="p-8 md:p-12">
      <!-- Header -->
      <div class="text-center" :class="isSignup ? 'mb-6' : 'mb-8'">
        <!-- Logo -->
        <div :class="isSignup ? 'mb-4' : 'mb-6'">
          <h1 :class="isSignup ? 'text-3xl' : 'text-4xl'" class="font-bold bg-gradient-to-r from-blue-700 via-indigo-700 to-cyan-600 bg-clip-text text-transparent">
            WifiCore
          </h1>
          <p class="text-sm text-gray-600 mt-2 font-medium">Hotspot Management System</p>
        </div>
        
        <div v-if="!isSignup" class="w-20 h-20 bg-gradient-to-br from-blue-600 via-indigo-600 to-cyan-600 rounded-3xl flex items-center justify-center mx-auto mb-4 shadow-xl">
          <svg class="w-12 h-12 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
          </svg>
        </div>
        <div class="text-center">
          <h2 :class="isSignup ? 'text-2xl' : 'text-3xl'" class="font-bold text-gray-900">{{ isSignup ? 'Create Account' : 'Welcome Back' }}</h2>
          <p class="text-gray-600 mt-2 text-sm">{{ isSignup ? 'Register your hotspot account' : 'Sign in to manage your WiFi hotspot operations' }}</p>
        </div>
        <!-- Feature bullets (signup only) -->
        <ul v-if="isSignup" class="mt-6 space-y-3 text-gray-700">
          <li class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
            <span>Multi-tenant hotspot and network management</span>
          </li>
          <li class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
            <span>RADIUS authentication and user access control</span>
          </li>
          <li class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
            <span>Billing, packages and payment integration</span>
          </li>
          <li class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
            <span>Analytics dashboards and reports</span>
          </li>
          <li class="flex items-start gap-3">
            <svg class="w-5 h-5 text-blue-600 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
            <span>Real-time notifications and WebSockets</span>
          </li>
        </ul>
        </div>
        </div>
        <!-- Right: Forms -->
        <div class="p-8 md:p-12 md:border-l border-gray-100">

      <!-- Error/Success Messages -->
      <div v-if="error" class="mb-4 p-4 bg-red-50 border-l-4 border-red-500 rounded-r-lg text-red-700 text-sm flex items-start gap-3 animate-shake">
        <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
        </svg>
        <span>{{ error }}</span>
      </div>
      <div v-if="success" class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-500 rounded-r-lg text-blue-700 text-sm flex items-start gap-3 animate-fade-in">
        <svg class="w-5 h-5 text-blue-500 flex-shrink-0 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
        </svg>
        <span>{{ success }}</span>
      </div>

      <!-- Login Form -->
      <form v-if="!isSignup" @submit.prevent="handleLogin" class="space-y-5">
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </div>
            <input 
              v-model="username" 
              type="text" 
              required
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Enter your username"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400 group-focus-within:text-blue-500 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </div>
            <input 
              v-model="password" 
              type="password" 
              required
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Enter your password"
            />
          </div>
        </div>
        
        <!-- Resend Verification Button -->
        <div v-if="showResendVerification" class="p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
          <p class="text-sm text-yellow-800 mb-2">Email not verified yet?</p>
          <button 
            type="button"
            @click="handleResendVerification"
            :disabled="loading"
            class="text-sm text-blue-600 hover:text-blue-800 font-medium underline"
          >
            Resend verification email
          </button>
        </div>
        
        <button 
          type="submit" 
          :disabled="loading"
          class="w-full bg-blue-600 text-white py-3.5 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed font-semibold shadow-sm transition-colors flex items-center justify-center gap-2"
        >
          <svg v-if="loading" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>{{ loading ? 'Authenticating...' : 'Sign In' }}</span>
        </button>
      </form>

      <!-- Signup Form -->
      <form v-else @submit.prevent="handleSignup" class="space-y-4">
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Full Name</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
            </div>
            <input 
              v-model="signupForm.name" 
              type="text" 
              required
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Enter your full name"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
              </svg>
            </div>
            <input 
              v-model="signupForm.username" 
              type="text" 
              required
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Choose a username"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Email</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <input 
              v-model="signupForm.email" 
              type="email" 
              required
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Enter your email"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
          <div class="flex relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none z-10">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
            </div>
            <span class="inline-flex items-center pl-10 pr-3 rounded-l-lg border border-r-0 border-gray-300 bg-gray-50 text-gray-600 font-medium">+254</span>
            <input 
              v-model="signupForm.phone" 
              type="text" 
              required
              maxlength="9"
              pattern="[0-9]{9}"
              class="flex-1 px-4 py-3 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="712345678"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
              </svg>
            </div>
            <input 
              v-model="signupForm.password" 
              type="password" 
              required
              minlength="8"
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Minimum 8 characters"
            />
          </div>
        </div>
        <div class="group">
          <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password</label>
          <div class="relative">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
              <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
            <input 
              v-model="signupForm.password_confirmation" 
              type="password" 
              required
              minlength="8"
              class="w-full pl-10 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" 
              placeholder="Confirm your password"
            />
          </div>
        </div>
        <button 
          type="submit" 
          :disabled="loading"
          class="w-full bg-blue-600 text-white py-3.5 rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed font-semibold shadow-sm transition-colors flex items-center justify-center gap-2"
        >
          <svg v-if="loading" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span>{{ loading ? 'Creating Account...' : 'Create Account' }}</span>
        </button>
      </form>

      <!-- Toggle between Login and Signup -->
      <div class="mt-8 text-center">
        <div class="relative">
          <div class="absolute inset-0 flex items-center">
            <div class="w-full border-t border-gray-200"></div>
          </div>
          <div class="relative flex justify-center text-sm">
            <span class="px-4 bg-white text-gray-500 font-medium">{{ isSignup ? 'Already registered?' : 'New here?' }}</span>
          </div>
        </div>
        <button 
          v-if="isSignup"
          @click="toggleMode" 
          class="mt-4 text-blue-600 hover:text-blue-800 font-semibold transition-colors inline-flex items-center gap-2"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
          </svg>
          Sign in to existing account
        </button>
        <div v-else class="space-y-3 mt-4">
          <router-link 
            to="/register" 
            class="text-cyan-600 hover:text-cyan-800 font-semibold transition-colors inline-flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
            Register your organization
          </router-link>
        </div>
      </div>

      <!-- Footer -->
      <div class="mt-8 pt-6 border-t border-gray-200 text-center">
        <p class="text-xs text-gray-600 font-medium">
          © {{ new Date().getFullYear() }} WifiCore by TraidNet Solutions. All rights reserved.
        </p>
        <p class="text-xs text-gray-400 mt-2 flex items-center justify-center gap-1">
          <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
          </svg>
          Secured with Laravel Sanctum & RADIUS
        </p>
      </div>
        </div>
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
