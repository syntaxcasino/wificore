import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_URL || import.meta.env.VITE_API_BASE_URL || '/api';

// Reactive state
const token = ref(localStorage.getItem('pppoe_portal_token') || null);
const user = ref(JSON.parse(localStorage.getItem('pppoe_portal_user') || 'null'));
const isLoading = ref(false);
const error = ref(null);
const isLoggingOut = ref(false);

export function usePppoePortal() {
  const router = useRouter();

  const isAuthenticated = computed(() => !!token.value && !!user.value);

  // Axios instance with auth header
  const api = axios.create({
    baseURL: `${API_BASE_URL}/pppoe-portal`,
    headers: {
      'Content-Type': 'application/json',
    },
  });

  // Request interceptor to add token
  api.interceptors.request.use((config) => {
    if (token.value) {
      config.headers.Authorization = `Bearer ${token.value}`;
    }
    return config;
  });

  // Response interceptor for 401 handling
  api.interceptors.response.use(
    (response) => response,
    (error) => {
      const requestUrl = String(error?.config?.url || '');
      if (error.response?.status === 401 && !requestUrl.includes('/logout') && !isLoggingOut.value) {
        logout({ skipServerCall: true });
        router.push('/portal/login');
      }
      return Promise.reject(error);
    }
  );

  async function login(accountNumber, portalPassword) {
    isLoading.value = true;
    error.value = null;
    // Clear stale local session before a fresh login attempt.
    token.value = null;
    user.value = null;
    localStorage.removeItem('pppoe_portal_token');
    localStorage.removeItem('pppoe_portal_user');

    try {
      const response = await api.post('/login', {
        account_number: accountNumber,
        portal_password: portalPassword,
      });

      if (response.data.success) {
        token.value = response.data.token;
        user.value = response.data.user;
        
        // Persist to localStorage
        localStorage.setItem('pppoe_portal_token', token.value);
        localStorage.setItem('pppoe_portal_user', JSON.stringify(user.value));

        return { success: true };
      }
    } catch (err) {
      token.value = null;
      user.value = null;
      localStorage.removeItem('pppoe_portal_token');
      localStorage.removeItem('pppoe_portal_user');
      error.value = err.response?.data?.message || 'Login failed. Please try again.';
      return { success: false, error: error.value };
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchDashboard() {
    isLoading.value = true;
    try {
      const response = await api.get('/dashboard');
      return response.data.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load dashboard';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchSessionHistory(days = 30) {
    try {
      const response = await api.get('/sessions/history', {
        params: { days },
      });
      return response.data.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load session history';
      throw err;
    }
  }

  async function initiateMpesaPayment(phoneNumber, amount) {
    isLoading.value = true;
    try {
      const response = await api.post('/payment/mpesa', {
        phone_number: phoneNumber,
        amount: parseFloat(amount),
      });
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Payment initiation failed';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function redeemVoucher(voucherCode) {
    isLoading.value = true;
    try {
      const response = await api.post('/payment/voucher', {
        voucher_code: voucherCode,
      });
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Voucher redemption failed';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function checkPaymentStatus(transactionId) {
    try {
      const response = await api.get('/payment/status', {
        params: { transaction_id: transactionId },
      });
      return response.data.data;
    } catch (err) {
      console.error('Failed to check payment status:', err);
      throw err;
    }
  }

  async function fetchPaymentInstructions() {
    try {
      const response = await api.get('/payment/instructions');
      return response.data.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load payment instructions';
      throw err;
    }
  }

  async function logout(options = {}) {
    const { skipServerCall = false } = options;
    if (isLoggingOut.value) {
      return;
    }
    isLoggingOut.value = true;

    try {
      if (!skipServerCall && token.value) {
        await api.post('/logout');
      }
    } catch (err) {
      // Ignore errors on logout
    } finally {
      // Clear all state
      token.value = null;
      user.value = null;
      localStorage.removeItem('pppoe_portal_token');
      localStorage.removeItem('pppoe_portal_user');
      isLoggingOut.value = false;
      router.push('/portal/login');
    }
  }

  function clearError() {
    error.value = null;
  }

  return {
    // State
    token: readonly(token),
    user: readonly(user),
    isLoading: readonly(isLoading),
    error: readonly(error),
    isAuthenticated,
    
    // Methods
    login,
    logout,
    fetchDashboard,
    fetchSessionHistory,
    initiateMpesaPayment,
    redeemVoucher,
    fetchPaymentInstructions,
    checkPaymentStatus,
    clearError,
  };
}

// Helper for readonly refs
function readonly(ref) {
  return computed(() => ref.value);
}
