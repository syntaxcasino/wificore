<template>
  <Transition name="modal">
    <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
      <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity bg-black bg-opacity-50" aria-hidden="true"></div>

        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full relative z-10">
          <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
            <h3 class="text-lg leading-6 font-medium text-gray-900 mb-4">
              M-Pesa Payment for {{ selectedPackage.name }}
            </h3>

            <div class="mb-6 p-4 bg-blue-50 rounded-lg border border-blue-100">
              <p class="text-sm text-blue-700">
                You will receive an M-Pesa push notification on your phone to complete payment of
                <span class="font-bold">KSH {{ selectedPackage.price }}</span>.
              </p>
            </div>

            <div class="mt-6 space-y-4">
              <div>
                <label for="phone-number" class="block text-sm font-medium text-gray-700">M-Pesa Phone Number</label>
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
                <p class="mt-1 text-xs text-gray-500">Enter 9-digit Safaricom number (e.g., 712345678)</p>
              </div>

              <div v-if="paymentStatus" class="mt-4 p-3 rounded-md"
                :class="{
                  'bg-green-50 text-green-700': paymentStatus.type === 'success',
                  'bg-red-50 text-red-700': paymentStatus.type === 'error',
                }"
              >
                <div class="flex items-center">
                  <svg v-if="paymentStatus.type === 'success'" class="h-5 w-5 text-green-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  <svg v-else class="h-5 w-5 text-red-500 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                  <span>{{ paymentStatus.message }}</span>
                </div>
                <div v-if="paymentStatus.transactionId" class="mt-1 text-xs">
                  Transaction ID: {{ paymentStatus.transactionId }}
                </div>
              </div>
            </div>
          </div>

          <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
            <button
              type="button"
              class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-green-600 text-base font-medium text-white hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 sm:ml-3 sm:w-auto sm:text-sm disabled:opacity-50"
              @click="handlePayment"
              :disabled="!phoneNumberValid || loading || paymentStatus?.type === 'success'"
            >
              <span v-if="!loading">Request Payment</span>
              <span v-else class="flex items-center">
                <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                </svg>
                Processing...
              </span>
            </button>
            <button
              type="button"
              class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
              @click="closeModal"
              :disabled="loading"
            >
              {{ paymentStatus?.type === 'success' ? 'Close' : 'Cancel' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </Transition>
</template>

<script setup>
import { ref, computed } from 'vue'
import usePayment from '@/composables/usePayment'

const props = defineProps({
  show: Boolean,
  selectedPackage: Object,
  macAddress: String
})

const emit = defineEmits(['close', 'payment-success'])

const phoneNumber = ref('')
const { loading, error, paymentStatus, initiatePayment } = usePayment()

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
      message: 'Please enter a valid 9-digit Safaricom number'
    }
    return
  }

  const formattedPhone = `+254${phoneNumber.value}` // ✅ Add `+`
  const result = await initiatePayment({
    package: props.selectedPackage,
    phoneNumber: formattedPhone,
    macAddress: props.macAddress || 'D6:D2:52:1C:90:71'
  })

  if (result.success) {
    emit('payment-success', {
      transactionId: result.data.CheckoutRequestID,
      amount: props.selectedPackage.price
    })
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
