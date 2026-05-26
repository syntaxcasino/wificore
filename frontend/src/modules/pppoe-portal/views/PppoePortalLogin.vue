<template>
  <div class="min-h-screen flex">
    <!-- Left Side - Branding -->
    <div class="hidden lg:flex lg:w-1/2 xl:w-3/5 bg-slate-900 relative overflow-hidden">
      <!-- Subtle pattern overlay -->
      <div class="absolute inset-0 opacity-5" style="background-image: radial-gradient(circle at 2px 2px, white 1px, transparent 0); background-size: 32px 32px;"></div>
      
      <!-- Content -->
      <div class="relative z-10 flex flex-col justify-between p-12">
        <div>
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 bg-indigo-500 rounded-lg flex items-center justify-center">
              <i class="fas fa-wifi text-white text-lg"></i>
            </div>
            <span class="text-white font-semibold text-lg">{{ dashboardData?.user?.provider_name || 'Traidnet' }}</span>
          </div>
        </div>
        
        <div class="max-w-md">
          <h2 class="text-3xl font-bold text-white mb-4">Customer Portal</h2>
          <p class="text-slate-400 text-lg leading-relaxed">Manage your account, view usage, make payments, and stay connected.</p>
          
          <div class="flex items-center gap-6 mt-8">
            <div class="flex items-center gap-2 text-slate-500">
              <i class="fas fa-shield-alt text-emerald-500"></i>
              <span class="text-sm">Secure</span>
            </div>
            <div class="flex items-center gap-2 text-slate-500">
              <i class="fas fa-bolt text-amber-500"></i>
              <span class="text-sm">Fast</span>
            </div>
            <div class="flex items-center gap-2 text-slate-500">
              <i class="fas fa-clock text-blue-500"></i>
              <span class="text-sm">24/7</span>
            </div>
          </div>
        </div>
        
        <div class="text-slate-600 text-sm">
          &copy; {{ new Date().getFullYear() }} Traidnet Solutions. All rights reserved.
        </div>
      </div>
      
      <!-- Decorative gradient -->
      <div class="absolute bottom-0 right-0 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl"></div>
      <div class="absolute top-20 right-20 w-64 h-64 bg-blue-600/10 rounded-full blur-3xl"></div>
    </div>
    
    <!-- Right Side - Login Form -->
    <div class="w-full lg:w-1/2 xl:w-2/5 bg-white flex items-center justify-center p-6 sm:p-8">
      <div class="w-full max-w-md">
        <!-- Mobile Brand -->
        <div class="lg:hidden text-center mb-8">
          <div class="w-12 h-12 bg-indigo-500 rounded-xl flex items-center justify-center mx-auto mb-4">
            <i class="fas fa-wifi text-white text-xl"></i>
          </div>
          <h1 class="text-xl font-semibold text-slate-900">Customer Portal</h1>
          <p class="text-slate-500 text-sm mt-1">{{ isCaptive ? 'Sign in to restore access' : 'Sign in to your account' }}</p>
        </div>
        
        <!-- Captive Alert -->
        <div v-if="isCaptive" class="mb-6 bg-red-50 border border-red-200 rounded-xl p-4">
          <div class="flex items-start gap-3">
            <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center flex-shrink-0">
              <i class="fas fa-exclamation text-red-600 text-sm"></i>
            </div>
            <div>
              <p class="font-medium text-red-900 text-sm">Account Suspended</p>
              <p class="text-red-700 text-sm mt-0.5">Your account is <strong>{{ captiveReason }}</strong>. Sign in to make a payment and restore service.</p>
            </div>
          </div>
        </div>
        
        <!-- Desktop Title -->
        <div class="hidden lg:block mb-8">
          <h1 class="text-2xl font-semibold text-slate-900">Welcome back</h1>
          <p class="text-slate-500 mt-1">Sign in to access your account</p>
        </div>
        
        <!-- Error -->
        <div v-if="error" class="mb-5 bg-red-50 border border-red-200 rounded-lg px-4 py-3 flex items-start gap-2">
          <i class="fas fa-circle-exclamation text-red-500 mt-0.5 text-sm flex-shrink-0"></i>
          <p class="text-sm text-red-700">{{ error }}</p>
        </div>
        
        <!-- Form -->
        <form @submit.prevent="handleLogin" class="space-y-5">
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Account Number</label>
            <div class="relative">
              <i class="fas fa-id-card absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
              <input
                v-model="form.accountNumber"
                type="text"
                required
                :placeholder="isCaptive ? 'On your invoice' : 'e.g. WF-00123'"
                class="w-full pl-10 pr-4 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
              />
            </div>
          </div>
          
          <div>
            <label class="block text-sm font-medium text-slate-700 mb-1.5">Portal Password</label>
            <div class="relative">
              <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400 text-sm"></i>
              <input
                v-model="form.portalPassword"
                :type="showPassword ? 'text' : 'password'"
                required
                placeholder="Enter your password"
                class="w-full pl-10 pr-11 py-2.5 bg-slate-50 border border-slate-200 rounded-lg text-slate-900 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-all"
              />
              <button type="button" @click="showPassword = !showPassword"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-colors">
                <i :class="['fas text-sm', showPassword ? 'fa-eye-slash' : 'fa-eye']"></i>
              </button>
            </div>
            <p class="mt-1.5 text-xs text-slate-500">Different from your PPPoE connection password</p>
          </div>
          
          <button type="submit" :disabled="isLoading"
            :class="[
              'w-full py-2.5 rounded-lg font-medium text-sm transition-all duration-200 flex items-center justify-center gap-2',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              isCaptive
                ? 'bg-red-600 hover:bg-red-700 text-white'
                : 'bg-indigo-600 hover:bg-indigo-700 text-white'
            ]">
            <i v-if="isLoading" class="fas fa-spinner fa-spin"></i>
            <span>{{ isLoading ? 'Signing in…' : 'Sign In' }}</span>
          </button>
        </form>
        
        <div class="mt-6 text-center">
          <p class="text-sm text-slate-500">
            Forgot password? <a href="#" class="text-indigo-600 hover:text-indigo-700 font-medium">Contact support</a>
          </p>
        </div>
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

const isCaptive = computed(() => route.query.captive === '1' || route.query.redirect === 'unpaid');
const captiveReason = computed(() => {
  const reasons = { unpaid: 'unpaid/expired', expired: 'expired', suspended: 'suspended' };
  return reasons[route.query.reason] || 'unpaid';
});

const form = reactive({ accountNumber: '', portalPassword: '' });

onMounted(() => {
  if (route.query.account) form.accountNumber = route.query.account;
});

async function handleLogin() {
  clearError();
  const result = await login(form.accountNumber, form.portalPassword);
  if (result.success) {
    const userStatus = result.user?.status;
    if (isCaptive.value || userStatus === 'suspended' || userStatus === 'expired') {
      router.push({ path: '/portal/payment', query: { captive: '1', reason: userStatus || captiveReason.value } });
    } else {
      router.push('/portal/dashboard');
    }
  }
}
</script>

<style scoped>
/* Minimal professional styles - mostly Tailwind */
</style>
