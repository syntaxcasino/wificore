import { ref } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'

/**
 * Generic composable for settings pages that follow the
 * fetch → edit → save pattern with an optional test/connection action.
 *
 * @param {string} endpoint  - Base API endpoint e.g. '/settings/general'
 * @param {object} defaults  - Default form values
 * @param {object} options   - Optional config: { testEndpoint, activateEndpoint, mapResponse }
 */
export function useSettings(endpoint, defaults = {}, options = {}) {
  const { error: showError, success: showSuccess } = useToast()

  const loading = ref(false)
  const saving = ref(false)
  const testing = ref(false)
  const activating = ref(false)
  const error = ref('')
  const successMessage = ref('')
  const testResult = ref(null)

  const formData = ref({ ...defaults })

  const fetchSettings = async () => {
    loading.value = true
    error.value = ''
    try {
      const response = await axios.get(endpoint)
      const raw = response.data?.settings || response.data?.data || response.data || {}
      const mapped = options.mapResponse ? options.mapResponse(raw) : raw
      formData.value = { ...defaults, ...mapped }
    } catch (err) {
      error.value = err.response?.data?.message || `Failed to load settings from ${endpoint}`
      console.error('fetchSettings error:', err)
    } finally {
      loading.value = false
    }
  }

  const saveSettings = async (payload) => {
    saving.value = true
    error.value = ''
    successMessage.value = ''
    try {
      const data = payload ?? formData.value
      const response = await axios.post(endpoint, data)
      const updated = response.data?.settings || response.data?.data || response.data || {}
      const mapped = options.mapResponse ? options.mapResponse(updated) : updated
      if (mapped && Object.keys(mapped).length) {
        formData.value = { ...defaults, ...mapped }
      }
      successMessage.value = options.successMessage || 'Settings saved successfully'
      showSuccess(successMessage.value)
      return { success: true }
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to save settings'
      showError(error.value)
      return { success: false, error: error.value }
    } finally {
      saving.value = false
    }
  }

  const testConnection = async (payload) => {
    if (!options.testEndpoint) return
    testing.value = true
    testResult.value = null
    try {
      const data = payload ?? formData.value
      const response = await axios.post(options.testEndpoint, data)
      testResult.value = {
        success: true,
        message: response.data?.message || 'Connection successful'
      }
      return testResult.value
    } catch (err) {
      testResult.value = {
        success: false,
        message: err.response?.data?.message || 'Connection test failed'
      }
      return testResult.value
    } finally {
      testing.value = false
    }
  }

  const activateGateway = async () => {
    if (!options.activateEndpoint) return
    activating.value = true
    try {
      await axios.post(options.activateEndpoint)
      showSuccess('Gateway activated successfully')
      await fetchSettings()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to activate gateway')
    } finally {
      activating.value = false
    }
  }

  const resetToDefaults = () => {
    formData.value = { ...defaults }
    error.value = ''
    successMessage.value = ''
  }

  return {
    loading, saving, testing, activating,
    error, successMessage, testResult,
    formData,
    fetchSettings, saveSettings, testConnection, activateGateway, resetToDefaults
  }
}
