<template>
  <div class="min-h-screen bg-gradient-to-br from-gray-50 to-gray-100">
    <!-- Top Bar Section -->
    <section class="sticky top-0 z-20 bg-gradient-to-r from-green-600 to-green-500">
      <div
        class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 sm:py-4 flex items-center justify-between"
      >
        <div class="flex items-center space-x-2 sm:space-x-3">
          <div class="p-1 sm:p-2 rounded-lg bg-white/10 backdrop-blur-sm">
            <svg
              class="w-5 h-5 sm:w-6 sm:h-6 text-white"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
              />
            </svg>
          </div>
          <h1 class="text-lg sm:text-xl font-bold text-white">TraidNet Solutions</h1>
        </div>
      </div>
    </section>

    <!-- Steps Section - Combined into one card -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 sm:py-12">
      <div class="text-center mb-8 sm:mb-12">
        <h2 class="text-2xl sm:text-3xl font-bold text-gray-900 mb-2">How to Purchase WiFi</h2>
        <p class="text-base sm:text-lg text-gray-600 max-w-3xl mx-auto">
          Get connected in just three simple steps
        </p>
      </div>

      <div
        class="bg-white rounded-xl shadow-sm hover:shadow-md transition-all duration-300 border border-gray-100 p-6 sm:p-8"
      >
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
          <div v-for="(step, index) in steps" :key="index" class="flex items-start">
            <div
              class="flex-shrink-0 flex items-center justify-center w-12 h-12 sm:w-14 sm:h-14 rounded-full bg-green-600 text-white font-bold text-lg sm:text-xl mr-4"
            >
              {{ index + 1 }}
            </div>
            <div>
              <h3 class="text-lg sm:text-xl font-bold text-gray-800 mb-2">
                Step {{ index + 1 }}: {{ step.title }}
              </h3>
              <p class="text-gray-600 text-sm sm:text-base">{{ step.description }}</p>
            </div>
          </div>
        </div>

        <div class="mt-8 pt-6 border-t border-gray-100 text-center">
          <p class="text-gray-600 text-sm sm:text-base">
            Need help? Contact our support at
            <a
              href="tel:+254700000000"
              class="text-green-600 hover:text-green-700 font-medium hover:underline"
              >+254 700 000 000</a
            >
          </p>
        </div>
      </div>
    </section>

    <!-- Packages Section -->
    <section class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pb-8 sm:pb-16">
      <div class="bg-white rounded-xl shadow-sm overflow-hidden border border-gray-100">
        <!-- Packages Header -->
        <header class="bg-gray-50 border-b border-gray-200">
          <div class="px-4 sm:px-6 py-3 sm:py-4">
            <h2 class="text-lg sm:text-xl font-bold text-gray-800">WiFi Packages</h2>
          </div>
        </header>

        <!-- Main content -->
        <main class="p-4 sm:p-6 bg-gradient-to-b from-white to-gray-50/50">
          <!-- Loading state -->
          <div v-if="loading" class="flex flex-col items-center justify-center py-12 sm:py-16">
            <div class="relative">
              <div
                class="w-12 h-12 sm:w-16 sm:h-16 border-4 border-green-500 border-t-transparent rounded-full animate-spin"
              ></div>
              <div class="absolute inset-0 flex items-center justify-center">
                <div class="w-6 h-6 sm:w-8 sm:h-8 bg-green-500 rounded-full animate-ping"></div>
              </div>
            </div>
            <p class="mt-3 sm:mt-4 text-gray-600 text-sm sm:text-base">Loading packages...</p>
          </div>

          <!-- Error state -->
          <div
            v-else-if="error"
            class="max-w-md mx-auto bg-white p-4 sm:p-8 rounded-xl shadow-sm border border-gray-200 text-center"
          >
            <div class="mb-4 sm:mb-6">
              <div
                class="mx-auto flex items-center justify-center h-10 w-10 sm:h-12 sm:w-12 rounded-full bg-red-100"
              >
                <svg
                  class="h-5 w-5 sm:h-6 sm:w-6 text-red-600"
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
            </div>
            <h3 class="text-base sm:text-lg font-medium text-gray-900 mb-2">Connection Error</h3>
            <p class="text-gray-600 mb-4 sm:mb-6 text-sm sm:text-base">{{ error }}</p>
            <button
              @click="fetchPackages"
              class="px-4 sm:px-6 py-1.5 sm:py-2 bg-gradient-to-r from-green-500 to-green-600 text-white font-medium rounded-lg hover:shadow-md transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 text-sm sm:text-base"
            >
              Try Again
            </button>
          </div>

          <!-- Success state -->
          <template v-else>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6">
              <PackageCard
                v-for="pkg in packages"
                :key="pkg.id"
                :pkg="pkg"
                :selected="selectedPackage?.id === pkg.id"
                @select="selectPackage(pkg)"
                class="transition-all duration-300 hover:shadow-lg hover:-translate-y-1"
              />
            </div>

            <PaymentModal
              v-if="showPaymentModal"
              :show="showPaymentModal"
              :selected-package="selectedPackage"
              :mac-address="deviceMacAddress"
              @close="handleModalClose"
              @payment-success="handlePaymentSuccess"
            />
          </template>
        </main>

        <!-- Footer -->
        <footer class="bg-gray-50 px-4 sm:px-6 py-3 sm:py-4 border-t border-gray-200">
          <div class="flex items-center justify-between flex-col sm:flex-row gap-2 sm:gap-0">
            <p class="text-xs sm:text-sm text-gray-500">
              © {{ new Date().getFullYear() }} TraidNet Solutions
            </p>
            <div class="flex space-x-3 sm:space-x-4">
              <a href="#" class="text-gray-500 hover:text-gray-700">
                <span class="sr-only">Terms</span>
                <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path
                    fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                    clip-rule="evenodd"
                  />
                </svg>
              </a>
              <a href="#" class="text-gray-500 hover:text-gray-700">
                <span class="sr-only">Privacy</span>
                <svg class="h-4 w-4 sm:h-5 sm:w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path
                    fill-rule="evenodd"
                    d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"
                    clip-rule="evenodd"
                  />
                </svg>
              </a>
            </div>
          </div>
        </footer>
      </div>
    </section>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuth } from '@/composables/useAuth'
import { usePackages } from '@/composables/usePackages'
import PackageCard from '@/components/packages/PackageCard.vue'
import PaymentModal from '@/components/payment/PaymentModal.vue'

const auth = useAuth()
const { packages, loading, error, fetchPackages } = usePackages()
const selectedPackage = ref(null)
const showPaymentModal = ref(false)
const deviceMacAddress = ref(null)
const macDetectionError = ref(null)

const steps = [
  {
    title: 'Select Your Package',
    description: 'Choose from our range of high-speed WiFi packages tailored to your needs.',
  },
  {
    title: 'Secure Payment',
    description: 'Complete your purchase securely via M-Pesa with instant confirmation.',
  },
  {
    title: 'Instant Access',
    description: 'Get immediate access to high-speed internet after successful payment.',
  },
]

auth.checkAuth()

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

onMounted(async () => {
  await fetchPackages()
  deviceMacAddress.value = (await detectMacAddress()) || 'D6:D2:52:1C:90:71'
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
</script>
