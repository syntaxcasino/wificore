<template>
  <div :class="[
    'min-h-screen flex items-center justify-center p-4',
    isCaptive ? 'bg-gradient-to-br from-red-600 via-orange-500 to-pink-500' : 'bg-gradient-to-br from-indigo-600 via-purple-600 to-pink-500'
  ]">
    <div class="w-full max-w-md">
      <!-- Logo and Header -->
      <div class="text-center mb-8">
        <div class="w-20 h-20 bg-white rounded-full flex items-center justify-center mx-auto mb-4 shadow-xl">
          <i :class="[
            'text-4xl',
            isCaptive ? 'fas fa-exclamation-triangle text-red-600' : 'fas fa-wifi text-indigo-600'
          ]"></i>
        </div>
        <h1 class="text-3xl font-bold text-white mb-2">
          {{ isCaptive ? 'Payment Required' : 'Customer Portal' }}
        </h1>
        <p class="text-white/80">
          {{ isCaptive ? 'Your account needs a top-up to continue browsing' : 'Access your account, check usage, and make payments' }}
        </p>
      </div>

      <!-- Captive Mode Alert -->
      <div v-if="isCaptive" class="bg-white/90 backdrop-blur rounded-xl shadow-xl p-4 mb-4">
        <div class="flex items-start gap-3">
          <div class="w-10 h-10 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fas fa-lock text-red-600"></i>
          </div>
          <div>
            <h3 class="font-semibold text-red-800">Internet Access Restricted</h3>
            <p class="text-sm text-red-700 mt-1">
              Your account is <strong>{{ captiveReason }}</strong>. 
              Please sign in below to make a payment and restore your internet access.
            </p>
          </div>
        </div>
      </div>

      <!-- Login Card -->
      <div class="bg-white rounded-2xl shadow-2xl p-8">
        <h2 class="text-2xl font-semibold text-gray-800 mb-6 text-center">Sign In to Pay</h2>

        <!-- Error Alert -->
        <div v-if="error" class="mb-4 p-4 bg-red-50 border border-red-200 rounded-lg flex items-start gap-3">
          <i class="fas fa-exclamation-circle text-red-500 mt-0.5"></i>
          <p class="text-sm text-red-700">{{ error }}</p>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-5">
          <!-- Account Number -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Account Number
            </label>
            <div class="relative">
              <i class="fas fa-user absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
              <input
                v-model="form.accountNumber"
                type="text"
                required
                :placeholder="isCaptive ? 'Found on your invoice/receipt' : 'Enter your account number'"
                class="w-full pl-11 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
              />
            </div>
          </div>

          <!-- Portal Password -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Portal Password
            </label>
            <div class="relative">
              <i class="fas fa-lock absolute left-4 top-1/2 -translate-y-1/2 text-gray-400"></i>
              <input
                v-model="form.portalPassword"
                :type="showPassword ? 'text' : 'password'"
                required
                placeholder="Enter your portal password"
                class="w-full pl-11 pr-12 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 transition-all"
              />
              <button
                type="button"
                @click="showPassword = !showPassword"
                class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600"
              >
                <i :class="showPassword ? 'fas fa-eye-slash' : 'fas fa-eye'"></i>
              </button>
            </div>
            <p class="mt-1 text-xs text-gray-500">
              This is different from your PPPoE connection password
            </p>
          </div>

          <!-- Submit Button -->
          <button
            type="submit"
            :disabled="isLoading"
            :class="[
              'w-full py-3 px-4 text-white font-semibold rounded-lg transition-all disabled:opacity-50 disabled:cursor-not-allowed flex items-center justify-center gap-2',
              isCaptive ? 'bg-red-600 hover:bg-red-700' : 'bg-indigo-600 hover:bg-indigo-700'
            ]"
          >
            <i v-if="isLoading" class="fas fa-spinner fa-spin"></i>
            <span>{{ isLoading ? 'Signing In...' : (isCaptive ? 'Sign In & Pay Now' : 'Sign In') }}</span>
          </button>
        </form>

        <!-- Help Links -->
        <div class="mt-6 pt-6 border-t border-gray-200 text-center space-y-2">
          <p class="text-sm text-gray-600">
            Forgot your password? Contact your service provider
          </p>
          <p v-if="isCaptive" class="text-xs text-gray-500">
            <i class="fas fa-info-circle mr-1"></i>
            After payment, your internet will be restored within 1 minute
          </p>
        </div>
      </div>

      <!-- Footer -->
      <div class="text-center mt-8 text-white/70 text-sm">
        <p>&copy; {{ new Date().getFullYear() }} All rights reserved</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { reactive, ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { usePppoePortal } from '../composables/usePppoePortal.js';

const router = useRouter();
const route = useRoute();
const { login, isLoading, error, clearError } = usePppoePortal();

const showPassword = ref(false);

// Captive mode detection
const isCaptive = computed(() => route.query.captive === '1' || route.query.redirect === 'unpaid');
const captiveReason = computed(() => {
  const reasons = {
    'unpaid': 'unpaid/expired',
    'expired': 'expired',
    'suspended': 'suspended',
  };
  return reasons[route.query.reason] || 'unpaid';
});

const form = reactive({
  accountNumber: '',
  portalPassword: '',
});

onMounted(() => {
  // Auto-fill account number if provided in query (from router redirect)
  if (route.query.account) {
    form.accountNumber = route.query.account;
  }
});

async function handleLogin() {
  clearError();
  
  const result = await login(form.accountNumber, form.portalPassword);
  
  if (result.success) {
    // Check if user account status requires payment
    const userStatus = result.user?.status;
    
    if (isCaptive.value || userStatus === 'suspended' || userStatus === 'expired') {
      // Redirect to payment page with captive mode
      router.push({
        path: '/portal/payment',
        query: { 
          captive: '1', 
          reason: userStatus || captiveReason.value 
        }
      });
    } else {
      router.push('/portal/dashboard');
    }
  }
}
</script>

<style scoped>
/* Custom animations */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.bg-white {
  animation: fadeIn 0.3s ease-out;
}
</style>
