<template>
  <div class="flex flex-col min-h-screen bg-gray-50">
    <!-- Sticky Header -->
    <header class="sticky top-0 z-50 bg-gradient-to-r from-green-600 to-green-500 shadow-sm">
      <div class="container mx-auto px-4 py-3">
        <div class="flex items-center justify-between">
          <div class="flex items-center space-x-2">
            <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"
              />
            </svg>
            <h1 class="text-xl font-bold text-white tracking-tight">TraidNet WiFi Packages</h1>
          </div>
          <div
            v-if="deviceMacAddress"
            class="text-xs font-medium text-green-100 bg-green-700/90 px-3 py-1.5 rounded-full backdrop-blur-sm"
          >
            Device: <span class="font-mono">{{ deviceMacAddress }}</span>
          </div>
        </div>
      </div>
    </header>

    <!-- Main content -->
    <main class="flex-1 overflow-y-auto bg-gradient-to-b from-white to-gray-50/50">
      <div class="container mx-auto px-4 py-8">
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
              v-for="pkg in packages"
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
            @close="handleModalClose"
            @payment-success="handlePaymentSuccess"
          />
        </template>
      </div>
    </main>

    <!-- Footer -->
    <footer class="bg-white py-4 border-t border-gray-100">
      <div class="container mx-auto px-4 text-center">
        <p class="text-xs text-gray-400">© {{ new Date().getFullYear() }} TraidNet Technologies</p>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { usePackages } from '@/composables/usePackages'
import PackageCard from '@/components/packages/PackageCard.vue'
import PaymentModal from '@/components/payment/PaymentModal.vue'

const { packages, loading, error, fetchPackages } = usePackages()
const selectedPackage = ref(null)
const showPaymentModal = ref(false)
const deviceMacAddress = ref(null)
const macDetectionError = ref(null)

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
