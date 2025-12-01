<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-green-50 via-emerald-50 to-teal-50 py-6 px-4">
    <div class="bg-white p-6 rounded-2xl shadow-2xl w-full max-w-5xl my-auto">
      <!-- Header -->
      <div class="text-center mb-8">
        <div class="w-14 h-14 bg-gradient-to-br from-green-600 to-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-3 shadow-lg">
          <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
          </svg>
        </div>
        <h1 class="text-2xl font-bold bg-gradient-to-r from-green-600 via-emerald-600 to-teal-600 bg-clip-text text-transparent mb-2">
          Create Your Account
        </h1>
        <p class="text-gray-600">Get started with your 30-day free trial</p>
      </div>

      <!-- Success Message -->
      <div v-if="success" class="mb-6 p-4 bg-green-50 border border-green-200 rounded-lg animate-fade-in">
        <div class="flex items-center">
          <svg class="w-6 h-6 text-green-600 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p class="text-green-800 font-medium">{{ success }}</p>
            <p class="text-green-700 text-sm mt-1">Redirecting to login...</p>
          </div>
        </div>
      </div>

      <!-- Error Message -->
      <div v-if="error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg animate-fade-in">
        <div class="flex items-start">
          <svg class="w-6 h-6 text-red-600 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div class="flex-1">
            <p class="font-medium text-red-800">{{ error }}</p>
            <ul v-if="errors && Object.keys(errors).length" class="mt-2 text-sm text-red-700 space-y-1">
              <li v-for="(msgs, field) in errors" :key="field" class="flex items-start">
                <span class="mr-1">•</span>
                <span>{{ msgs[0] }}</span>
              </li>
            </ul>
          </div>
        </div>
      </div>

      <!-- Registration Form -->
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <!-- Company Name -->
        <div>
          <label class="block text-sm font-semibold text-gray-700 mb-2">Company Name *</label>
          <input 
            v-model="form.tenant_name" 
            type="text" 
            required
            class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
            placeholder="Enter your company name"
          />
          <p v-if="generatedSlug" class="text-xs text-gray-600 mt-1.5 flex items-center">
            <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            Your subdomain will be: <strong class="ml-1">{{ generatedSlug }}.{{ baseDomain }}</strong>
          </p>
        </div>

        <!-- Three Column Layout for Better Space Usage -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Your Full Name -->
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Your Full Name *</label>
            <input 
              v-model="form.admin_name" 
              type="text" 
              required
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
              placeholder="John Doe"
            />
          </div>

          <!-- Your Email -->
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Your Email *</label>
            <div class="relative">
              <input 
                v-model="form.admin_email" 
                type="email" 
                required
                @input="validateEmail"
                class="w-full px-4 py-2.5 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
                placeholder="john@company.com"
              />
              <div v-if="emailAvailable !== null" class="absolute right-2 top-1/2 -translate-y-1/2">
                <svg v-if="emailAvailable" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <p v-if="emailAvailable === false" class="text-xs text-red-600 mt-1">Email taken</p>
          </div>

          <!-- Username -->
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Username *</label>
            <div class="relative">
              <input 
                v-model="form.admin_username" 
                type="text" 
                required
                pattern="[a-z0-9_]+"
                @input="validateUsername"
                class="w-full px-4 py-2.5 pr-10 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
                placeholder="johndoe"
              />
              <div v-if="usernameAvailable !== null" class="absolute right-2 top-1/2 -translate-y-1/2">
                <svg v-if="usernameAvailable" class="w-5 h-5 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
            </div>
            <p v-if="usernameAvailable === false" class="text-xs text-red-600 mt-1">Username taken</p>
          </div>
        </div>

        <!-- Password Fields -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Password *</label>
            <input 
              v-model="form.admin_password" 
              type="password" 
              required
              minlength="8"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
              placeholder="••••••••"
            />
            <p class="text-xs text-gray-500 mt-1.5">Min 8 chars, uppercase, number & special char</p>
          </div>
          
          <div>
            <label class="block text-sm font-semibold text-gray-700 mb-2">Confirm Password *</label>
            <input 
              v-model="form.admin_password_confirmation" 
              type="password" 
              required
              minlength="8"
              class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
              placeholder="••••••••"
            />
          </div>
        </div>

        <!-- Optional Fields (Always Visible) -->
        <div class="border-t border-gray-200 pt-4">
          <h3 class="text-sm font-semibold text-gray-700 mb-4">Additional Information (Optional)</h3>
          
          <div class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Email</label>
                <input 
                  v-model="form.tenant_email" 
                  type="email" 
                  class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
                  placeholder="contact@company.com"
                />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                <input 
                  v-model="form.tenant_phone" 
                  type="tel" 
                  class="w-full px-4 py-2.5 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all" 
                  placeholder="+254712345678"
                />
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Company Address</label>
              <textarea 
                v-model="form.tenant_address" 
                rows="2"
                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-transparent transition-all resize-none" 
                placeholder="Your company address"
              ></textarea>
            </div>
          </div>
        </div>

        <!-- Terms & Conditions -->
        <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
          <div class="flex items-start">
            <input 
              v-model="form.accept_terms" 
              type="checkbox" 
              required
              class="mt-1 mr-3 w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
            />
            <label class="text-sm text-gray-700 cursor-pointer select-none">
              I agree to the <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Terms of Service</a> and 
              <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Privacy Policy</a>
            </label>
          </div>
        </div>

        <!-- Submit Button -->
        <button 
          type="submit" 
          :disabled="loading || !canSubmit"
          class="w-full bg-gradient-to-r from-green-600 to-emerald-600 text-white py-3 rounded-lg hover:from-green-700 hover:to-emerald-700 disabled:opacity-50 disabled:cursor-not-allowed font-semibold shadow-lg transition-all transform hover:scale-[1.01] active:scale-[0.99] text-base flex items-center justify-center"
        >
          <svg v-if="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          <span v-if="loading">Creating Your Account...</span>
          <span v-else class="flex items-center">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            Start Free Trial - No Credit Card Required
          </span>
        </button>
      </form>

      <!-- Login Link -->
      <div class="mt-6 text-center">
        <p class="text-sm text-gray-600">
          Already have an account? 
          <router-link 
            to="/login" 
            class="text-green-600 hover:text-green-800 font-semibold transition-colors ml-1"
          >
            Sign In
          </router-link>
        </p>
      </div>

      <!-- Footer -->
      <div class="mt-8 pt-6 border-t border-gray-200 text-center">
        <p class="text-xs text-gray-500">
          © {{ new Date().getFullYear() }} TraidNet Solutions. All rights reserved.
        </p>
        <div class="flex items-center justify-center mt-2 space-x-4 text-xs text-gray-400">
          <span class="flex items-center">
            <svg class="w-4 h-4 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            30-day free trial
          </span>
          <span class="flex items-center">
            <svg class="w-4 h-4 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            No credit card required
          </span>
          <span class="flex items-center">
            <svg class="w-4 h-4 mr-1 text-green-500" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
            </svg>
            Cancel anytime
          </span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const API_URL = import.meta.env.VITE_API_URL || 'http://localhost/api'

const form = ref({
  tenant_name: '',
  tenant_email: '',
  tenant_phone: '',
  tenant_address: '',
  admin_name: '',
  admin_username: '',
  admin_email: '',
  admin_phone: '',
  admin_password: '',
  admin_password_confirmation: '',
  accept_terms: false,
})

const loading = ref(false)
const error = ref('')
const errors = ref({})
const success = ref('')
const generatedSlug = ref('')
const baseDomain = ref(import.meta.env.VITE_BASE_DOMAIN || 'yourdomain.com')

const usernameAvailable = ref(null)
const emailAvailable = ref(null)

let usernameTimeout = null
let emailTimeout = null

const canSubmit = computed(() => {
  return form.value.accept_terms && 
         form.value.tenant_name.length >= 3 &&
         usernameAvailable.value !== false && 
         emailAvailable.value !== false
})

// Auto-generate slug from company name
const generateSlug = (name) => {
  return name
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
}

// Watch tenant name and generate slug
watch(() => form.value.tenant_name, (newName) => {
  if (newName) {
    generatedSlug.value = generateSlug(newName)
  } else {
    generatedSlug.value = ''
  }
})

const validateUsername = () => {
  clearTimeout(usernameTimeout)
  usernameAvailable.value = null
  
  if (form.value.admin_username.length < 3) return
  
  usernameTimeout = setTimeout(async () => {
    try {
      const response = await axios.post(`${API_URL}/register/check-username`, {
        username: form.value.admin_username
      })
      usernameAvailable.value = response.data.available
    } catch (err) {
      console.error('Username check error:', err)
    }
  }, 500)
}

const validateEmail = () => {
  clearTimeout(emailTimeout)
  emailAvailable.value = null
  
  if (!form.value.admin_email.includes('@')) return
  
  emailTimeout = setTimeout(async () => {
    try {
      const response = await axios.post(`${API_URL}/register/check-email`, {
        email: form.value.admin_email
      })
      emailAvailable.value = response.data.available
    } catch (err) {
      console.error('Email check error:', err)
    }
  }, 500)
}

const handleSubmit = async () => {
  error.value = ''
  errors.value = {}
  success.value = ''
  loading.value = true
  
  try {
    const response = await axios.post(`${API_URL}/register/tenant`, form.value)
    
    if (response.data.success) {
      success.value = '✅ Registration successful! Please check your email to verify your account.'
      
      // Redirect to login after 3 seconds
      setTimeout(() => {
        router.push({
          name: 'login',
          query: { registered: 'true', email: form.value.admin_email }
        })
      }, 3000)
    }
  } catch (err) {
    console.error('Registration error:', err)
    error.value = err.response?.data?.message || 'Registration failed. Please try again.'
    errors.value = err.response?.data?.errors || {}
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
@keyframes fade-in {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-fade-in {
  animation: fade-in 0.3s ease-out;
}
</style>
