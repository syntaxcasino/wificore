<template>
  <!-- Loading Progress Overlay -->
  <LoadingProgress
    :show="loading"
    :title="loadingState.title"
    :message="loadingState.message"
    :progress="loadingState.progress"
    :statusMessage="loadingState.statusMessage"
  />

  <div class="relative overflow-hidden min-h-screen flex items-center justify-center bg-gradient-to-br from-blue-100 via-indigo-100 to-cyan-100 py-6 px-4">
    <!-- Animated Background Elements -->
    <div class="absolute inset-0 pointer-events-none">
      <div class="absolute inset-0 reg-bg-grid reg-bg-anim"></div>
      <div class="reg-bg-card reg-bg-anim" style="top: 9%; left: 6%; width: 260px;">
        <div class="reg-bg-card-title">
          <span class="reg-bg-pill"></span>
          <span class="reg-bg-pill"></span>
          <span class="reg-bg-pill"></span>
        </div>
        <div class="reg-bg-line"></div>
        <div class="reg-bg-line reg-bg-line-sm"></div>
        <div class="reg-bg-line reg-bg-line-md"></div>
        <div class="reg-bg-spark"></div>
      </div>

      <div class="reg-bg-card reg-bg-anim" style="top: 64%; left: 10%; width: 220px;">
        <div class="reg-bg-card-title">
          <span class="reg-bg-dot"></span>
          <span class="reg-bg-line reg-bg-line-sm" style="margin: 0;"></span>
        </div>
        <div class="reg-bg-metric">
          <div class="reg-bg-bar reg-bg-anim"></div>
          <div class="reg-bg-bar reg-bg-bar-2 reg-bg-anim"></div>
          <div class="reg-bg-bar reg-bg-bar-3 reg-bg-anim"></div>
          <div class="reg-bg-bar reg-bg-bar-4 reg-bg-anim"></div>
        </div>
      </div>

      <div class="reg-bg-card reg-bg-anim" style="top: 18%; right: 7%; width: 280px;">
        <div class="reg-bg-card-title">
          <span class="reg-bg-dot"></span>
          <span class="reg-bg-pill" style="width: 64px;"></span>
          <span class="reg-bg-pill" style="width: 44px;"></span>
        </div>
        <div class="reg-bg-timeline">
          <span class="reg-bg-tick"></span>
          <span class="reg-bg-tick"></span>
          <span class="reg-bg-tick"></span>
          <span class="reg-bg-tick"></span>
          <span class="reg-bg-tick"></span>
        </div>
        <div class="reg-bg-progress">
          <span class="reg-bg-progress-fill reg-bg-anim"></span>
        </div>
      </div>

      <div class="reg-bg-orbit reg-bg-anim" style="top: 38%; right: 14%;"></div>
      <div class="reg-bg-orbit reg-bg-orbit-2 reg-bg-anim" style="top: 76%; right: 22%;"></div>
      <div class="reg-bg-orbit reg-bg-orbit-3 reg-bg-anim" style="top: 22%; left: 38%;"></div>
    </div>

    <div class="relative z-10 bg-white p-0 rounded-2xl shadow-2xl w-full my-auto overflow-hidden" style="max-width: 90rem;">
      <div class="grid grid-cols-1 md:grid-cols-12">
        <!-- Left: Branding + Features -->
        <div class="relative p-8 md:p-10 bg-gradient-to-br from-blue-600 via-indigo-600 to-cyan-600 md:col-span-4 flex">
          <div class="absolute inset-0 bg-[url('data:image/svg+xml,%3Csvg width=\'200\' height=\'200\' viewBox=\'0 0 200 200\' xmlns=\'http://www.w3.org/2000/svg\'%3E%3Ccircle cx=\'100\' cy=\'100\' r=\'90\' fill=\'none\' stroke=\'rgba(255,255,255,0.05)\' stroke-width=\'8\'/%3E%3C/svg%3E')] opacity-30"></div>
          <div class="relative text-white w-full flex flex-col items-center justify-center">
            <div class="flex flex-col items-center text-center">
              <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center mb-4 shadow-lg">
                <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                </svg>
              </div>
              <h1 class="text-2xl font-bold tracking-tight">WifiCore</h1>
              <p class="text-blue-100 mt-1">Hotspot Management System</p>
            </div>

            <div class="mt-8 w-full max-w-md text-left">
              <h3 class="text-white/90 font-semibold mb-3">Why you'll love it</h3>
              <ul class="space-y-3 text-blue-50/95">
                <li class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-white/90 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
                  <span>Multi-tenant hotspot and network management</span>
                </li>
                <li class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-white/90 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
                  <span>RADIUS authentication and user access control</span>
                </li>
                <li class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-white/90 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
                  <span>Billing, packages and payment integration</span>
                </li>
                <li class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-white/90 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
                  <span>Analytics dashboards and reports</span>
                </li>
                <li class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-white/90 mt-0.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-7.364 7.364a1 1 0 01-1.414 0L3.293 10.7a1 1 0 111.414-1.414l3.05 3.05 6.657-6.657a1 1 0 011.293-.386z" clip-rule="evenodd"/></svg>
                  <span>Real-time notifications and WebSockets</span>
                </li>
              </ul>
            </div>
          </div>
        </div>

        <!-- Right: Form -->
        <div class="p-6 md:p-10 md:col-span-8">

          <!-- Success Message -->
          <div v-if="success" class="mb-4 p-3 bg-blue-50 border border-blue-200 rounded-lg animate-fade-in">
            <div class="flex items-center">
              <svg class="w-5 h-5 text-blue-600 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div>
                <p class="text-blue-800 font-medium text-sm">{{ success }}</p>
                <p class="text-blue-700 text-xs mt-1">Redirecting to login...</p>
              </div>
            </div>
          </div>

          <!-- Error Message -->
          <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg animate-fade-in">
            <div class="flex items-start">
              <svg class="w-5 h-5 text-red-600 mr-2 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
          <form @submit.prevent="handleSubmit" class="space-y-5">
            <!-- User Details -->
            <div class="bg-white border border-gray-200 rounded-xl p-4 md:p-5 shadow-sm">
              <h2 class="text-sm font-semibold text-gray-900 mb-3">Your Details</h2>
              <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Your Full Name *</label>
                  <input v-model="form.admin_name" type="text" required class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="John Doe" />
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Your Email *</label>
                  <div class="relative">
                    <input v-model="form.admin_email" type="email" required @input="validateEmail" class="w-full px-3 py-2 pr-10 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="john@company.com" />
                    <div v-if="emailAvailable !== null" class="absolute right-2 top-1/2 -translate-y-1/2">
                      <svg v-if="emailAvailable" class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                      <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                  </div>
                  <p v-if="emailAvailable === false" class="text-xs text-red-600 mt-1">Email taken</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Username *</label>
                  <div class="relative">
                    <input v-model="form.admin_username" type="text" required pattern="[a-z0-9_]+" @input="validateUsername" class="w-full px-3 py-2 pr-10 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="johndoe" />
                    <div v-if="usernameAvailable !== null" class="absolute right-2 top-1/2 -translate-y-1/2">
                      <svg v-if="usernameAvailable" class="w-5 h-5 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                      <svg v-else class="w-5 h-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    </div>
                  </div>
                  <p v-if="usernameAvailable === false" class="text-xs text-red-600 mt-1">Username taken</p>
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mt-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Password *</label>
                  <input v-model="form.admin_password" type="password" required minlength="8" pattern="^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z\d]).{8,}$" class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="••••••••" title="Password must contain at least 8 characters, including uppercase, lowercase, number and special character" />
                  <p class="text-xs text-gray-500 mt-1">Min 8 chars (uppercase, lowercase, number, special char)</p>
                </div>
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Confirm Password *</label>
                  <input v-model="form.admin_password_confirmation" type="password" required minlength="8" class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="••••••••" />
                </div>
              </div>
            </div>

            <!-- Company Details -->
            <div class="bg-white border border-gray-200 rounded-xl p-4 md:p-5 shadow-sm">
              <h2 class="text-sm font-semibold text-gray-900 mb-3">Company Details</h2>
              <div class="space-y-3">
                <div>
                  <label class="block text-xs font-semibold text-gray-700 mb-1.5">Company Name *</label>
                  <input v-model="form.tenant_name" type="text" required class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="Your Company Name" />
                  <p v-if="generatedSlug" class="text-xs text-gray-600 mt-1.5 flex items-center">
                    <svg class="w-3.5 h-3.5 mr-1" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    Your subdomain: <strong class="ml-1">{{ generatedSlug }}.{{ baseDomain }}</strong>
                  </p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1.5">Company Email <span class="text-gray-400">(optional)</span></label>
                    <input v-model="form.tenant_email" type="email" class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="contact@company.com" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1.5">Company Phone <span class="text-gray-400">(optional)</span></label>
                    <input v-model="form.tenant_phone" type="tel" class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="+254712345678" />
                  </div>
                  <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1.5">Company Address <span class="text-gray-400">(optional)</span></label>
                    <input v-model="form.tenant_address" type="text" class="w-full px-3 py-2 border-2 border-gray-200 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all hover:border-gray-300" placeholder="Your company address" />
                  </div>
                </div>
                <!-- Terms & Conditions -->
                <div class="bg-blue-50 border-2 border-blue-200 rounded-lg p-3 mt-3">
                  <div class="flex items-start">
                    <input 
                      v-model="form.accept_terms" 
                      type="checkbox" 
                      required
                      class="mt-0.5 mr-2 w-4 h-4 text-blue-600 border-gray-300 rounded focus:ring-blue-500 cursor-pointer"
                    />
                    <label class="text-xs text-gray-700 cursor-pointer select-none">
                      I agree to the <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Terms of Service</a> and 
                      <a href="#" class="text-blue-600 hover:text-blue-800 underline font-medium">Privacy Policy</a>
                    </label>
                  </div>
                </div>
              </div>
            </div>

            <!-- Submit Button -->
            <button type="submit" :disabled="loading || !canSubmit" class="w-full bg-gradient-to-r from-blue-600 via-indigo-600 to-cyan-600 text-white py-3 rounded-xl hover:from-blue-700 hover:via-indigo-700 hover:to-cyan-700 disabled:opacity-50 disabled:cursor-not-allowed font-semibold shadow-xl transition-all transform hover:scale-[1.01] hover:shadow-2xl active:scale-[0.99] text-sm flex items-center justify-center">
              <svg v-if="loading" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              <span v-if="loading">Creating Your Account...</span>
              <span v-else class="flex items-center">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg>
                Start Free Trial - No Credit Card Required
              </span>
            </button>
          </form>

          <!-- Login Link -->
          <div class="mt-4 text-center">
            <p class="text-sm text-gray-600">
              Already have an account? 
              <router-link to="/login" class="text-blue-600 hover:text-blue-800 font-semibold transition-colors ml-1">Sign In</router-link>
            </p>
          </div>

          <!-- Footer -->
          <div class="mt-4 pt-3 border-t border-gray-200 text-center">
            <p class="text-xs text-gray-500">© {{ new Date().getFullYear() }} WifiCore by TraidNet Solutions. All rights reserved.</p>
            <div class="flex items-center justify-center mt-1 space-x-3 text-xs text-gray-400">
              <span class="flex items-center"><svg class="w-4 h-4 mr-1 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>30-day free trial</span>
              <span class="flex items-center"><svg class="w-4 h-4 mr-1 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>No credit card required</span>
              <span class="flex items-center"><svg class="w-4 h-4 mr-1 text-blue-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>Cancel anytime</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import LoadingProgress from '@/components/LoadingProgress.vue'
import { useNotificationStore } from '@/stores/notifications'

const router = useRouter()
const notificationStore = useNotificationStore()

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

// Loading state with progress tracking
const loadingState = ref({
  title: '🔄 Creating your account...',
  message: 'Please wait while we set up your workspace',
  progress: 0,
  statusMessage: ''
})

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
      const response = await axios.post('/register/check-username', {
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
      const response = await axios.post('/register/check-email', {
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
  
  // Reset loading state
  loadingState.value = {
    title: '🔄 Creating your account...',
    message: 'Setting up your workspace...',
    progress: 0,
    statusMessage: 'Initializing...'
  }
  
  try {
    // Simulate progress stages
    const progressStages = [
      { progress: 20, message: 'Validating information...' },
      { progress: 40, message: 'Creating tenant workspace...' },
      { progress: 60, message: 'Setting up database schema...' },
      { progress: 80, message: 'Configuring RADIUS authentication...' },
      { progress: 90, message: 'Finalizing setup...' }
    ]
    
    // Start progress simulation
    let currentStage = 0
    const progressInterval = setInterval(() => {
      if (currentStage < progressStages.length) {
        loadingState.value.progress = progressStages[currentStage].progress
        loadingState.value.statusMessage = progressStages[currentStage].message
        currentStage++
      }
    }, 800)
    
    const response = await axios.post('/register/tenant', form.value)
    
    // Clear progress interval
    clearInterval(progressInterval)
    
    if (response.data.success) {
      // Complete progress
      loadingState.value.progress = 100
      loadingState.value.title = '✅ Account Created Successfully!'
      loadingState.value.message = 'Your workspace is ready'
      loadingState.value.statusMessage = 'Redirecting to login...'
      
      success.value = '✅ Registration successful! Please check your email to verify your account.'
      
      // Show success toast
      notificationStore.success(
        'Registration Successful! 🎉',
        `Welcome ${form.value.admin_name}! Your account has been created. Please check your email to verify.`,
        8000
      )
      
      // Wait a moment to show completion
      await new Promise(resolve => setTimeout(resolve, 1500))
      
      // Redirect to login
      router.push({
        name: 'login',
        query: { 
          registered: 'true', 
          email: form.value.admin_email,
          subdomain: generatedSlug.value
        }
      })
    }
  } catch (err) {
    console.error('Registration error:', err)
    
    const errorMessage = err.response?.data?.message || 'Registration failed. Please try again.'
    error.value = errorMessage
    errors.value = err.response?.data?.errors || {}
    
    // Show error toast
    notificationStore.error(
      'Registration Failed',
      errorMessage,
      7000
    )
    
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

@keyframes reg-float {
  0% {
    transform: translate3d(0, 0, 0);
  }
  50% {
    transform: translate3d(0, -14px, 0);
  }
  100% {
    transform: translate3d(0, 0, 0);
  }
}

@keyframes reg-drift {
  0% {
    transform: translate3d(0, 0, 0);
  }
  50% {
    transform: translate3d(18px, 10px, 0);
  }
  100% {
    transform: translate3d(0, 0, 0);
  }
}

@keyframes reg-scan {
  0% {
    background-position: 0 0, 0 0;
  }
  100% {
    background-position: 160px 160px, 80px 80px;
  }
}

@keyframes reg-progress {
  0% {
    transform: translateX(-55%);
  }
  100% {
    transform: translateX(55%);
  }
}

.animate-fade-in {
  animation: fade-in 0.3s ease-out;
}

.reg-bg-anim {
  animation-duration: 10s;
  animation-timing-function: ease-in-out;
  animation-iteration-count: infinite;
}

.reg-bg-grid {
  opacity: 0.55;
  background-image:
    radial-gradient(circle at 1px 1px, rgba(59, 130, 246, 0.14) 1px, transparent 0),
    linear-gradient(135deg, rgba(99, 102, 241, 0.06), rgba(6, 182, 212, 0.06));
  background-size: 40px 40px, 100% 100%;
  animation-name: reg-scan;
}

.reg-bg-card {
  position: absolute;
  padding: 14px 14px 12px;
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.55);
  border: 1px solid rgba(59, 130, 246, 0.16);
  box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
  backdrop-filter: blur(10px);
  animation-name: reg-float;
}

.reg-bg-card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.reg-bg-pill {
  display: inline-block;
  height: 10px;
  width: 54px;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.22);
}

.reg-bg-dot {
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: rgba(99, 102, 241, 0.28);
  box-shadow: 0 0 0 6px rgba(59, 130, 246, 0.08);
}

.reg-bg-line {
  height: 10px;
  border-radius: 999px;
  background: rgba(6, 182, 212, 0.12);
  margin: 9px 0;
}

.reg-bg-line-sm {
  width: 55%;
}

.reg-bg-line-md {
  width: 75%;
}

.reg-bg-spark {
  margin-top: 12px;
  height: 2px;
  width: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, transparent, rgba(59, 130, 246, 0.55), transparent);
}

.reg-bg-metric {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  height: 44px;
}

.reg-bg-bar {
  width: 10px;
  height: 70%;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.24);
  animation-name: reg-drift;
}

.reg-bg-bar-2 {
  height: 45%;
  background: rgba(99, 102, 241, 0.22);
  animation-duration: 12s;
}

.reg-bg-bar-3 {
  height: 85%;
  background: rgba(6, 182, 212, 0.22);
  animation-duration: 11s;
}

.reg-bg-bar-4 {
  height: 55%;
  background: rgba(37, 99, 235, 0.18);
  animation-duration: 13s;
}

.reg-bg-timeline {
  display: flex;
  gap: 8px;
  margin: 10px 0 12px;
}

.reg-bg-tick {
  width: 100%;
  height: 10px;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.14);
}

.reg-bg-progress {
  height: 10px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.06);
  overflow: hidden;
}

.reg-bg-progress-fill {
  display: block;
  width: 50%;
  height: 100%;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(59, 130, 246, 0.25), rgba(99, 102, 241, 0.32), rgba(6, 182, 212, 0.25));
  animation-name: reg-progress;
  animation-duration: 6s;
  animation-timing-function: linear;
  animation-iteration-count: infinite;
}

.reg-bg-orbit {
  position: absolute;
  width: 180px;
  height: 180px;
  border-radius: 999px;
  border: 1px solid rgba(59, 130, 246, 0.16);
  box-shadow: inset 0 0 0 1px rgba(99, 102, 241, 0.10);
  background: radial-gradient(circle at 30% 30%, rgba(59, 130, 246, 0.12), transparent 58%);
  animation-name: reg-drift;
  animation-duration: 14s;
}

.reg-bg-orbit-2 {
  width: 140px;
  height: 140px;
  animation-duration: 16s;
}

.reg-bg-orbit-3 {
  width: 220px;
  height: 220px;
  animation-duration: 18s;
}

@media (prefers-reduced-motion: reduce) {
  .reg-bg-anim {
    animation: none !important;
  }
}
</style>
