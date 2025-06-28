<template>
  <div class="min-h-screen flex flex-col">
    <!-- Main Scrollable Content -->
    <div class="flex-1 overflow-y-auto">
      <div class="container mx-auto p-4 sm:p-6 lg:p-8">
        <h1
          class="text-2xl sm:text-3xl lg:text-4xl font-bold text-gray-900 text-center mb-6 sm:mb-8 lg:mb-12"
        >
          Choose Your WiFi Package
        </h1>

        <!-- Loading State -->
        <div v-if="loadingPackages" class="flex justify-center items-center py-12">
          <div
            class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-indigo-600"
          ></div>
        </div>

        <!-- Error State -->
        <div v-else-if="packagesError" class="text-center py-8">
          <div class="inline-flex items-center px-4 py-2 rounded-md bg-red-100 text-red-700">
            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
              />
            </svg>
            {{ packagesError }}
          </div>
          <button
            @click="fetchPackages"
            class="mt-4 px-4 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 transition-colors"
          >
            Retry
          </button>
        </div>

        <!-- Package List -->
        <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">
          <div
            v-for="pkg in packages"
            :key="pkg.id"
            class="bg-white rounded-lg shadow-md p-4 sm:p-6 hover:shadow-lg transition-all duration-300 cursor-pointer"
            :class="{
              'ring-2 ring-indigo-600 scale-[1.02]': selectedPackage === pkg.id,
              'opacity-75': loadingPackages,
            }"
            @click="selectPackage(pkg.id)"
            role="button"
            tabindex="0"
            @keydown.enter="selectPackage(pkg.id)"
            aria-label="Select package"
          >
            <div class="flex flex-col h-full">
              <h2 class="text-lg sm:text-xl lg:text-2xl font-semibold text-gray-900">
                {{ pkg.name }}
              </h2>
              <p class="text-gray-500 text-sm sm:text-base mt-1">{{ pkg.description }}</p>

              <div class="mt-auto pt-4">
                <p class="text-lg sm:text-xl font-bold text-indigo-600">
                  KSH {{ parseFloat(pkg.price).toFixed(2) }}
                </p>
                <p class="text-sm sm:text-base text-gray-500">
                  {{ pkg.duration_hours }} hours access
                </p>

                <button
                  class="mt-4 w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700 transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:bg-gray-400 disabled:cursor-not-allowed"
                  :disabled="selectedPackage === pkg.id || loadingPackages"
                >
                  <span v-if="selectedPackage === pkg.id">
                    <svg
                      class="inline w-5 h-5 mr-1"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M5 13l4 4L19 7"
                      />
                    </svg>
                    Selected
                  </span>
                  <span v-else>Select</span>
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment Modal -->
      <div
        v-if="selectedPackage"
        class="fixed inset-0 bg-gray-800 bg-opacity-60 flex items-center justify-center z-50 transition-opacity duration-300"
        :class="{ 'opacity-0 pointer-events-none': !showModal, 'opacity-100': showModal }"
        @click.self="closeModal"
        role="dialog"
        aria-modal="true"
        aria-labelledby="payment-modal-title"
      >
        <div
          class="bg-white rounded-lg shadow-xl p-4 sm:p-6 max-w-md w-full mx-4 transform transition-all duration-300"
          :class="{ 'translate-y-4 opacity-0': !showModal, 'translate-y-0 opacity-100': showModal }"
          @keydown.esc="closeModal"
        >
          <div class="flex justify-between items-center mb-4">
            <h2 id="payment-modal-title" class="text-xl sm:text-2xl font-semibold text-gray-900">
              Enter Payment Details
            </h2>
            <button
              @click="closeModal"
              class="text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 rounded-full p-1 transition-colors"
              aria-label="Close"
              :disabled="loading"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
            </button>
          </div>

          <!-- Selected Package Details -->
          <div class="mb-6 p-4 bg-gray-50 rounded-md border border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ selectedPackageDetails.name }}</h3>
            <p class="text-gray-500 text-sm sm:text-base mt-1">
              {{ selectedPackageDetails.description }}
            </p>
            <div class="flex justify-between items-center mt-2">
              <p class="text-base font-bold text-indigo-600">
                KSH {{ parseFloat(selectedPackageDetails.price).toFixed(2) }}
              </p>
              <p class="text-sm text-gray-500">{{ selectedPackageDetails.duration_hours }} hours</p>
            </div>
          </div>

          <!-- Payment Form -->
          <form @submit.prevent="initiatePayment" class="space-y-4">
            <div>
              <label
                for="phone_number"
                class="block text-sm sm:text-base font-medium text-gray-700"
              >
                Phone Number (M-Pesa)
              </label>
              <div class="mt-1 flex rounded-md shadow-sm">
                <span
                  class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 sm:text-base"
                >
                  +254
                </span>
                <input
                  v-model="phoneNumberLocal"
                  type="tel"
                  id="phone_number"
                  class="flex-1 min-w-0 block w-full rounded-none rounded-r-md border border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-base p-2 sm:p-3 bg-white text-gray-900 disabled:bg-gray-100"
                  placeholder="700123456"
                  required
                  @input="handlePhoneInput"
                  pattern="[0-9]{9}"
                  title="Please enter 9 digits after +254"
                  :disabled="loading"
                />
              </div>
              <p class="mt-1 text-xs text-gray-500">Enter your M-Pesa registered number</p>
            </div>

            <div class="flex justify-end space-x-3 pt-2">
              <button
                type="button"
                @click="closeModal"
                class="py-2 px-4 border border-gray-300 text-gray-700 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-colors disabled:opacity-50"
                :disabled="loading"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="py-2 px-4 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-colors disabled:bg-indigo-400 disabled:cursor-not-allowed"
                :disabled="loading || !isValidPhoneNumber"
              >
                <span v-if="loading">
                  <svg
                    class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline"
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
                    ></circle>
                    <path
                      class="opacity-75"
                      fill="currentColor"
                      d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                  </svg>
                  Processing...
                </span>
                <span v-else>Pay Now</span>
              </button>
            </div>
          </form>

          <!-- Status Messages -->
          <div
            v-if="error || message"
            class="mt-4 rounded-md p-3"
            :class="{
              'bg-red-50 text-red-700': error,
              'bg-green-50 text-green-700': message,
            }"
          >
            <div class="flex items-center">
              <svg
                v-if="error"
                class="w-5 h-5 mr-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                />
              </svg>
              <svg
                v-if="message"
                class="w-5 h-5 mr-2"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                />
              </svg>
              <p class="text-sm sm:text-base">{{ error || message }}</p>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Sticky Footer -->
    <footer class="bg-gray-100 text-center text-gray-600 py-4 text-sm">
      © {{ new Date().getFullYear() }} TraidNet Hotspot. All rights reserved.
    </footer>
  </div>
</template>

<script>
import axios from 'axios'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

export default {
  data() {
    return {
      packages: [],
      selectedPackage: null,
      phoneNumberLocal: '',
      phoneNumber: '+254',
      macAddress: 'D6:D2:52:1C:90:71', // Default MAC address
      loading: false,
      loadingPackages: true,
      packagesError: null,
      error: null,
      message: null,
      showModal: false,
      transactionId: null,
      echo: null,
    }
  },
  computed: {
    selectedPackageDetails() {
      return this.packages.find((pkg) => pkg.id === this.selectedPackage) || {}
    },
    isValidPhoneNumber() {
      return /^\+254[0-9]{9}$/.test(this.phoneNumber)
    },
  },
  watch: {
    selectedPackage(newVal) {
      this.showModal = !!newVal
      if (newVal) {
        this.resetForm()
      }
    },
    phoneNumberLocal(newVal) {
      this.phoneNumber = '+254' + newVal
    },
  },
  async created() {
    await this.fetchPackages()
    this.setupWebSocket()
  },
  beforeUnmount() {
    this.cleanupWebSocket()
  },
  methods: {
    async fetchPackages() {
      this.loadingPackages = true
      this.packagesError = null
      try {
        const response = await axios.get('/api/packages')
        this.packages = response.data
      } catch (error) {
        console.error('Failed to load packages:', error)
        this.packagesError = 'Failed to load packages. Please try again later.'
      } finally {
        this.loadingPackages = false
      }
    },
    selectPackage(id) {
      if (this.loadingPackages) return
      this.selectedPackage = id
    },
    closeModal() {
      if (!this.loading) {
        this.cleanupWebSocket()
        this.selectedPackage = null
        this.showModal = false
        this.resetForm()
      }
    },
    resetForm() {
      this.phoneNumber = '+254'
      this.phoneNumberLocal = ''
      this.error = null
      this.message = null
      this.transactionId = null
    },
    handlePhoneInput(event) {
      let value = event.target.value.replace(/[^0-9]/g, '')
      if (value.startsWith('0')) value = value.substring(1)
      if (value.length > 9) value = value.substring(0, 9)
      this.phoneNumberLocal = value
    },
    async initiatePayment() {
      this.loading = true
      this.error = null
      this.message = null

      try {
        const response = await axios.post('/api/payments/initiate', {
          package_id: this.selectedPackage,
          phone_number: this.phoneNumber,
          mac_address: this.macAddress,
        })

        if (response.data.success) {
          this.transactionId = response.data.transaction_id
          this.message = 'Payment initiated. Please complete the STK push on your phone.'
          this.setupWebSocket()
        } else {
          this.error = response.data.message || 'Payment initiation failed. Please try again.'
        }
      } catch (error) {
        console.error('Payment error:', error)
        this.error =
          error.response?.data?.message ||
          error.message ||
          'An error occurred during payment. Please try again.'
      } finally {
        this.loading = false
      }
    },
    setupWebSocket() {
      if (!this.transactionId) return

      this.cleanupWebSocket()

      window.Pusher = Pusher
      this.echo = new Echo({
        broadcaster: 'pusher',
        key: process.env.VUE_APP_PUSHER_APP_KEY,
        cluster: process.env.VUE_APP_PUSHER_APP_CLUSTER,
        forceTLS: true,
        enabledTransports: ['ws', 'wss'],
      })

      this.echo.private(`payment.${this.transactionId}`).listen('.payment.processed', (e) => {
        if (e.status === 'completed') {
          this.message = `Payment successful! Your voucher code is: ${e.voucher}`
          setTimeout(() => {
            window.location.href = `/payment-success?voucher=${encodeURIComponent(e.voucher)}`
          }, 3000)
        } else {
          this.error = e.message || 'Payment failed. Please try again.'
        }
      })
    },
    cleanupWebSocket() {
      if (this.echo && this.transactionId) {
        this.echo.leave(`payment.${this.transactionId}`)
      }
    },
  },
}
</script>

<style scoped>
/* Smooth transitions for modal */
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.3s;
}
.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

/* Package card hover effect */
.package-card:hover {
  transform: translateY(-2px);
}
</style>
