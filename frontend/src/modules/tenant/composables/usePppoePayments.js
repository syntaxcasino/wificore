import { ref, computed } from 'vue'
import axios from 'axios'

/**
 * Composable for PPPoE payment management with real-time WebSocket updates
 */
export function usePppoePayments() {
  const loading = ref(false)
  const error = ref(null)
  const settings = ref(null)
  const transactions = ref([])
  const checkLogs = ref([])

  /**
   * Fetch Paybill settings
   */
  const fetchSettings = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/billing/paybill/settings')
      settings.value = response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load settings'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Save Paybill settings
   */
  const saveSettings = async (formData) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post('/billing/paybill/settings', formData)
      settings.value = response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save settings'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Test MPesa connection
   */
  const testConnection = async () => {
    try {
      const response = await axios.post('/billing/paybill/test')
      return response.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'Connection test failed')
    }
  }

  /**
   * Register callback URLs with Safaricom
   */
  const registerUrls = async () => {
    try {
      const response = await axios.post('/billing/paybill/register-urls')
      return response.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'URL registration failed')
    }
  }

  /**
   * Switch to using landlord Paybill
   */
  const useLandlordPaybill = async () => {
    try {
      const response = await axios.post('/billing/paybill/use-landlord')
      return response.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'Failed to switch to landlord Paybill')
    }
  }

  /**
   * Activate tenant's own Paybill
   */
  const activateOwnPaybill = async () => {
    try {
      const response = await axios.post('/billing/paybill/activate')
      return response.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'Failed to activate Paybill')
    }
  }

  /**
   * Get payment instructions for a user
   */
  const getPaymentInstructions = async (userId) => {
    try {
      const response = await axios.get(`/billing/paybill/instructions/${userId}`)
      return response.data.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'Failed to get instructions')
    }
  }

  /**
   * Fetch transaction history
   */
  const fetchTransactions = async (page = 1, perPage = 20) => {
    loading.value = true
    try {
      const response = await axios.get('/billing/paybill/transactions', {
        params: { page, per_page: perPage }
      })
      transactions.value = response.data.data?.data || []
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load transactions'
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch payment check logs
   */
  const fetchCheckLogs = async () => {
    try {
      const response = await axios.get('/billing/paybill/logs')
      checkLogs.value = response.data.data || []
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load logs'
      throw err
    }
  }

  /**
   * Manually trigger payment check
   */
  const triggerPaymentCheck = async () => {
    try {
      const response = await axios.post('/billing/paybill/check-payments')
      return response.data
    } catch (err) {
      throw new Error(err.response?.data?.message || 'Failed to trigger payment check')
    }
  }

  /**
   * Setup WebSocket listeners for real-time updates
   */
  const setupWebSocketListeners = (tenantId, callbacks = {}) => {
    if (!window.Echo || !tenantId) return null

    const channels = []

    // Settings channel
    const settingsChannel = window.Echo.private(`tenant.${tenantId}.settings`)
    settingsChannel.listen('.paybill.settings.updated', (event) => {
      if (callbacks.onSettingsUpdated) {
        callbacks.onSettingsUpdated(event)
      }
      fetchSettings()
    })
    channels.push({ channel: settingsChannel, event: '.paybill.settings.updated' })

    // Payments channel
    const paymentsChannel = window.Echo.private(`tenant.${tenantId}.payments`)
    paymentsChannel.listen('.payment.received', (event) => {
      if (callbacks.onPaymentReceived) {
        callbacks.onPaymentReceived(event)
      }
    })
    channels.push({ channel: paymentsChannel, event: '.payment.received' })

    // PPPoE users channel (for status changes)
    const pppoeChannel = window.Echo.private(`tenant.${tenantId}.pppoe-users`)
    pppoeChannel.listen('.pppoe.payment.status.changed', (event) => {
      if (callbacks.onStatusChanged) {
        callbacks.onStatusChanged(event)
      }
    })
    channels.push({ channel: pppoeChannel, event: '.pppoe.payment.status.changed' })

    // Return cleanup function
    return () => {
      channels.forEach(({ channel, event }) => {
        channel.stopListening(event)
      })
    }
  }

  // Computed
  const hasOwnPaybill = computed(() => settings.value?.has_own_paybill || false)
  const usingLandlordPaybill = computed(() => settings.value?.using_landlord_paybill || false)
  const landlordPaybillAvailable = computed(() => settings.value?.landlord_paybill_available || false)
  const activeShortcode = computed(() => {
    if (usingLandlordPaybill.value) {
      return settings.value?.landlord_shortcode
    }
    return settings.value?.data?.business_shortcode
  })

  return {
    // State
    loading,
    error,
    settings,
    transactions,
    checkLogs,

    // Computed
    hasOwnPaybill,
    usingLandlordPaybill,
    landlordPaybillAvailable,
    activeShortcode,

    // Methods
    fetchSettings,
    saveSettings,
    testConnection,
    registerUrls,
    useLandlordPaybill,
    activateOwnPaybill,
    getPaymentInstructions,
    fetchTransactions,
    fetchCheckLogs,
    triggerPaymentCheck,
    setupWebSocketListeners,
  }
}
