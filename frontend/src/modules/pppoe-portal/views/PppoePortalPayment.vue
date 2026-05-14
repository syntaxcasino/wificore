<template>
  <div class="min-h-screen bg-gradient-to-br from-red-600 via-orange-500 to-pink-500">
    <!-- Header -->
    <header class="bg-white/10 backdrop-blur-sm">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-white rounded-lg flex items-center justify-center">
              <i class="fas fa-exclamation-triangle text-red-600"></i>
            </div>
            <h1 class="text-xl font-bold text-white">Payment Required</h1>
          </div>
          <button
            @click="logout"
            class="text-white/80 hover:text-white transition-colors"
          >
            <i class="fas fa-sign-out-alt"></i>
          </button>
        </div>
      </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Status Card -->
      <div class="bg-white rounded-2xl shadow-xl overflow-hidden mb-6">
        <div class="bg-red-50 p-6 border-b border-red-100">
          <div class="flex items-center gap-4">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
              <i class="fas fa-lock text-red-600 text-2xl"></i>
            </div>
            <div>
              <h2 class="text-xl font-bold text-red-800">Account {{ statusText }}</h2>
              <p class="text-red-700 mt-1">
                Your internet access is currently restricted. Please make a payment to restore service.
              </p>
            </div>
          </div>
        </div>

        <div class="p-6">
          <!-- Account Info -->
          <div class="grid grid-cols-2 gap-4 mb-6">
            <div class="bg-gray-50 rounded-lg p-4">
              <p class="text-sm text-gray-500">Account Number</p>
              <p class="font-semibold text-gray-900">{{ user?.account_number }}</p>
            </div>
            <div class="bg-gray-50 rounded-lg p-4">
              <p class="text-sm text-gray-500">Current Balance</p>
              <p class="font-semibold text-gray-900">KES {{ user?.balance || 0 }}</p>
            </div>
          </div>

          <!-- Payment Methods Tabs -->
          <div class="border-b border-gray-200 mb-6">
            <nav class="flex gap-4">
              <button
                @click="activeTab = 'mpesa'"
                :class="[
                  'py-2 px-4 font-medium border-b-2 transition-colors',
                  activeTab === 'mpesa' 
                    ? 'border-green-500 text-green-600' 
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                ]"
              >
                <i class="fas fa-mobile-alt mr-2"></i>
                M-Pesa
              </button>
              <button
                @click="activeTab = 'voucher'"
                :class="[
                  'py-2 px-4 font-medium border-b-2 transition-colors',
                  activeTab === 'voucher' 
                    ? 'border-indigo-500 text-indigo-600' 
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                ]"
              >
                <i class="fas fa-ticket-alt mr-2"></i>
                Voucher
              </button>
              <button
                @click="activeTab = 'paybill'"
                :class="[
                  'py-2 px-4 font-medium border-b-2 transition-colors',
                  activeTab === 'paybill'
                    ? 'border-blue-500 text-blue-600'
                    : 'border-transparent text-gray-500 hover:text-gray-700'
                ]"
              >
                <i class="fas fa-building-columns mr-2"></i>
                Paybill
              </button>
            </nav>
          </div>

          <!-- M-Pesa Payment Form -->
          <div v-if="activeTab === 'mpesa'" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Phone Number
              </label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">254</span>
                <input
                  v-model="mpesaForm.phone"
                  type="tel"
                  placeholder="7XX XXX XXX"
                  maxlength="9"
                  required
                  class="w-full pl-14 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Amount (KES)
              </label>
              <div class="relative">
                <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">KES</span>
                <input
                  v-model="mpesaForm.amount"
                  type="number"
                  min="10"
                  max="100000"
                  required
                  class="w-full pl-14 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>
            </div>

            <!-- Quick Amounts -->
            <div class="flex gap-2 flex-wrap">
              <button
                v-for="amount in [50, 100, 200, 500, 1000]"
                :key="amount"
                type="button"
                @click="mpesaForm.amount = amount"
                class="px-4 py-2 bg-gray-100 hover:bg-gray-200 rounded-full text-sm font-medium text-gray-700 transition-colors"
              >
                {{ amount }}
              </button>
            </div>

            <button
              @click="handleMpesaPayment"
              :disabled="isLoading"
              class="w-full py-4 bg-green-600 hover:bg-green-700 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <i v-if="isLoading" class="fas fa-spinner fa-spin"></i>
              <span>{{ isLoading ? 'Processing...' : 'Pay with M-Pesa' }}</span>
            </button>

            <p class="text-sm text-gray-500 text-center">
              You will receive an M-Pesa prompt on your phone. Enter your PIN to complete payment.
            </p>
          </div>

          <!-- Voucher Form -->
          <div v-else-if="activeTab === 'voucher'" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Voucher Code
              </label>
              <input
                v-model="voucherForm.code"
                type="text"
                placeholder="Enter voucher code (e.g., WIFI-XXXX-XXXX)"
                required
                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 uppercase text-center tracking-wider font-mono"
              />
            </div>

            <button
              @click="handleVoucherRedeem"
              :disabled="isLoading"
              class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white font-bold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
            >
              <i v-if="isLoading" class="fas fa-spinner fa-spin"></i>
              <span>{{ isLoading ? 'Redeeming...' : 'Redeem Voucher' }}</span>
            </button>

            <p class="text-sm text-gray-500 text-center">
              Enter the voucher code from your prepaid card or receipt.
            </p>
          </div>

          <!-- Paybill Instructions -->
          <div v-else class="space-y-4">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <h4 class="font-semibold text-blue-900 mb-2">Pay via M-Pesa Paybill</h4>
              <p class="text-sm text-blue-800">
                Use these details in M-Pesa: `Lipa na M-Pesa` -> `Pay Bill`.
              </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-500">Business Number</p>
                <p class="font-semibold text-gray-900">{{ paybillInfo.paybill_number || 'Not configured' }}</p>
              </div>
              <div class="bg-gray-50 rounded-lg p-4">
                <p class="text-sm text-gray-500">Account Number</p>
                <p class="font-semibold text-gray-900">{{ paybillInfo.account_number || user?.account_number }}</p>
              </div>
            </div>

            <div class="bg-gray-50 rounded-lg p-4">
              <p class="text-sm text-gray-500">Suggested Amount</p>
              <p class="font-semibold text-gray-900">KES {{ paybillInfo.suggested_amount || 0 }}</p>
            </div>

            <ol class="list-decimal list-inside text-sm text-gray-700 space-y-1">
              <li v-for="(step, idx) in paybillSteps" :key="idx">{{ step }}</li>
            </ol>
          </div>
        </div>
      </div>

      <!-- Help Section -->
      <div class="bg-white/10 backdrop-blur-sm rounded-xl p-6 text-white">
        <h3 class="font-semibold mb-2 flex items-center gap-2">
          <i class="fas fa-question-circle"></i>
          Need Help?
        </h3>
        <p class="text-white/80 text-sm">
          If you're having trouble making a payment, please contact your service provider.
          Your internet will be automatically restored once payment is confirmed.
        </p>
      </div>
    </main>

    <!-- Success Modal -->
    <Teleport to="body">
      <div v-if="showSuccess" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 bg-black/50">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-8 text-center">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
              <i class="fas fa-check-circle text-green-600 text-4xl"></i>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 mb-2">Payment Successful!</h3>
            <p class="text-gray-600 mb-6">
              Your payment has been received. Your internet access will be restored within 1 minute.
            </p>
            <button
              @click="continueToDashboard"
              class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors"
            >
              Continue to Dashboard
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { usePppoePortal } from '../composables/usePppoePortal.js';

const router = useRouter();
const route = useRoute();
const {
  user,
  isLoading,
  logout,
  initiateMpesaPayment,
  redeemVoucher,
  checkPaymentStatus,
  fetchPaymentInstructions,
} = usePppoePortal();

const activeTab = ref('mpesa');
const showSuccess = ref(false);
const successMessage = ref('');

const mpesaForm = ref({
  phone: '',
  amount: '',
});

const voucherForm = ref({
  code: '',
});
const paybillInfo = ref({
  paybill_number: null,
  account_number: null,
  suggested_amount: 0,
  instructions: [],
});

const statusText = computed(() => {
  const reasons = {
    'unpaid': 'Unpaid',
    'expired': 'Expired',
    'suspended': 'Suspended',
  };
  return reasons[route.query.reason] || 'Restricted';
});

async function handleMpesaPayment() {
  try {
    const phone = '254' + mpesaForm.value.phone.replace(/\D/g, '');
    const result = await initiateMpesaPayment(phone, mpesaForm.value.amount);

    // Poll for payment status
    if (result?.data?.transaction_id) {
      pollPaymentStatus(result.data.transaction_id);
    }
  } catch (err) {
    console.error('Payment failed:', err);
  }
}

async function handleVoucherRedeem() {
  try {
    await redeemVoucher(voucherForm.value.code);
    showSuccess.value = true;
  } catch (err) {
    console.error('Voucher redemption failed:', err);
  }
}

async function pollPaymentStatus(transactionId) {
  // Simple polling - in production, use WebSocket or SSE
  let attempts = 0;
  const maxAttempts = 30; // 5 minutes at 10 second intervals
  
  const check = async () => {
    attempts++;
    try {
      const status = await checkPaymentStatus(transactionId);
      if (status?.status === 'completed') {
        showSuccess.value = true;
        return;
      }
      if (attempts >= maxAttempts) {
        return;
      }
      setTimeout(check, 10000);
    } catch (err) {
      console.error('Payment status check failed:', err);
    }
  };
  
  check();
}

function continueToDashboard() {
  showSuccess.value = false;
  router.push('/portal/dashboard');
}

const paybillSteps = computed(() => {
  if (Array.isArray(paybillInfo.value.instructions) && paybillInfo.value.instructions.length > 0) {
    return paybillInfo.value.instructions;
  }

  return [
    'Open M-Pesa on your phone',
    'Select Lipa na M-Pesa, then Pay Bill',
    `Enter Business Number ${paybillInfo.value.paybill_number || 'from your provider'}`,
    `Enter Account Number ${paybillInfo.value.account_number || user.value?.account_number || ''}`,
    'Enter amount and M-Pesa PIN to confirm',
  ];
});

onMounted(async () => {
  try {
    const response = await fetchPaymentInstructions();
    if (response?.paybill) {
      paybillInfo.value = response.paybill;
    }
  } catch (err) {
    console.error('Failed to load paybill instructions:', err);
  }
});
</script>
