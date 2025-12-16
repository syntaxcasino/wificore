<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-gray-50 via-white to-gray-100 p-4 py-8">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-5xl border border-gray-200">
      <div class="p-8 md:p-12">
        <!-- Header -->
        <div class="text-center mb-8">
          <div class="w-16 h-16 bg-gradient-to-br from-blue-600 via-indigo-600 to-cyan-600 rounded-3xl flex items-center justify-center mx-auto mb-4 shadow-xl">
            <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
          </div>
          <h1 class="text-3xl font-bold bg-gradient-to-r from-blue-700 via-indigo-700 to-cyan-600 bg-clip-text text-transparent mb-2">
            Create Company Account
          </h1>
          <p class="text-gray-600">Register your company to get started</p>
        </div>

        <!-- Multi-Step Progress Indicator -->
        <div class="mb-8">
          <div class="flex items-center justify-between relative">
            <!-- Step 1 -->
            <div class="flex flex-col items-center flex-1 relative z-10">
              <div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg transition-all duration-300 bg-gradient-to-br from-blue-600 to-indigo-600 text-white shadow-lg">
                <span>1</span>
              </div>
              <p class="text-xs font-medium mt-2 text-center text-blue-700">Input & Submission</p>
            </div>
            
            <!-- Connector 1-2 -->
            <div class="flex-1 h-1 mx-2 relative" style="top: -20px;">
              <div class="h-full bg-gray-200 rounded"></div>
            </div>
            
            <!-- Step 2 -->
            <div class="flex flex-col items-center flex-1 relative z-10">
              <div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg transition-all duration-300 bg-gray-200 text-gray-500">
                <span>2</span>
              </div>
              <p class="text-xs font-medium mt-2 text-center text-gray-500">Email Verification</p>
            </div>
            
            <!-- Connector 2-3 -->
            <div class="flex-1 h-1 mx-2 relative" style="top: -20px;">
              <div class="h-full bg-gray-200 rounded"></div>
            </div>
            
            <!-- Step 3 -->
            <div class="flex flex-col items-center flex-1 relative z-10">
              <div class="w-12 h-12 rounded-full flex items-center justify-center font-bold text-lg transition-all duration-300 bg-gray-200 text-gray-500">
                <span>3</span>
              </div>
              <p class="text-xs font-medium mt-2 text-center text-gray-500">Sending Credentials</p>
            </div>
          </div>
        </div>

        <!-- Error Message -->
        <div v-if="error" class="mb-6 p-4 bg-red-50 border-l-4 border-red-500 rounded-r-lg">
          <div class="flex items-start">
            <svg class="w-5 h-5 text-red-600 mr-3 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div class="flex-1">
              <p class="font-medium text-red-800">{{ error }}</p>
              <ul v-if="errors && Object.keys(errors).length" class="mt-2 text-sm text-red-700 space-y-1">
                <li v-for="(msgs, field) in errors" :key="field">{{ msgs[0] }}</li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Registration Form -->
        <form @submit.prevent="handleSubmit" class="space-y-6">
          <!-- Your Details Section -->
          <div class="space-y-4">
            <h3 class="text-lg font-semibold text-gray-900">Your Details</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Your Full Name *</label>
                <input v-model="form.admin_name" type="text" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="John Doe" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Your Email *</label>
                <input v-model="form.admin_email" type="email" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="john@company.com" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Username *</label>
                <input v-model="form.admin_username" type="text" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="kja2aro" />
                <p class="text-xs text-gray-500 mt-1">Min 6 chars (lowercase, lowercase number, special char)</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Password *</label>
                <input v-model="form.admin_password" type="password" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="********" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Confirm Password *</label>
                <input v-model="form.admin_password_confirmation" type="password" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="********" />
              </div>
            </div>
          </div>

          <!-- Company Details Section -->
          <div class="space-y-4">
            <h3 class="text-lg font-semibold text-gray-900">Company Details</h3>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Company Name *</label>
              <input v-model="form.tenant_name" type="text" required class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Your Company Name" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Email (optional)</label>
                <input v-model="form.tenant_email" type="email" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="contact@company.com" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Phone (optional)</label>
                <input v-model="form.tenant_phone" type="tel" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="+254712345678" />
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Company Address (optional)</label>
                <input v-model="form.tenant_address" type="text" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-colors" placeholder="Your company address" />
              </div>
            </div>
          </div>

          <!-- Terms -->
          <div class="flex items-start">
            <input v-model="form.accept_terms" type="checkbox" required class="mt-1 mr-3 w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer" />
            <label class="text-sm text-gray-700 cursor-pointer select-none">
              I agree to the <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Terms of Service</a> and 
              <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Privacy Policy</a>
            </label>
          </div>

          <!-- Submit Button -->
          <button type="submit" :disabled="loading || !form.accept_terms" class="w-full bg-gradient-to-r from-blue-600 via-indigo-600 to-cyan-600 text-white py-3 rounded-lg hover:from-blue-700 hover:via-indigo-700 hover:to-cyan-700 disabled:opacity-50 disabled:cursor-not-allowed font-semibold shadow-lg transition-all transform hover:scale-[1.01] active:scale-[0.99] flex items-center justify-center">
            <svg v-if="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span v-if="loading">Processing...</span>
            <span v-else>Start Free Trial - No Credit Card Required</span>
          </button>
        </form>

        <!-- Login Link -->
        <div class="mt-6 text-center">
          <p class="text-sm text-gray-600">
            Already have an account? 
            <router-link to="/login" class="text-blue-600 hover:text-blue-800 font-semibold transition-colors ml-1">Sign In</router-link>
          </p>
        </div>

        <!-- Footer -->
        <div class="mt-6 pt-4 border-t border-gray-200 text-center">
          <p class="text-xs text-gray-500">© {{ new Date().getFullYear() }} WifiCore by TraidSolutions. All rights reserved.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()

const form = ref({
  admin_name: '',
  admin_email: '',
  admin_username: '',
  admin_password: '',
  admin_password_confirmation: '',
  tenant_name: '',
  tenant_email: '',
  tenant_phone: '',
  tenant_address: '',
  accept_terms: false
})

const loading = ref(false)
const error = ref('')
const errors = ref({})

const handleSubmit = async () => {
  error.value = ''
  errors.value = {}
  loading.value = true
  
  try {
    const response = await axios.post('/register/tenant', {
      admin_name: form.value.admin_name,
      admin_email: form.value.admin_email,
      admin_username: form.value.admin_username,
      admin_password: form.value.admin_password,
      admin_password_confirmation: form.value.admin_password_confirmation,
      tenant_name: form.value.tenant_name,
      tenant_email: form.value.tenant_email,
      tenant_phone: form.value.tenant_phone,
      tenant_address: form.value.tenant_address,
      accept_terms: form.value.accept_terms
    })
    
    if (response.data.success) {
      router.push({
        name: 'login',
        query: { registered: 'true' }
      })
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
</style>
