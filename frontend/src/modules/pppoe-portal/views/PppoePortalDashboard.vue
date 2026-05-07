<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center">
              <i class="fas fa-wifi text-white text-lg"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900">Customer Portal</h1>
          </div>
          
          <div class="flex items-center gap-4">
            <span class="text-sm text-gray-600 hidden sm:block">
              Welcome, <strong>{{ user?.full_name || user?.username }}</strong>
            </span>
            <button
              @click="handleLogout"
              class="p-2 text-gray-500 hover:text-red-600 transition-colors"
              title="Logout"
            >
              <i class="fas fa-sign-out-alt text-lg"></i>
            </button>
          </div>
        </div>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Loading State -->
      <div v-if="isLoading && !dashboardData" class="flex items-center justify-center h-64">
        <div class="text-center">
          <i class="fas fa-spinner fa-spin text-3xl text-indigo-600 mb-4"></i>
          <p class="text-gray-600">Loading your dashboard...</p>
        </div>
      </div>

      <template v-else-if="dashboardData">
        <!-- Account Status Banner -->
        <div 
          :class="[
            'rounded-xl p-4 mb-6 flex items-center gap-4',
            accountStatus.class
          ]"
        >
          <div class="w-12 h-12 rounded-full bg-white/20 flex items-center justify-center">
            <i :class="['fas', accountStatus.icon, 'text-xl']"></i>
          </div>
          <div class="flex-1">
            <h2 class="font-semibold text-lg">{{ accountStatus.title }}</h2>
            <p class="text-sm opacity-90">{{ accountStatus.message }}</p>
          </div>
          <button
            v-if="user?.status === 'active'"
            @click="showPaymentModal = true"
            class="px-4 py-2 bg-white text-gray-900 rounded-lg font-medium hover:bg-gray-100 transition-colors"
          >
            <i class="fas fa-plus-circle mr-2"></i>
            Top Up
          </button>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <!-- Current Session -->
          <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-signal text-green-600 text-xl"></i>
              </div>
              <span 
                :class="[
                  'px-2 py-1 text-xs font-medium rounded-full',
                  dashboardData.current_session ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'
                ]"
              >
                {{ dashboardData.current_session ? 'Online' : 'Offline' }}
              </span>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Current Session</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              {{ dashboardData.current_session ? dashboardData.current_session.duration_formatted : '--' }}
            </p>
            <p v-if="dashboardData.current_session" class="text-xs text-gray-500 mt-2">
              IP: {{ dashboardData.current_session.ip_address }}
            </p>
          </div>

          <!-- Balance -->
          <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-wallet text-blue-600 text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Account Balance</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              KES {{ formatNumber(dashboardData.user?.balance || 0) }}
            </p>
            <p class="text-xs text-gray-500 mt-2">
              Expires: {{ formatDate(dashboardData.user?.expiration_date) }}
            </p>
          </div>

          <!-- Data Usage -->
          <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-chart-line text-purple-600 text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">30-Day Usage</h3>
            <p class="text-2xl font-bold text-gray-900 mt-1">
              {{ dashboardData.usage_stats?.total_usage_formatted || '0 B' }}
            </p>
            <p class="text-xs text-gray-500 mt-2">
              {{ dashboardData.usage_stats?.total_sessions || 0 }} sessions
            </p>
          </div>

          <!-- Package -->
          <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
              <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                <i class="fas fa-box text-orange-600 text-xl"></i>
              </div>
            </div>
            <h3 class="text-gray-500 text-sm font-medium">Current Plan</h3>
            <p class="text-lg font-bold text-gray-900 mt-1 truncate">
              {{ dashboardData.user?.package?.name || 'No Plan' }}
            </p>
            <p v-if="dashboardData.user?.package" class="text-xs text-gray-500 mt-2">
              {{ dashboardData.user.package.download_speed }} ↓ / {{ dashboardData.user.package.upload_speed }} ↑
            </p>
          </div>
        </div>

        <!-- Main Content Grid -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
          <!-- Left Column: Session History -->
          <div class="lg:col-span-2 space-y-6">
            <!-- Current Session Details -->
            <div v-if="dashboardData.current_session" class="bg-white rounded-xl shadow-sm overflow-hidden">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                  <i class="fas fa-info-circle text-indigo-500"></i>
                  Session Details
                </h3>
              </div>
              <div class="p-6">
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                  <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <i class="fas fa-download text-green-500 mb-2"></i>
                    <p class="text-xs text-gray-500">Download</p>
                    <p class="font-semibold text-gray-900">{{ dashboardData.current_session.download_formatted }}</p>
                  </div>
                  <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <i class="fas fa-upload text-blue-500 mb-2"></i>
                    <p class="text-xs text-gray-500">Upload</p>
                    <p class="font-semibold text-gray-900">{{ dashboardData.current_session.upload_formatted }}</p>
                  </div>
                  <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <i class="fas fa-clock text-purple-500 mb-2"></i>
                    <p class="text-xs text-gray-500">Duration</p>
                    <p class="font-semibold text-gray-900">{{ dashboardData.current_session.duration_formatted }}</p>
                  </div>
                  <div class="text-center p-4 bg-gray-50 rounded-lg">
                    <i class="fas fa-network-wired text-orange-500 mb-2"></i>
                    <p class="text-xs text-gray-500">IP Address</p>
                    <p class="font-semibold text-gray-900 text-xs">{{ dashboardData.current_session.ip_address }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Recent Sessions -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
              <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                  <i class="fas fa-history text-indigo-500"></i>
                  Recent Sessions
                </h3>
                <button
                  @click="loadSessionHistory"
                  class="text-sm text-indigo-600 hover:text-indigo-700 font-medium"
                >
                  View All
                </button>
              </div>
              <div class="divide-y divide-gray-100">
                <div
                  v-for="session in recentSessions"
                  :key="session.id"
                  class="px-6 py-4 hover:bg-gray-50 transition-colors"
                >
                  <div class="flex items-center justify-between">
                    <div class="flex items-center gap-4">
                      <div 
                        :class="[
                          'w-2 h-2 rounded-full',
                          session.status === 'active' ? 'bg-green-500 animate-pulse' : 'bg-gray-400'
                        ]"
                      ></div>
                      <div>
                        <p class="font-medium text-gray-900">{{ formatDateTime(session.start_time) }}</p>
                        <p class="text-sm text-gray-500">{{ session.duration_formatted }}</p>
                      </div>
                    </div>
                    <div class="text-right">
                      <p class="font-medium text-gray-900">{{ session.total_formatted }}</p>
                      <p class="text-xs text-gray-500">{{ session.download_formatted }} ↓ {{ session.upload_formatted }} ↑</p>
                    </div>
                  </div>
                </div>
                <div v-if="recentSessions.length === 0" class="px-6 py-8 text-center text-gray-500">
                  <i class="fas fa-inbox text-4xl mb-3 opacity-30"></i>
                  <p>No recent sessions found</p>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Column: Quick Actions -->
          <div class="space-y-6">
            <!-- Payment Methods -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                  <i class="fas fa-credit-card text-indigo-500"></i>
                  Quick Top Up
                </h3>
              </div>
              <div class="p-6 space-y-3">
                <button
                  @click="showPaymentModal = true"
                  class="w-full py-3 px-4 bg-green-600 hover:bg-green-700 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2"
                >
                  <i class="fas fa-mobile-alt"></i>
                  M-Pesa Payment
                </button>
                <button
                  @click="showVoucherModal = true"
                  class="w-full py-3 px-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg font-medium transition-colors flex items-center justify-center gap-2"
                >
                  <i class="fas fa-ticket-alt"></i>
                  Redeem Voucher
                </button>
              </div>
            </div>

            <!-- Recent Payments -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
              <div class="px-6 py-4 border-b border-gray-200">
                <h3 class="font-semibold text-gray-900 flex items-center gap-2">
                  <i class="fas fa-receipt text-indigo-500"></i>
                  Recent Payments
                </h3>
              </div>
              <div class="divide-y divide-gray-100">
                <div
                  v-for="payment in dashboardData.recent_payments"
                  :key="payment.id"
                  class="px-6 py-3"
                >
                  <div class="flex items-center justify-between">
                    <div>
                      <p class="font-medium text-gray-900">KES {{ payment.amount }}</p>
                      <p class="text-xs text-gray-500">{{ formatDate(payment.created_at) }}</p>
                    </div>
                    <span
                      :class="[
                        'px-2 py-1 text-xs font-medium rounded-full',
                        payment.status === 'completed' ? 'bg-green-100 text-green-700' :
                        payment.status === 'pending' ? 'bg-yellow-100 text-yellow-700' :
                        'bg-red-100 text-red-700'
                      ]"
                    >
                      {{ payment.status }}
                    </span>
                  </div>
                </div>
                <div v-if="!dashboardData.recent_payments?.length" class="px-6 py-6 text-center text-gray-500 text-sm">
                  No recent payments
                </div>
              </div>
            </div>

            <!-- Support -->
            <div class="bg-indigo-50 rounded-xl p-6">
              <h3 class="font-semibold text-indigo-900 mb-2 flex items-center gap-2">
                <i class="fas fa-headset"></i>
                Need Help?
              </h3>
              <p class="text-sm text-indigo-700 mb-4">
                Contact your service provider for assistance with your account.
              </p>
              <div class="space-y-2 text-sm text-indigo-600">
                <p v-if="user?.email">
                  <i class="fas fa-envelope mr-2"></i>
                  {{ user.email }}
                </p>
                <p v-if="user?.phone">
                  <i class="fas fa-phone mr-2"></i>
                  {{ user.phone }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </template>
    </main>

    <!-- M-Pesa Payment Modal -->
    <Teleport to="body">
      <div v-if="showPaymentModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 bg-black/50">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">M-Pesa Payment</h3>
              <button @click="showPaymentModal = false" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>

            <form @submit.prevent="handleMpesaPayment" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Phone Number</label>
                <div class="relative">
                  <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">254</span>
                  <input
                    v-model="paymentForm.phone"
                    type="tel"
                    placeholder="7XX XXX XXX"
                    maxlength="9"
                    required
                    class="w-full pl-14 pr-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
                <p class="text-xs text-gray-500 mt-1">Format: 2547XX XXX XXX</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Amount (KES)</label>
                <div class="relative">
                  <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-500 font-medium">KES</span>
                  <input
                    v-model="paymentForm.amount"
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
                  @click="paymentForm.amount = amount"
                  class="px-3 py-1 bg-gray-100 hover:bg-gray-200 rounded-full text-sm font-medium text-gray-700 transition-colors"
                >
                  KES {{ amount }}
                </button>
              </div>

              <button
                type="submit"
                :disabled="paymentLoading"
                class="w-full py-3 bg-green-600 hover:bg-green-700 text-white font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
              >
                <i v-if="paymentLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ paymentLoading ? 'Processing...' : 'Pay with M-Pesa' }}</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Voucher Modal -->
    <Teleport to="body">
      <div v-if="showVoucherModal" class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4 bg-black/50">
          <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Redeem Voucher</h3>
              <button @click="showVoucherModal = false" class="text-gray-400 hover:text-gray-600">
                <i class="fas fa-times text-xl"></i>
              </button>
            </div>

            <form @submit.prevent="handleVoucherRedeem" class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Voucher Code</label>
                <input
                  v-model="voucherForm.code"
                  type="text"
                  placeholder="Enter voucher code"
                  required
                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 uppercase"
                />
                <p class="text-xs text-gray-500 mt-1">Enter the voucher code exactly as shown</p>
              </div>

              <button
                type="submit"
                :disabled="voucherLoading"
                class="w-full py-3 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold rounded-lg transition-colors flex items-center justify-center gap-2 disabled:opacity-50"
              >
                <i v-if="voucherLoading" class="fas fa-spinner fa-spin"></i>
                <span>{{ voucherLoading ? 'Redeeming...' : 'Redeem Voucher' }}</span>
              </button>
            </form>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Success Toast -->
    <Teleport to="body">
      <Transition
        enter-active-class="transition duration-300 ease-out"
        enter-from-class="transform translate-y-2 opacity-0"
        enter-to-class="transform translate-y-0 opacity-100"
        leave-active-class="transition duration-200 ease-in"
        leave-from-class="transform translate-y-0 opacity-100"
        leave-to-class="transform translate-y-2 opacity-0"
      >
        <div v-if="successMessage" class="fixed bottom-4 right-4 z-50">
          <div class="bg-green-600 text-white px-6 py-3 rounded-lg shadow-lg flex items-center gap-3">
            <i class="fas fa-check-circle"></i>
            <span>{{ successMessage }}</span>
          </div>
        </div>
      </Transition>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { usePppoePortal } from '../composables/usePppoePortal.js';

const router = useRouter();
const { 
  user, 
  isLoading, 
  logout, 
  fetchDashboard, 
  fetchSessionHistory,
  initiateMpesaPayment,
  redeemVoucher 
} = usePppoePortal();

const dashboardData = ref(null);
const recentSessions = ref([]);
const showPaymentModal = ref(false);
const showVoucherModal = ref(false);
const paymentLoading = ref(false);
const voucherLoading = ref(false);
const successMessage = ref('');

const paymentForm = ref({
  phone: '',
  amount: '',
});

const voucherForm = ref({
  code: '',
});

const accountStatus = computed(() => {
  const status = dashboardData.value?.user?.status;
  switch (status) {
    case 'active':
      return {
        class: 'bg-green-50 border border-green-200 text-green-800',
        icon: 'fa-check-circle',
        title: 'Account Active',
        message: 'Your internet service is active and running',
      };
    case 'suspended':
      return {
        class: 'bg-red-50 border border-red-200 text-red-800',
        icon: 'fa-exclamation-triangle',
        title: 'Account Suspended',
        message: 'Please make a payment to restore service',
      };
    case 'expired':
      return {
        class: 'bg-yellow-50 border border-yellow-200 text-yellow-800',
        icon: 'fa-clock',
        title: 'Account Expired',
        message: 'Your package has expired. Please renew',
      };
    default:
      return {
        class: 'bg-gray-50 border border-gray-200 text-gray-800',
        icon: 'fa-question-circle',
        title: 'Unknown Status',
        message: 'Please contact support',
      };
  }
});

async function loadDashboard() {
  try {
    dashboardData.value = await fetchDashboard();
    // Load last 5 sessions
    const history = await fetchSessionHistory(7);
    recentSessions.value = history?.sessions?.slice(0, 5) || [];
  } catch (err) {
    console.error('Failed to load dashboard:', err);
  }
}

async function loadSessionHistory() {
  router.push('/portal/history');
}

async function handleMpesaPayment() {
  paymentLoading.value = true;
  try {
    const phone = '254' + paymentForm.value.phone.replace(/\D/g, '');
    const result = await initiateMpesaPayment(phone, paymentForm.value.amount);
    
    showSuccess(result.message || 'Payment request sent! Check your phone.');
    showPaymentModal.value = false;
    paymentForm.value = { phone: '', amount: '' };
    
    // Refresh dashboard after a delay
    setTimeout(loadDashboard, 3000);
  } catch (err) {
    console.error('Payment failed:', err);
  } finally {
    paymentLoading.value = false;
  }
}

async function handleVoucherRedeem() {
  voucherLoading.value = true;
  try {
    const result = await redeemVoucher(voucherForm.value.code);
    
    showSuccess(result.message || 'Voucher redeemed successfully!');
    showVoucherModal.value = false;
    voucherForm.value = { code: '' };
    
    // Refresh dashboard
    loadDashboard();
  } catch (err) {
    console.error('Voucher redemption failed:', err);
  } finally {
    voucherLoading.value = false;
  }
}

function showSuccess(message) {
  successMessage.value = message;
  setTimeout(() => {
    successMessage.value = '';
  }, 5000);
}

function handleLogout() {
  logout();
}

function formatNumber(num) {
  return new Intl.NumberFormat('en-KE').format(num);
}

function formatDate(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleDateString('en-KE', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

function formatDateTime(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleString('en-KE', {
    day: 'numeric',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  });
}

onMounted(() => {
  loadDashboard();
});
</script>
