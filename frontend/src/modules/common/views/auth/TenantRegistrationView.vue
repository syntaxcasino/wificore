<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-100 via-indigo-100 to-cyan-100 py-12 px-4">
    <div class="max-w-4xl w-full bg-white rounded-2xl shadow-2xl overflow-hidden">
      <!-- Header -->
      <div class="bg-gradient-to-r from-blue-600 via-indigo-600 to-cyan-600 px-8 py-6 text-white">
        <div class="flex items-center justify-center mb-4">
          <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
          </div>
        </div>
        <h1 class="text-3xl font-bold text-center">WifiCore Registration</h1>
        <p class="text-center text-blue-100 mt-2">Create your hotspot management account</p>
      </div>

      <!-- Step Indicator -->
      <div class="px-8 py-6 bg-gray-50 border-b border-gray-200">
        <div class="flex items-center justify-between max-w-2xl mx-auto">
          <!-- Step 1 -->
          <div class="flex items-center flex-1">
            <div class="flex flex-col items-center flex-1">
              <div 
                class="w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all"
                :class="currentStep >= 1 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'"
              >
                <svg v-if="currentStep > 1" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <span v-else>1</span>
              </div>
              <span class="text-xs mt-2 font-medium text-center" :class="currentStep >= 1 ? 'text-blue-600' : 'text-gray-500'">
                Input & Submission
              </span>
            </div>
            <div class="w-full h-1 mx-2" :class="currentStep > 1 ? 'bg-blue-600' : 'bg-gray-300'"></div>
          </div>

          <!-- Step 2 -->
          <div class="flex items-center flex-1">
            <div class="flex flex-col items-center flex-1">
              <div 
                class="w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all"
                :class="currentStep >= 2 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'"
              >
                <svg v-if="currentStep > 2" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <span v-else>2</span>
              </div>
              <span class="text-xs mt-2 font-medium text-center" :class="currentStep >= 2 ? 'text-blue-600' : 'text-gray-500'">
                Email Verification
              </span>
            </div>
            <div class="w-full h-1 mx-2" :class="currentStep > 2 ? 'bg-blue-600' : 'bg-gray-300'"></div>
          </div>

          <!-- Step 3 -->
          <div class="flex flex-col items-center flex-1">
            <div 
              class="w-10 h-10 rounded-full flex items-center justify-center font-bold transition-all"
              :class="currentStep >= 3 ? 'bg-blue-600 text-white' : 'bg-gray-300 text-gray-600'"
            >
              <svg v-if="currentStep > 3" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
              </svg>
              <span v-else>3</span>
            </div>
            <span class="text-xs mt-2 font-medium text-center" :class="currentStep >= 3 ? 'text-blue-600' : 'text-gray-500'">
              Sending Credentials
            </span>
          </div>
        </div>
      </div>

      <!-- Content Area -->
      <div class="px-8 py-8">
        <!-- Step 1: Registration Form -->
        <div v-if="currentStep === 1" class="max-w-2xl mx-auto">
          <h2 class="text-2xl font-bold text-gray-900 mb-6">Company Details</h2>
          
          <form @submit.prevent="handleSubmit" class="space-y-5">
            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Company Name</label>
              <input 
                v-model="form.company_name" 
                type="text" 
                required 
                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                placeholder="Enter your company name"
              />
              <p v-if="generatedSlug" class="text-sm text-gray-600 mt-2 flex items-center">
                <svg class="w-4 h-4 mr-1 text-blue-500" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                Your subdomain: <strong class="ml-1">{{ generatedSlug }}.wificore.traidsolutions.com</strong>
              </p>
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Company Email</label>
              <input 
                v-model="form.company_email" 
                type="email" 
                required 
                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                placeholder="contact@company.com"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Company Phone</label>
              <input 
                v-model="form.company_phone" 
                type="tel" 
                required 
                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                placeholder="+254712345678"
              />
            </div>

            <div>
              <label class="block text-sm font-semibold text-gray-700 mb-2">Company Address</label>
              <textarea 
                v-model="form.company_address" 
                required 
                rows="3"
                class="w-full px-4 py-3 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all"
                placeholder="Enter your company address"
              ></textarea>
            </div>

            <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-4">
              <div class="flex items-start">
                <input 
                  v-model="form.accept_terms" 
                  type="checkbox" 
                  required
                  class="mt-1 mr-3 w-5 h-5 text-blue-600 border-gray-300 rounded focus:ring-blue-500"
                />
                <label class="text-sm text-gray-700">
                  I agree to the <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Terms of Service</a> and 
                  <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Privacy Policy</a>
                </label>
              </div>
            </div>

            <div v-if="error" class="bg-red-50 border border-red-200 rounded-lg p-4">
              <p class="text-red-800 text-sm">{{ error }}</p>
            </div>

            <button 
              type="submit" 
              :disabled="submitting || !form.accept_terms"
              class="w-full bg-gradient-to-r from-blue-600 via-indigo-600 to-cyan-600 text-white py-4 rounded-xl font-semibold text-lg hover:from-blue-700 hover:via-indigo-700 hover:to-cyan-700 disabled:opacity-50 disabled:cursor-not-allowed transition-all shadow-lg hover:shadow-xl"
            >
              <span v-if="submitting" class="flex items-center justify-center">
                <svg class="animate-spin h-5 w-5 mr-3" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Processing...
              </span>
              <span v-else>Register Company</span>
            </button>
          </form>

          <div class="mt-6 text-center">
            <p class="text-sm text-gray-600">
              Already have an account? 
              <router-link to="/login" class="text-blue-600 hover:text-blue-800 font-semibold">Sign In</router-link>
            </p>
          </div>
        </div>

        <!-- Step 2: Email Verification -->
        <div v-if="currentStep === 2" class="max-w-2xl mx-auto text-center">
          <div class="mb-6">
            <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg class="w-10 h-10 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Check Your Email</h2>
            <p class="text-gray-600 mb-2">
              We've sent a verification link to:
            </p>
            <p class="text-lg font-semibold text-blue-600 mb-4">{{ registrationData?.email }}</p>
            <p class="text-gray-600">
              Please click the link in the email to verify your account.
            </p>
          </div>

          <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
            <div class="flex items-center justify-center mb-3">
              <svg class="animate-spin h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </div>
            <p class="text-blue-800 font-medium">Waiting for email verification...</p>
            <p class="text-blue-600 text-sm mt-2">This page will automatically update once you verify your email</p>
          </div>

          <div class="mt-6 text-sm text-gray-500">
            <p>Didn't receive the email? Check your spam folder or contact support.</p>
          </div>
        </div>

        <!-- Step 3: Creating Account -->
        <div v-if="currentStep === 3" class="max-w-2xl mx-auto text-center">
          <div class="mb-6">
            <div class="w-20 h-20 bg-indigo-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </div>
            <h2 class="text-2xl font-bold text-gray-900 mb-4">Creating Your Account</h2>
            <p class="text-gray-600">
              Please wait while we set up your workspace and generate your credentials...
            </p>
          </div>

          <div class="bg-indigo-50 border border-indigo-200 rounded-lg p-6">
            <div class="flex items-center justify-center mb-3">
              <svg class="animate-spin h-8 w-8 text-indigo-600" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
            </div>
            <p class="text-indigo-800 font-medium">Setting up your database and sending credentials...</p>
            <p class="text-indigo-600 text-sm mt-2">This may take a few moments</p>
          </div>
        </div>

        <!-- Step 4: Complete -->
        <div v-if="currentStep === 4" class="max-w-2xl mx-auto text-center">
          <div class="mb-6">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <h2 class="text-3xl font-bold text-gray-900 mb-4">✅ Registration Complete!</h2>
            <p class="text-lg text-gray-600 mb-6">
              Your account has been created successfully!
            </p>
          </div>

          <div class="bg-green-50 border-2 border-green-200 rounded-lg p-6 mb-6">
            <h3 class="font-semibold text-green-900 mb-3">What's Next?</h3>
            <ul class="text-left text-green-800 space-y-2">
              <li class="flex items-start">
                <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span>Check your email for login credentials</span>
              </li>
              <li class="flex items-start">
                <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span>Your username is based on your company name (without spaces)</span>
              </li>
              <li class="flex items-start">
                <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span>A secure password has been generated for you</span>
              </li>
              <li class="flex items-start">
                <svg class="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                </svg>
                <span>Please change your password after first login</span>
              </li>
            </ul>
          </div>

          <button 
            @click="goToLogin"
            class="w-full bg-gradient-to-r from-blue-600 via-indigo-600 to-cyan-600 text-white py-4 rounded-xl font-semibold text-lg hover:from-blue-700 hover:via-indigo-700 hover:to-cyan-700 transition-all shadow-lg hover:shadow-xl"
          >
            Go to Login
          </button>

          <p class="mt-4 text-sm text-gray-500">
            Your subdomain: <strong class="text-gray-700">{{ registrationData?.subdomain }}</strong>
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const notificationStore = useNotificationStore()

const currentStep = ref(1)
const submitting = ref(false)
const error = ref('')
const registrationData = ref(null)
const pollInterval = ref(null)

const form = ref({
  company_name: '',
  company_email: '',
  company_phone: '',
  company_address: '',
  accept_terms: false
})

const generatedSlug = computed(() => {
  if (!form.value.company_name) return ''
  return form.value.company_name
    .toLowerCase()
    .replace(/[^a-z0-9\s-]/g, '')
    .replace(/\s+/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-+|-+$/g, '')
})

const handleSubmit = async () => {
  error.value = ''
  submitting.value = true
  
  try {
    const response = await axios.post('/register/tenant', form.value)
    
    if (response.data.success) {
      registrationData.value = response.data.data
      currentStep.value = 2
      
      notificationStore.success(
        'Registration Submitted!',
        'Please check your email to verify your account.',
        5000
      )
      
      // Start polling for verification status
      startPollingVerification()
    }
  } catch (err) {
    console.error('Registration error:', err)
    error.value = err.response?.data?.message || 'Registration failed. Please try again.'
    
    notificationStore.error(
      'Registration Failed',
      error.value,
      7000
    )
  } finally {
    submitting.value = false
  }
}

const startPollingVerification = () => {
  // Poll every 3 seconds
  pollInterval.value = setInterval(async () => {
    try {
      const response = await axios.get(`/register/status/${registrationData.value.tenant_id}`)
      const status = response.data.data
      
      // Move to step 3 when email is verified
      if (status.email_verified && currentStep.value === 2) {
        currentStep.value = 3
        notificationStore.success(
          'Email Verified!',
          'Creating your account...',
          3000
        )
      }
      
      // Move to step 4 when credentials are sent
      if (status.credentials_sent && currentStep.value === 3) {
        currentStep.value = 4
        clearInterval(pollInterval.value)
        notificationStore.success(
          'Account Created!',
          'Check your email for login credentials.',
          5000
        )
      }
    } catch (err) {
      console.error('Status check failed:', err)
    }
  }, 3000)
}

const goToLogin = () => {
  router.push('/login')
}

// Cleanup polling on component unmount
onUnmounted(() => {
  if (pollInterval.value) {
    clearInterval(pollInterval.value)
  }
})
</script>

<style scoped>
@keyframes spin {
  to { transform: rotate(360deg); }
}

.animate-spin {
  animation: spin 1s linear infinite;
}
</style>
