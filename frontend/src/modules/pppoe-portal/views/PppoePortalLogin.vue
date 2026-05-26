<template>
  <div class="portal-login-bg min-h-screen flex items-center justify-center p-4">
    <!-- Background blobs -->
    <div class="blob blob-1" :class="isCaptive ? 'blob-red' : 'blob-indigo'"></div>
    <div class="blob blob-2" :class="isCaptive ? 'blob-orange' : 'blob-purple'"></div>

    <div class="w-full max-w-sm relative z-10">
      <!-- Brand -->
      <div class="text-center mb-8 animate-fade-up">
        <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl shadow-lg mb-4"
          :class="isCaptive ? 'bg-red-500' : 'bg-indigo-600'">
          <i :class="['text-white text-2xl fas', isCaptive ? 'fa-lock' : 'fa-wifi']"></i>
        </div>
        <h1 class="text-2xl font-bold text-white tracking-tight">
          {{ isCaptive ? 'Payment Required' : 'My Account' }}
        </h1>
        <p class="text-white/70 text-sm mt-1">
          {{ isCaptive ? 'Sign in to restore your internet access' : 'Customer self-service portal' }}
        </p>
      </div>

      <!-- Captive alert -->
      <div v-if="isCaptive" class="glass-card rounded-2xl p-4 mb-4 flex items-start gap-3 animate-fade-up border border-red-200/30">
        <div class="w-8 h-8 bg-red-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
          <i class="fas fa-exclamation text-red-300 text-xs"></i>
        </div>
        <div>
          <p class="text-white font-medium text-sm">Internet access suspended</p>
          <p class="text-white/60 text-xs mt-0.5">Account is <strong class="text-white/80">{{ captiveReason }}</strong>. Pay now to restore.</p>
        </div>
      </div>

      <!-- Card -->
      <div class="glass-card rounded-3xl p-6 sm:p-8 shadow-2xl animate-fade-up" style="animation-delay:0.05s">

        <!-- Error -->
        <div v-if="error" class="mb-5 flex items-start gap-3 bg-red-500/10 border border-red-400/30 rounded-xl px-4 py-3">
          <i class="fas fa-circle-exclamation text-red-400 mt-0.5 text-sm flex-shrink-0"></i>
          <p class="text-sm text-red-200">{{ error }}</p>
        </div>

        <form @submit.prevent="handleLogin" class="space-y-4">
          <!-- Account Number -->
          <div>
            <label class="block text-xs font-semibold text-white/60 uppercase tracking-wider mb-2">Account Number</label>
            <div class="relative">
              <i class="fas fa-id-card absolute left-3.5 top-1/2 -translate-y-1/2 text-white/30 text-sm"></i>
              <input
                v-model="form.accountNumber"
                type="text"
                required
                :placeholder="isCaptive ? 'On your invoice / receipt' : 'e.g. WF-00123'"
                class="portal-input w-full pl-10 pr-4 py-3 text-sm"
              />
            </div>
          </div>

          <!-- Password -->
          <div>
            <label class="block text-xs font-semibold text-white/60 uppercase tracking-wider mb-2">Portal Password</label>
            <div class="relative">
              <i class="fas fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-white/30 text-sm"></i>
              <input
                v-model="form.portalPassword"
                :type="showPassword ? 'text' : 'password'"
                required
                placeholder="Enter portal password"
                class="portal-input w-full pl-10 pr-11 py-3 text-sm"
              />
              <button type="button" @click="showPassword = !showPassword"
                class="absolute right-3.5 top-1/2 -translate-y-1/2 text-white/30 hover:text-white/60 transition-colors">
                <i :class="['fas text-sm', showPassword ? 'fa-eye-slash' : 'fa-eye']"></i>
              </button>
            </div>
            <p class="mt-1.5 text-xs text-white/40">Different from your PPPoE connection password</p>
          </div>

          <!-- Submit -->
          <button type="submit" :disabled="isLoading"
            :class="[
              'w-full py-3.5 rounded-xl font-semibold text-sm text-white transition-all duration-200 flex items-center justify-center gap-2 mt-2',
              'disabled:opacity-50 disabled:cursor-not-allowed',
              isCaptive
                ? 'bg-red-500 hover:bg-red-400 shadow-lg shadow-red-500/30'
                : 'bg-indigo-500 hover:bg-indigo-400 shadow-lg shadow-indigo-500/30'
            ]">
            <i v-if="isLoading" class="fas fa-spinner fa-spin text-sm"></i>
            <span>{{ isLoading ? 'Signing in…' : isCaptive ? 'Sign In & Pay' : 'Sign In' }}</span>
          </button>
        </form>

        <p class="mt-5 text-center text-xs text-white/40">
          Forgot password? Contact your ISP provider
        </p>
      </div>

      <p class="text-center text-white/30 text-xs mt-6">&copy; {{ new Date().getFullYear() }} All rights reserved</p>
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
.portal-login-bg {
  background: #0f0c29;
  background: linear-gradient(135deg, #0f0c29 0%, #302b63 50%, #24243e 100%);
  position: relative;
  overflow: hidden;
}
.portal-login-bg.captive {
  background: linear-gradient(135deg, #1a0000 0%, #3d0000 50%, #200000 100%);
}

.blob {
  position: absolute;
  border-radius: 50%;
  filter: blur(80px);
  opacity: 0.25;
  pointer-events: none;
}
.blob-1 { width: 400px; height: 400px; top: -100px; left: -100px; }
.blob-2 { width: 350px; height: 350px; bottom: -80px; right: -80px; }
.blob-indigo { background: #6366f1; }
.blob-purple { background: #a855f7; }
.blob-red    { background: #ef4444; }
.blob-orange { background: #f97316; }

.glass-card {
  background: rgba(255, 255, 255, 0.06);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border: 1px solid rgba(255, 255, 255, 0.12);
}

.portal-input {
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 12px;
  color: #fff;
  transition: border-color 0.2s, background 0.2s;
  outline: none;
}
.portal-input::placeholder { color: rgba(255,255,255,0.25); }
.portal-input:focus {
  border-color: rgba(255, 255, 255, 0.35);
  background: rgba(255, 255, 255, 0.10);
}

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(16px); }
  to   { opacity: 1; transform: translateY(0); }
}
.animate-fade-up {
  animation: fadeUp 0.45s ease both;
}
</style>
