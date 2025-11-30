<template>
  <Transition name="modal">
    <div
      v-if="show"
      class="fixed inset-0 z-50 flex items-center justify-center bg-black bg-opacity-30 backdrop-blur-sm"
    >
      <div
        class="bg-white bg-opacity-95 rounded-lg shadow-xl w-full max-w-lg mx-auto transform transition-all relative z-10 p-6"
      >
        <!-- Close button in top-right corner -->
        <button
          type="button"
          class="absolute top-3 right-3 text-red-500 hover:text-red-700 focus:outline-none"
          @click="closeModal"
          :disabled="loading"
        >
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path
              stroke-linecap="round"
              stroke-linejoin="round"
              stroke-width="2"
              d="M6 18L18 6M6 6l12 12"
            />
          </svg>
        </button>

        <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
          M-Pesa Payment for {{ selectedPackage.name }}
        </h3>

        <div class="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-100">
          <p class="text-sm text-blue-700">
            You will receive an M-Pesa push notification on your phone to complete payment of
            <span class="font-bold">KSH {{ selectedPackage.price }}</span
            >.
          </p>
        </div>

        <div class="space-y-4">
          <div>
            <label for="phone-number" class="block text-sm font-medium text-gray-700"
              >M-Pesa Phone Number</label
            >
            <div class="mt-1 relative rounded-md shadow-sm">
              <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <span class="text-gray-500 sm:text-sm">+254</span>
              </div>
              <input
                id="phone-number"
                v-model="phoneNumber"
                type="tel"
                placeholder="712 345678"
                class="block w-full pl-14 border border-gray-300 rounded-md shadow-sm py-2 px-3 focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                @input="formatPhoneNumber"
                :disabled="loading || paymentStatus?.type === 'success'"
              />
            </div>
            <p class="mt-1 text-xs text-gray-500">
              Enter 9-digit Safaricom number (e.g., 712345678)
            </p>
          </div>

          <div
            v-if="paymentStatus"
            class="p-3 rounded-md"
            :class="{
              'bg-green-50 text-green-700': paymentStatus.type === 'success',
              'bg-red-50 text-red-700': paymentStatus.type === 'error',
            }"
          >
            <div class="flex items-start space-x-2">
              <svg
                v-if="paymentStatus.type === 'success'"
                class="h-5 w-5 text-green-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                />
              </svg>
              <svg
                v-else
                class="h-5 w-5 text-red-500"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
              <div>
                <p class="text-sm font-medium">{{ paymentStatus.message }}</p>
                <p v-if="paymentStatus.hint" class="text-xs mt-1">{{ paymentStatus.hint }}</p>
                <p v-if="paymentStatus.code" class="text-xs mt-1 text-gray-500">
                  Error Code: {{ paymentStatus.code }}
                </p>
                <p v-if="paymentStatus.transactionId" class="text-xs mt-1 text-gray-600">
                  Transaction ID: {{ paymentStatus.transactionId }}
                </p>
              </div>
            </div>
          </div>
        </div>

        <div class="mt-6 flex justify-center">
          <button
            type="button"
            class="inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 text-white font-medium hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 w-3/4"
            @click="handlePayment"
            :disabled="!phoneNumberValid || loading || paymentStatus?.type === 'success'"
          >
            <span v-if="!loading">Make Payment</span>
            <span v-else class="flex items-center">
              <svg
                class="animate-spin -ml-1 mr-2 h-4 w-4 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  class="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  stroke-width="4"
                />
                <path
                  class="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"
                />
              </svg>
              Processing...
            </span>
          </button>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed } from 'vue'
import axios from 'axios'
import { usePayment } from '@/modules/tenant/composables/data/usePayments' // Updated to named import

const props = defineProps({
  show: Boolean,
  selectedPackage: Object,
  macAddress: String,
})

const emit = defineEmits(['close', 'payment-success', 'auto-login-success'])

const phoneNumber = ref('')
const { loading, paymentStatus, initiatePayment } = usePayment()
const pollingPayment = ref(false)
const autoLoginInProgress = ref(false)

const formatPhoneNumber = () => {
  phoneNumber.value = phoneNumber.value.replace(/\D/g, '')
  if (phoneNumber.value.startsWith('0')) {
    phoneNumber.value = phoneNumber.value.substring(1)
  }
  if (phoneNumber.value.length > 9) {
    phoneNumber.value = phoneNumber.value.substring(0, 9)
  }
}

const phoneNumberValid = computed(() => {
  const clean = phoneNumber.value.replace(/\D/g, '')
  return clean.length === 9 && /^[17]/.test(clean)
})

const handlePayment = async () => {
  if (!phoneNumberValid.value) {
    paymentStatus.value = {
      type: 'error',
      message: 'Please enter a valid 9-digit Safaricom number',
    }
    return
  }

  const formattedPhone = `+254${phoneNumber.value}`
  const result = await initiatePayment({
    package: props.selectedPackage,
    phoneNumber: formattedPhone,
    macAddress: props.macAddress || 'D6:D2:52:1C:90:71',
  })

  if (result.success && result.data?.payment_id) {
    // Start polling for payment status
    pollPaymentStatus(result.data.payment_id)
    
    emit('payment-success', {
      transactionId: result.data.CheckoutRequestID,
      amount: props.selectedPackage.price,
      paymentId: result.data.payment_id,
    })
  }
}

/**
 * Poll payment status for auto-login
 */
const pollPaymentStatus = async (paymentId) => {
  pollingPayment.value = true
  const maxAttempts = 60 // Poll for 60 seconds
  let attempts = 0
  
  const poll = async () => {
    try {
      const response = await axios.get(`/payments/${paymentId}/status`)
      
      if (response.data.payment.status === 'completed') {
        pollingPayment.value = false
        
        // Check if auto-login is available
        if (response.data.auto_login && response.data.credentials) {
          await autoLogin(response.data.credentials)
        } else {
          paymentStatus.value = {
            type: 'success',
            message: 'Payment successful! Check your SMS for login credentials.',
          }
        }
        return
      } else if (response.data.payment.status === 'failed') {
        pollingPayment.value = false
        paymentStatus.value = {
          type: 'error',
          message: 'Payment failed. Please try again.',
        }
        return
      }
      
      // Continue polling
      attempts++
      if (attempts < maxAttempts) {
        setTimeout(poll, 1000) // Poll every second
      } else {
        pollingPayment.value = false
        paymentStatus.value = {
          type: 'info',
          message: 'Payment verification timeout. Please check your SMS for credentials.',
        }
      }
      
    } catch (error) {
      console.error('Payment status check error:', error)
      attempts++
      if (attempts < maxAttempts) {
        setTimeout(poll, 2000) // Retry after 2 seconds on error
      } else {
        pollingPayment.value = false
        paymentStatus.value = {
          type: 'error',
          message: 'Error checking payment status. Please check your SMS.',
        }
      }
    }
  }
  
  // Start polling after a short delay
  setTimeout(poll, 2000)
}

/**
 * Auto-login user with credentials
 */
const autoLogin = async (credentials) => {
  autoLoginInProgress.value = true
  
  try {
    paymentStatus.value = {
      type: 'info',
      message: 'Connecting you to WiFi...',
    }
    
    // Call login API
    const response = await axios.post('/hotspot/login', {
      username: credentials.username,
      password: credentials.password,
      mac_address: props.macAddress || 'D6:D2:52:1C:90:71',
      auto_login: true,
    })
    
    if (response.data.success) {
      paymentStatus.value = {
        type: 'success',
        message: `🎉 You're connected to WiFi!`,
        hint: `Package: ${credentials.package_name} | Valid until: ${new Date(credentials.expires_at).toLocaleString()}`,
      }
      
      // Emit success event
      emit('auto-login-success', {
        credentials,
        session: response.data.data.session,
      })
      
      // Close modal after 3 seconds
      setTimeout(() => {
        closeModal()
      }, 3000)
    } else {
      paymentStatus.value = {
        type: 'warning',
        message: 'Auto-login failed. Please check your SMS for credentials.',
      }
    }
    
  } catch (error) {
    console.error('Auto-login error:', error)
    paymentStatus.value = {
      type: 'warning',
      message: 'Auto-login failed. Please use credentials from SMS to login.',
      hint: error.response?.data?.message || 'Connection error',
    }
  } finally {
    autoLoginInProgress.value = false
  }
}

const closeModal = () => {
  if (!loading.value) {
    phoneNumber.value = ''
    paymentStatus.value = null
    emit('close')
  }
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}
</style>
