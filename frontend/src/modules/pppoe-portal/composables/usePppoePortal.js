import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import axios from 'axios';

const API_BASE_URL = import.meta.env.VITE_API_URL || import.meta.env.VITE_API_BASE_URL || '/api';

// Reactive state
function readJsonStorage(key) {
  if (typeof window === 'undefined') return null;
  const raw = localStorage.getItem(key);
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch (error) {
    console.warn(`Invalid JSON in ${key}; clearing stale value`, error);
    localStorage.removeItem(key);
    return null;
  }
}

const storedToken = typeof window === 'undefined' ? null : localStorage.getItem('pppoe_portal_token') || null;
const storedUser = readJsonStorage('pppoe_portal_user');
if (typeof window !== 'undefined' && storedToken && !storedUser) {
  localStorage.removeItem('pppoe_portal_token');
}
const token = ref(storedToken && storedUser ? storedToken : null);
const user = ref(storedUser);
const isLoading = ref(false);
const error = ref(null);
const isLoggingOut = ref(false);
const dashboardCache = ref(null);
let dashboardPromise = null;
let dashboardFetchedAt = 0;
const DASHBOARD_SEED_MAX_AGE_MS = 10000;

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
      const isLoginRequest = requestUrl.includes('/login');
      if (error.response?.status === 401 && !isLoginRequest && !requestUrl.includes('/logout') && !isLoggingOut.value) {
        logout({ skipServerCall: true });
        router.push('/portal/login');
      }
      return Promise.reject(error);
    }
  );

  function setDashboardCache(data) {
    dashboardCache.value = data;
    dashboardFetchedAt = Date.now();
  }

  function invalidateDashboardCache() {
    dashboardCache.value = null;
    dashboardFetchedAt = 0;
    dashboardPromise = null;
  }

  function getDashboardSeed(maxAgeMs = DASHBOARD_SEED_MAX_AGE_MS) {
    if (!dashboardCache.value) {
      return null;
    }

    return Date.now() - dashboardFetchedAt <= maxAgeMs ? dashboardCache.value : null;
  }

  async function login(accountNumber, portalPassword) {
    isLoading.value = true;
    error.value = null;
    // Clear stale local session before a fresh login attempt.
    token.value = null;
    user.value = null;
    localStorage.removeItem('pppoe_portal_token');
    localStorage.removeItem('pppoe_portal_user');
    invalidateDashboardCache();

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

        prefetchDashboard().catch(() => {});
        return { success: true };
      }
    } catch (err) {
      token.value = null;
      user.value = null;
      invalidateDashboardCache();
      localStorage.removeItem('pppoe_portal_token');
      localStorage.removeItem('pppoe_portal_user');
      error.value = err.response?.data?.message || 'Login failed. Please try again.';
      return { success: false, error: error.value };
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchDashboard(options = {}) {
    const { force = false } = options;

    if (!force && dashboardPromise) {
      return dashboardPromise;
    }

    if (!force) {
      const seed = getDashboardSeed();
      if (seed) {
        return seed;
      }
    }

    isLoading.value = true;
    dashboardPromise = api.get('/dashboard')
      .then((response) => {
        const data = response.data.data;
        setDashboardCache(data);
        return data;
      })
      .catch((err) => {
        error.value = err.response?.data?.message || 'Failed to load dashboard';
        throw err;
      })
      .finally(() => {
        isLoading.value = false;
        dashboardPromise = null;
      });

    return dashboardPromise;
  }

  function prefetchDashboard() {
    return fetchDashboard({ force: true });
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
      invalidateDashboardCache();
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
      invalidateDashboardCache();
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

  async function pauseAccount() {
    isLoading.value = true;
    try {
      const response = await api.post('/account/pause');
      invalidateDashboardCache();
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to pause account';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function resumeAccount() {
    isLoading.value = true;
    try {
      const response = await api.post('/account/resume');
      invalidateDashboardCache();
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to resume account';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchPlans() {
    try {
      const response = await api.get('/plans');
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load plans';
      throw err;
    }
  }

  async function requestPlanSwitch(packageId) {
    isLoading.value = true;
    try {
      const response = await api.post('/plans/switch', { package_id: packageId });
      invalidateDashboardCache();
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to request plan switch';
      throw err;
    } finally {
      isLoading.value = false;
    }
  }

  async function fetchTimedVoucherOptions() {
    try {
      const response = await api.get('/vouchers/timed/options');
      return response.data.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load voucher options';
      throw err;
    }
  }

  async function buyTimedVoucher(phoneNumber, packageId, durationHours) {
    isLoading.value = true;
    try {
      const response = await api.post('/vouchers/timed/buy', {
        phone_number:   phoneNumber,
        package_id:     packageId,
        duration_hours: durationHours,
      });
      return response.data;
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to purchase voucher';
      throw err;
    } finally {
      isLoading.value = false;
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
      invalidateDashboardCache();
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
    getDashboardSeed,
    prefetchDashboard,
    invalidateDashboardCache,
    pauseAccount,
    resumeAccount,
    fetchPlans,
    requestPlanSwitch,
    fetchTimedVoucherOptions,
    buyTimedVoucher,
  };
}

// Helper for readonly refs
function readonly(ref) {
  return computed(() => ref.value);
}
