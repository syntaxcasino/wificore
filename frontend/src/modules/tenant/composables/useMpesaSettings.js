import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'

export function useMpesaSettings() {
  const { error: showError } = useToast()

  const loading = ref(false)
  const saving = ref(false)
  const testing = ref(false)
  const activating = ref(false)
  const error = ref('')
  const settingsData = ref(null)
  const landlordPaybillAvailable = ref(false)
  const landlordShortcode = ref('')
  const connectionStatus = ref(null)

  const formDefaults = {
    environment: 'sandbox',
    business_shortcode: '',
    consumer_key: '',
    consumer_secret: '',
    passkey: '',
    account_reference: '',
    use_landlord_paybill: true,
  }

  const formData = ref({ ...formDefaults })

  const gatewayActive = computed(() => {
    if (!settingsData.value) return false
    return settingsData.value.is_active || settingsData.value.use_landlord_paybill
  })

  const fetchSettings = async () => {
    loading.value = true
    error.value = ''
    try {
      const response = await axios.get('/billing/paybill/settings')
      const d = response.data
      settingsData.value = d.data
      landlordPaybillAvailable.value = d.landlord_paybill_available ?? false
      landlordShortcode.value = d.landlord_shortcode ?? ''
      if (d.data) {
        formData.value = {
          environment: d.data.environment || 'sandbox',
          business_shortcode: d.data.business_shortcode || '',
          consumer_key: '',
          consumer_secret: '',
          passkey: '',
          account_reference: d.data.account_reference || '',
          use_landlord_paybill: d.data.use_landlord_paybill ?? true,
        }
      } else {
        formData.value = { ...formDefaults }
      }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load settings'
      console.error('fetchMpesaSettings error:', err)
    } finally {
      loading.value = false
    }
  }

  const saveSettings = async () => {
    saving.value = true
    error.value = ''
    try {
      const response = await axios.post('/billing/paybill/settings', formData.value)
      settingsData.value = response.data?.data || settingsData.value
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save settings'
      return { success: false }
    } finally {
      saving.value = false
    }
  }

  const testConnection = async () => {
    testing.value = true
    connectionStatus.value = null
    try {
      const response = await axios.post('/billing/paybill/test')
      connectionStatus.value = {
        success: response.data?.success ?? true,
        message: response.data?.message || 'Connection Successful',
        details: response.data?.using_landlord_paybill ? 'Using system Paybill' : 'Using own Paybill'
      }
    } catch (err) {
      connectionStatus.value = {
        success: false,
        message: 'Connection Failed',
        details: err.response?.data?.message || 'Please check your credentials'
      }
    } finally {
      testing.value = false
    }
  }

  const activateGateway = async () => {
    activating.value = true
    try {
      await axios.post('/billing/paybill/activate')
      await fetchSettings()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to activate gateway')
    } finally {
      activating.value = false
    }
  }

  const fetchRecentTransactions = async (limit = 5) => {
    try {
      const response = await axios.get(`/billing/paybill/transactions?per_page=${limit}`)
      return response.data.data?.data || response.data.data || []
    } catch (err) {
      console.error('fetchRecentTransactions error:', err)
      return []
    }
  }

  return {
    loading, saving, testing, activating,
    error, settingsData, landlordPaybillAvailable, landlordShortcode,
    connectionStatus, formData, formDefaults, gatewayActive,
    fetchSettings, saveSettings, testConnection, activateGateway, fetchRecentTransactions
  }
}
