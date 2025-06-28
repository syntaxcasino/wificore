<!-- views/PackagesView.vue -->
<template>
  <div class="container mx-auto px-4 py-8">
    <h1 class="text-3xl font-bold text-gray-800 mb-8">Available Packages</h1>

    <div v-if="loading" class="flex justify-center py-12">
      <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
    </div>

    <div v-else-if="error" class="text-center py-8">
      <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded">
        <p>{{ error }}</p>
        <button
          @click="fetchPackages"
          class="mt-2 px-4 py-2 bg-blue-600 text-white rounded hover:bg-blue-700"
        >
          Retry
        </button>
      </div>
    </div>

    <template v-else>
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        <PackageCard
          v-for="pkg in packages"
          :key="pkg.id"
          :pkg="pkg"
          :selected="selectedPackage?.id === pkg.id"
          @select="selectPackage(pkg)"
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

// Enhanced MAC address detection using WebRTC
const detectMacAddress = async () => {
  try {
    if (!window.RTCPeerConnection) {
      throw new Error('WebRTC not supported in this browser')
    }

    const peerConnection = new RTCPeerConnection({ iceServers: [] })

    // Create data channel to trigger candidate gathering
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
          // Try to extract MAC address from candidate string
          const macMatch = candidate.match(/ ([0-9a-fA-F]{2}(:[0-9a-fA-F]{2}){5})/)
          if (macMatch && macMatch[1]) {
            clearTimeout(timeout)
            peerConnection.close()
            resolve(macMatch[1].toUpperCase())
          }
        } else {
          // No more candidates
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
  fetchPackages()
  deviceMacAddress.value = (await detectMacAddress()) || 'D6:D2:52:1C:90:71'
})

const selectPackage = (pkg) => {
  selectedPackage.value = pkg
  showPaymentModal.value = true
}

const handlePaymentSuccess = (paymentResult) => {
  setTimeout(() => {
    showPaymentModal.value = false
    selectedPackage.value = null
  }, 2000)
}

const handleModalClose = () => {
  showPaymentModal.value = false
  selectedPackage.value = null
}
</script>
