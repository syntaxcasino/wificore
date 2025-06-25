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

        <!-- Debug Output -->
        <div v-if="packages.length === 0" class="text-center text-red-500">
          No packages available. Check console for details.
        </div>

        <!-- Package List -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 sm:gap-6 lg:gap-8">
          <div
            v-for="pkg in packages"
            :key="pkg.id"
            class="bg-white rounded-lg shadow-md p-4 sm:p-6 hover:shadow-lg transition-shadow duration-300 cursor-pointer"
            :class="{ 'border-2 border-indigo-600': selectedPackage === pkg.id }"
            @click="selectPackage(pkg.id)"
            role="button"
            tabindex="0"
            @keydown.enter="selectPackage(pkg.id)"
            aria-label="Select package"
          >
            <h2 class="text-lg sm:text-xl lg:text-2xl font-semibold text-gray-900">
              {{ pkg.name }}
            </h2>
            <p class="text-gray-500 text-sm sm:text-base">{{ pkg.description }}</p>
            <p class="text-lg sm:text-xl font-bold mt-2 text-indigo-600">
              KSH {{ parseFloat(pkg.price).toFixed(2) }}
            </p>
            <p class="text-sm sm:text-base text-gray-500">{{ pkg.duration_hours }} hours</p>
            <button
              class="mt-4 w-full bg-indigo-600 text-white py-2 px-4 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2"
              :disabled="selectedPackage === pkg.id"
            >
              {{ selectedPackage === pkg.id ? 'Selected' : 'Select' }}
            </button>
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
          class="bg-white rounded-lg shadow-xl p-4 sm:p-6 max-w-md w-full mx-4 transform transition-transform duration-300"
          :class="{ 'translate-y-4': !showModal, 'translate-y-0': showModal }"
          @keydown.esc="closeModal"
        >
          <div class="flex justify-between items-center mb-4">
            <h2 id="payment-modal-title" class="text-xl sm:text-2xl font-semibold text-gray-900">
              Enter Payment Details
            </h2>
            <button
              @click="closeModal"
              class="text-gray-500 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-gray-500 rounded-full p-1"
              aria-label="Close"
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
          <div class="mb-6 p-4 bg-gray-100 rounded-md border border-gray-200">
            <h3 class="text-lg font-semibold text-gray-900">{{ selectedPackageDetails.name }}</h3>
            <p class="text-gray-500 text-sm sm:text-base">
              {{ selectedPackageDetails.description }}
            </p>
            <p class="text-base font-bold text-indigo-600">
              KSH {{ parseFloat(selectedPackageDetails.price).toFixed(2) }}
            </p>
            <p class="text-sm text-gray-500">{{ selectedPackageDetails.duration_hours }} hours</p>
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
                  class="flex-1 block w-full rounded-none rounded-r-md border border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 sm:text-base p-2 sm:p-3 bg-white text-gray-900"
                  placeholder="e.g., 700123456"
                  required
                  @input="handlePhoneInput"
                  pattern="[0-9]{9}"
                  title="Please enter 9 digits after +254"
                />
              </div>
            </div>

            <div class="flex justify-end space-x-4">
              <button
                type="button"
                @click="closeModal"
                class="py-2 px-4 bg-teal-500 text-white rounded-md hover:bg-teal-600 focus:outline-none focus:ring-2 focus:ring-teal-500"
              >
                Cancel
              </button>
              <button
                type="submit"
                class="py-2 px-4 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-600 disabled:bg-gray-400"
                :disabled="loading || !isValidPhoneNumber"
              >
                {{ loading ? 'Processing...' : 'Pay Now' }}
              </button>
            </div>
          </form>
          <p v-if="error" class="mt-4 text-red-500 text-sm sm:text-base text-center">{{ error }}</p>
        </div>
      </div>
    </div>

    <!-- Sticky Footer -->
    <footer class="bg-gray-100 text-center text-gray-600 py-4">
      &copy; {{ new Date().getFullYear() }} TraidNet Hotspot. All rights reserved.
    </footer>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  data() {
    return {
      packages: [],
      selectedPackage: null,
      phoneNumberLocal: '',
      phoneNumber: '+254',
      loading: false,
      error: null,
      showModal: false,
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
        this.phoneNumber = '+254'
        this.phoneNumberLocal = ''
        this.error = null
      }
    },
    phoneNumberLocal(newVal) {
      this.phoneNumber = '+254' + newVal
    },
  },
  async created() {
    try {
      const response = await axios.get('/api/packages')
      this.packages = response.data
    } catch (error) {
      console.error('API Error:', error)
      this.error = 'Failed to load packages. Please try again.'
    }
  },
  methods: {
    selectPackage(id) {
      this.selectedPackage = id
    },
    closeModal() {
      this.selectedPackage = null
      this.showModal = false
      this.phoneNumber = '+254'
      this.phoneNumberLocal = ''
      this.error = null
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
      try {
        const response = await axios.post('/api/payments/initiate', {
          package_id: this.selectedPackage,
          phone_number: this.phoneNumber,
          mac_address: 'D6:D2:52:1C:90:71',
        })
        if (response.data.status === 'success') {
          this.closeModal()
          this.$router.push('/payment-success')
        } else {
          this.error = response.data.message || 'Payment initiation failed.'
        }
      } catch (error) {
        console.error('Payment Error:', error.response?.data || error.message)
        this.error =
          error.response?.data?.message || 'An error occurred during payment. Please try again.'
      } finally {
        this.loading = false
      }
    },
  },
}
</script>
