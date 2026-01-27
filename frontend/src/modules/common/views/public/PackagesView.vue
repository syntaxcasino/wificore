<template>
  <div class="flex flex-col min-h-full bg-gradient-to-br from-gray-50 via-green-50/30 to-gray-50">
    <!-- Sticky Header -->
    <header class="sticky top-0 z-50 bg-gradient-to-r from-green-600 to-green-500 shadow-lg backdrop-blur-sm flex-shrink-0">
      <div class="container mx-auto px-4 py-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-3">
            <div class="w-10 h-10 bg-white/20 rounded-lg flex items-center justify-center backdrop-blur-sm">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
                />
              </svg>
            </div>
            <div>
              <h1 class="text-xl font-bold text-white tracking-tight">{{ tenantBranding.company_name }}</h1>
              <p class="text-xs text-green-100">{{ tenantBranding.tagline }}</p>
            </div>
          </div>
          <div class="flex items-center gap-3">
            <!-- Prominent Login Button -->
            <button
              v-if="!showLoginForm"
              @click="showLoginForm = true"
              class="flex items-center gap-2 px-6 py-2.5 bg-white text-green-600 rounded-lg transition-all shadow-lg hover:shadow-xl hover:scale-105 font-bold"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
              </svg>
              <span>Already Have Access? Login</span>
            </button>
          
          </div>
        </div>
      </div>
    </header>

    <!-- Main content -->
    <main class="flex-1 bg-gradient-to-b from-white to-gray-50/50">
      <div class="container mx-auto px-4 py-8">
        
        <!-- Login Form Modal -->
        <div v-if="showLoginForm" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
          <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md transform transition-all">
            <div class="bg-gradient-to-r from-green-600 to-green-500 p-6 rounded-t-2xl">
              <div class="flex items-center justify-between">
                <h3 class="text-2xl font-bold text-white">Hotspot Login</h3>
                <button @click="showLoginForm = false" class="text-white hover:bg-white/20 p-2 rounded-lg transition-colors">
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
              </div>
              <p class="text-green-100 mt-2">Enter your credentials to access the internet</p>
            </div>
            <form @submit.prevent="handleLogin" class="p-6 space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Username</label>
                <input
                  v-model="loginForm.username"
                  type="text"
                  required
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all"
                  placeholder="Enter your username"
                />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Password</label>
                <input
                  v-model="loginForm.password"
                  type="password"
                  required
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500 transition-all"
                  placeholder="Enter your password"
                />
              </div>
              <button
                type="submit"
                :disabled="loginLoading"
                class="w-full py-3 bg-gradient-to-r from-green-600 to-green-500 text-white font-semibold rounded-lg hover:shadow-lg transform hover:-translate-y-0.5 transition-all disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="!loginLoading">Login to Internet</span>
                <span v-else class="flex items-center justify-center gap-2">
                  <svg class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  Connecting...
                </span>
              </button>
              <p v-if="loginError" class="text-red-600 text-sm text-center">{{ loginError }}</p>
            </form>
          </div>
        </div>

        <!-- Hero Section -->
        <div class="mb-16 text-center relative">
          <div class="absolute inset-0 bg-gradient-to-r from-green-500/10 to-green-600/10 rounded-3xl blur-3xl"></div>
          <div class="relative">
            <h2 class="text-4xl md:text-5xl font-bold text-gray-900 mb-4">
              Lightning-Fast <span class="text-green-600">WiFi</span> Access
            </h2>
            <p class="text-gray-600 text-lg md:text-xl max-w-2xl mx-auto">
              Get connected in just three simple steps and enjoy high-speed internet
            </p>
          </div>
        </div>

        <!-- Steps Cards with Enhanced Design -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-16">
          <!-- Step 1 -->
          <div class="group relative bg-white rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 border border-green-100 hover:border-green-300">
            <div class="absolute -top-6 left-8">
              <div class="w-14 h-14 bg-gradient-to-br from-green-600 to-green-500 rounded-2xl flex items-center justify-center text-white font-bold text-2xl shadow-lg transform group-hover:scale-110 transition-transform">
                1
              </div>
            </div>
            <div class="mt-6">
              <h3 class="text-2xl font-bold text-gray-900 mb-3">Select Package</h3>
              <p class="text-gray-600 leading-relaxed">
                Browse our affordable packages and choose the perfect plan for your internet needs.
              </p>
            </div>
          </div>

          <!-- Step 2 -->
          <div class="group relative bg-white rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 border border-green-100 hover:border-green-300">
            <div class="absolute -top-6 left-8">
              <div class="w-14 h-14 bg-gradient-to-br from-green-600 to-green-500 rounded-2xl flex items-center justify-center text-white font-bold text-2xl shadow-lg transform group-hover:scale-110 transition-transform">
                2
              </div>
            </div>
            <div class="mt-6">
              <h3 class="text-2xl font-bold text-gray-900 mb-3">Secure Payment</h3>
              <p class="text-gray-600 leading-relaxed">
                Pay safely via M-Pesa and receive instant confirmation of your purchase.
              </p>
            </div>
          </div>

          <!-- Step 3 -->
          <div class="group relative bg-white rounded-2xl p-8 shadow-lg hover:shadow-2xl transition-all duration-300 border border-green-100 hover:border-green-300">
            <div class="absolute -top-6 left-8">
              <div class="w-14 h-14 bg-gradient-to-br from-green-600 to-green-500 rounded-2xl flex items-center justify-center text-white font-bold text-2xl shadow-lg transform group-hover:scale-110 transition-transform">
                3
              </div>
            </div>
            <div class="mt-6">
              <h3 class="text-2xl font-bold text-gray-900 mb-3">Instant Access</h3>
              <p class="text-gray-600 leading-relaxed">
                Get connected immediately and enjoy blazing-fast internet speeds.
              </p>
            </div>
          </div>
        </div>

        <!-- Support Banner -->
        <div class="mb-16 bg-gradient-to-r from-green-600 to-green-500 rounded-2xl p-8 text-center shadow-xl">
          <div class="flex items-center justify-center gap-3 mb-3">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636l-3.536 3.536m0 5.656l3.536 3.536M9.172 9.172L5.636 5.636m3.536 9.192l-3.536 3.536M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-5 0a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <h3 class="text-2xl font-bold text-white">Need Assistance?</h3>
          </div>
          <p class="text-green-100 text-lg mb-4">Our support team is here to help you 24/7</p>
          <a
            href="tel:+254700000000"
            class="inline-flex items-center gap-2 px-8 py-3 bg-white text-green-600 font-bold rounded-lg hover:bg-green-50 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
            </svg>
            +254 700 000 000
          </a>
        </div>

        <!-- WiFi Packages Section Header -->
        <div class="mb-10 text-center">
          <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-3">Choose Your Perfect Plan</h2>
          <p class="text-gray-600 text-lg">Affordable packages designed for every need</p>
        </div>

        <!-- Loading state with proper spinner -->
        <div v-if="loading" class="flex justify-center py-16">
          <div
            class="inline-block h-8 w-8 animate-spin rounded-full border-4 border-solid border-green-600 border-r-transparent align-[-0.125em] motion-reduce:animate-[spin_1.5s_linear_infinite]"
          >
            <span
              class="!absolute !-m-px !h-px !w-px !overflow-hidden !whitespace-nowrap !border-0 !p-0 ![clip:rect(0,0,0,0)]"
              >Loading...</span
            >
          </div>
        </div>

        <!-- Error state -->
        <div
          v-else-if="error"
          class="max-w-md mx-auto bg-white p-6 rounded-xl shadow-sm border border-gray-100"
        >
          <div class="flex flex-col items-center text-center space-y-4">
            <div class="p-3 bg-red-100 rounded-full">
              <svg
                class="w-6 h-6 text-red-500"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                />
              </svg>
            </div>
            <h3 class="text-lg font-medium text-gray-900">Something went wrong</h3>
            <p class="text-sm text-gray-500">{{ error }}</p>
            <button
              @click="fetchPackages"
              class="mt-2 px-4 py-2 bg-gradient-to-r from-green-500 to-green-600 text-white text-sm font-medium rounded-lg hover:shadow-md transition-all duration-200"
            >
              Retry Connection
            </button>
          </div>
        </div>

        <!-- Success state -->
        <template v-else>
          <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <PackageCard
              v-for="pkg in publicPackages"
              :key="pkg.id"
              :pkg="pkg"
              :selected="selectedPackage?.id === pkg.id"
              @select="selectPackage(pkg)"
              class="transition-all duration-300 hover:shadow-md hover:-translate-y-1"
            />
          </div>

          <PaymentModal
            v-if="showPaymentModal"
            :show="showPaymentModal"
            :selected-package="selectedPackage"
            :mac-address="deviceMacAddress"
            :tenant-id="tenantId"
            @close="handleModalClose"
            @payment-success="handlePaymentSuccess"
          />
        </template>
      </div>
    </main>

    <!-- Toast Notification -->
    <transition
      enter-active-class="transition ease-out duration-300"
      enter-from-class="translate-y-2 opacity-0"
      enter-to-class="translate-y-0 opacity-100"
      leave-active-class="transition ease-in duration-200"
      leave-from-class="translate-y-0 opacity-100"
      leave-to-class="translate-y-2 opacity-0"
    >
      <div
        v-if="showToast"
        class="fixed top-20 right-4 z-50 max-w-md"
      >
        <div
          :class="[
            'rounded-lg shadow-2xl p-4 flex items-start gap-3',
            toastType === 'success' ? 'bg-green-600' : 'bg-red-600'
          ]"
        >
          <svg v-if="toastType === 'success'" class="w-6 h-6 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <svg v-else class="w-6 h-6 text-white flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div class="flex-1">
            <p class="text-white font-medium">{{ toastMessage }}</p>
          </div>
          <button @click="showToast = false" class="text-white hover:text-gray-200">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </transition>

    <!-- Enhanced Footer -->
    <footer class="bg-gradient-to-r from-gray-900 to-gray-800 py-8 border-t border-gray-700 mt-auto">
      <div class="container mx-auto px-4">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-8 mb-6">
          <div>
            <h4 class="text-white font-bold mb-3">{{ tenantBranding.company_name }}</h4>
            <p class="text-gray-400 text-sm">{{ tenantBranding.tagline || 'Providing high-speed internet solutions for homes and businesses.' }}</p>
          </div>
          <div>
            <h4 class="text-white font-bold mb-3">Quick Links</h4>
            <ul class="space-y-2 text-sm">
              <li><a href="#" class="text-gray-400 hover:text-green-500 transition-colors">About Us</a></li>
              <li><a href="#" class="text-gray-400 hover:text-green-500 transition-colors">Support</a></li>
              <li><a href="#" class="text-gray-400 hover:text-green-500 transition-colors">Terms of Service</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-bold mb-3">Contact</h4>
            <ul class="space-y-2 text-sm">
              <li v-if="tenantBranding.support_email" class="text-gray-400">Email: {{ tenantBranding.support_email }}</li>
              <li v-if="tenantBranding.support_phone" class="text-gray-400">Phone: {{ tenantBranding.support_phone }}</li>
            </ul>
          </div>
        </div>
        <div class="border-t border-gray-700 pt-6 text-center">
          <p class="text-gray-400 text-sm">© {{ new Date().getFullYear() }} {{ tenantBranding.company_name }}. All rights reserved.</p>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import { useRoute } from 'vue-router'
import { usePublicPackages } from '@/modules/common/composables/usePublicPackages'
import PackageCard from '@/modules/tenant/components/packages/PackageCard.vue'
import PaymentModal from '@/modules/tenant/components/payment/PaymentModal.vue'

const route = useRoute()
const { packages, loading, error, tenantId, fetchPublicPackages } = usePublicPackages()

// Tenant branding state
const tenantBranding = ref({
  company_name: 'TraidNet Solutions',
  logo_url: null,
  primary_color: '#10b981',
  secondary_color: '#059669',
  tagline: 'High-Speed WiFi Packages',
  support_email: 'support@traidnet.com',
  support_phone: '+254 700 000 000'
})

// MikroTik hotspot parameters (from URL query)
const hotspotParams = ref({
  mac: null,
  ip: null,
  username: null,
  linkLogin: null,
  linkLogout: null,
  linkOrig: null,
  error: null,
  trial: null,
  popup: null
})

// Public packages are already filtered by backend (only active hotspot packages)
const publicPackages = computed(() => {
  return packages.value
})
const selectedPackage = ref(null)
const showPaymentModal = ref(false)
const deviceMacAddress = ref(null)
const macDetectionError = ref(null)

// Login form state
const showLoginForm = ref(false)
const loginLoading = ref(false)
const loginError = ref(null)
const loginForm = ref({
  username: '',
  password: ''
})

// Toast notification state
const showToast = ref(false)
const toastMessage = ref('')
const toastType = ref('success') // 'success' or 'error'

const showNotification = (message, type = 'success') => {
  toastMessage.value = message
  toastType.value = type
  showToast.value = true
  setTimeout(() => {
    showToast.value = false
  }, 5000)
}

const detectMacAddress = async () => {
  try {
    if (!window.RTCPeerConnection) {
      throw new Error('WebRTC not supported in this browser')
    }

    const peerConnection = new RTCPeerConnection({ iceServers: [] })
    peerConnection.createDataChannel('macDetectionChannel')
    const offer = await peerConnection.createOffer()
    await peerConnection.setLocalDescription(offer)

    return new Promise((resolve) => {
      const timeout = setTimeout(() => {
        peerConnection.close()
        resolve(null)
      }, 1000)

      peerConnection.onicecandidate = (event) => {
        if (event.candidate) {
          const candidate = event.candidate.candidate
          const macMatch = candidate.match(/ ([0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5})/)
          if (macMatch && macMatch[1]) {
            clearTimeout(timeout)
            peerConnection.close()
            resolve(macMatch[1].toUpperCase())
          }
        } else {
          clearTimeout(timeout)
          peerConnection.close()
          resolve(null)
        }
      }
    })
  } catch (error) {
    console.error('MAC detection error:', error)
    macDetectionError.value = 'Could not detect device MAC address'
    return null
  }
}

// Extract subdomain from hostname
const getSubdomain = () => {
  const hostname = window.location.hostname
  const parts = hostname.split('.')
  
  // For localhost development
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return route.query.subdomain || 'demo'
  }
  
  // For production, extract first part of domain
  if (parts.length >= 3) {
    return parts[0]
  }
  
  return null
}

// Load tenant branding
const loadTenantBranding = async () => {
  const subdomain = getSubdomain()
  if (!subdomain) return
  
  try {
    const response = await axios.get(`/public/tenant/${subdomain}`)
    if (response.data.success && response.data.data.branding) {
      tenantBranding.value = {
        ...tenantBranding.value,
        ...response.data.data.branding
      }
      
      // Apply CSS variables for branding
      if (tenantBranding.value.primary_color) {
        document.documentElement.style.setProperty('--primary-color', tenantBranding.value.primary_color)
      }
      if (tenantBranding.value.secondary_color) {
        document.documentElement.style.setProperty('--secondary-color', tenantBranding.value.secondary_color)
      }
    }
  } catch (err) {
    console.error('Failed to load tenant branding:', err)
  }
}

// Parse MikroTik hotspot parameters from URL
const parseMikrotikParams = () => {
  hotspotParams.value = {
    mac: route.query.mac || route.query['mac-address'] || null,
    ip: route.query.ip || null,
    username: route.query.username || null,
    linkLogin: route.query['link-login'] || null,
    linkLogout: route.query['link-logout'] || null,
    linkOrig: route.query['link-orig'] || null,
    error: route.query.error || null,
    trial: route.query.trial || null,
    popup: route.query.popup || null
  }
  
  // Use MAC from MikroTik if available
  if (hotspotParams.value.mac) {
    deviceMacAddress.value = hotspotParams.value.mac.toUpperCase()
  }
  
  // Pre-fill username if provided
  if (hotspotParams.value.username) {
    loginForm.value.username = hotspotParams.value.username
  }
  
  // Show error if MikroTik sent one
  if (hotspotParams.value.error) {
    const errorMessages = {
      'invalid-credentials': 'Invalid username or password',
      'no-credit': 'Insufficient credit',
      'already-logged-in': 'You are already logged in from another device'
    }
    loginError.value = errorMessages[hotspotParams.value.error] || 'Authentication failed. Please try again.'
  }
}

onMounted(async () => {
  // Parse MikroTik parameters first
  parseMikrotikParams()
  
  // Load tenant branding
  await loadTenantBranding()
  
  // Fetch public packages (tenant auto-detected from router/subdomain)
  await fetchPublicPackages()
  
  // Detect MAC address if not provided by MikroTik
  if (!deviceMacAddress.value) {
    deviceMacAddress.value = (await detectMacAddress()) || 'D6:D2:52:1C:90:71'
  }
  
  // Log tenant info for debugging
  if (tenantId.value) {
    console.log('Connected to tenant:', tenantId.value)
  }
  
  // Log hotspot params for debugging
  if (hotspotParams.value.mac || hotspotParams.value.linkLogin) {
    console.log('MikroTik hotspot mode detected:', hotspotParams.value)
  }
})

const selectPackage = (pkg) => {
  selectedPackage.value = pkg
  showPaymentModal.value = true
}

const handlePaymentSuccess = (paymentResult) => {
  showPaymentModal.value = false
  selectedPackage.value = null
}

const handleModalClose = () => {
  showPaymentModal.value = false
  selectedPackage.value = null
}

const handleLogin = async () => {
  loginLoading.value = true
  loginError.value = null
  
  try {
    // If MikroTik hotspot mode, submit to MikroTik's link-login
    if (hotspotParams.value.linkLogin) {
      // Create form and submit to MikroTik
      const form = document.createElement('form')
      form.method = 'POST'
      form.action = hotspotParams.value.linkLogin
      
      // Add username
      const usernameInput = document.createElement('input')
      usernameInput.type = 'hidden'
      usernameInput.name = 'username'
      usernameInput.value = loginForm.value.username
      form.appendChild(usernameInput)
      
      // Add password
      const passwordInput = document.createElement('input')
      passwordInput.type = 'hidden'
      passwordInput.name = 'password'
      passwordInput.value = loginForm.value.password
      form.appendChild(passwordInput)
      
      // Add destination (original URL)
      if (hotspotParams.value.linkOrig) {
        const dstInput = document.createElement('input')
        dstInput.type = 'hidden'
        dstInput.name = 'dst'
        dstInput.value = hotspotParams.value.linkOrig
        form.appendChild(dstInput)
      }
      
      // Add popup parameter
      if (hotspotParams.value.popup) {
        const popupInput = document.createElement('input')
        popupInput.type = 'hidden'
        popupInput.name = 'popup'
        popupInput.value = hotspotParams.value.popup
        form.appendChild(popupInput)
      }
      
      // Submit form
      document.body.appendChild(form)
      form.submit()
      
      // Show loading state
      showNotification('Authenticating with hotspot...', 'success')
      
    } else {
      // Regular login via API (non-hotspot mode)
      const response = await axios.post('/hotspot/login', {
        username: loginForm.value.username,
        password: loginForm.value.password,
        mac_address: deviceMacAddress.value
      })
      
      if (response.data.success) {
        // Close modal
        showLoginForm.value = false
        
        // Reset form
        loginForm.value = { username: '', password: '' }
        
        // Show success notification
        showNotification('Login successful! You are now connected to the internet.', 'success')
        
        // Optional: Redirect to a success page or dashboard after a delay
        setTimeout(() => {
          // You can redirect here if needed
          // window.location.href = '/dashboard'
        }, 2000)
      } else {
        loginError.value = response.data.message || 'Login failed. Please try again.'
      }
    }
  } catch (err) {
    console.error('Login error:', err)
    
    if (err.response) {
      // Server responded with error
      loginError.value = err.response.data.message || 'Invalid username or password.'
    } else if (err.request) {
      // Request made but no response
      loginError.value = 'Unable to connect to server. Please check your connection.'
    } else {
      // Something else happened
      loginError.value = 'An error occurred. Please try again.'
    }
    
    showNotification(loginError.value, 'error')
  } finally {
    loginLoading.value = false
  }
}
</script>
