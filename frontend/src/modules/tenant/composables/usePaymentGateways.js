import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'

export function usePaymentGateways() {
  const { error: showError } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const error = ref(null)
  const gateways = ref([])
  const selectedGateway = ref(null)
  const formSubmitting = ref(false)
  const formError = ref(null)
  const testing = ref(false)
  const testAmount = ref('')
  const testResult = ref(null)
  const showCreateOverlay = ref(false)
  const showViewOverlay = ref(false)
  const showEditOverlay = ref(false)

  const defaultCreateForm = () => ({
    name: '', provider: '', environment: 'sandbox',
    is_active: true, is_default: false, credentials: {}
  })

  const form = ref(defaultCreateForm())
  const editForm = ref({ id: null, name: '', provider: '', environment: 'sandbox', is_active: true, is_default: false, credentials: {} })

  const activeGateways = computed(() => gateways.value.filter(g => g.is_active))

  const credentialFields = computed(() => getCredentialFields(form.value.provider))
  const editCredentialFields = computed(() => getCredentialFields(editForm.value.provider))

  const getCredentialFields = (provider) => {
    const fields = {
      mpesa: [
        { key: 'consumer_key', label: 'Consumer Key' },
        { key: 'consumer_secret', label: 'Consumer Secret', secret: true },
        { key: 'passkey', label: 'Passkey', secret: true },
        { key: 'shortcode', label: 'Shortcode', placeholder: 'e.g. 174379' }
      ],
      stripe: [
        { key: 'publishable_key', label: 'Publishable Key' },
        { key: 'secret_key', label: 'Secret Key', secret: true }
      ],
      paypal: [
        { key: 'client_id', label: 'Client ID' },
        { key: 'client_secret', label: 'Client Secret', secret: true }
      ],
      flutterwave: [
        { key: 'public_key', label: 'Public Key' },
        { key: 'secret_key', label: 'Secret Key', secret: true }
      ],
      custom: [
        { key: 'api_key', label: 'API Key', secret: true },
        { key: 'api_endpoint', label: 'API Endpoint', placeholder: 'https://api.example.com/pay' }
      ]
    }
    return fields[provider] || fields.custom
  }

  const formatProvider = (p) =>
    ({ mpesa: 'M-Pesa (Daraja)', stripe: 'Stripe', paypal: 'PayPal', flutterwave: 'Flutterwave', custom: 'Custom API' }[p] || p)

  const onProviderChange = () => { form.value.credentials = {} }

  const fetchGateways = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/settings/payment-gateways')
      gateways.value = response.data?.gateways || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load payment gateways'
    } finally {
      loading.value = false
    }
  }

  const openCreateOverlay = () => {
    form.value = defaultCreateForm()
    formError.value = null
    showCreateOverlay.value = true
  }

  const openViewOverlay = (gateway) => {
    selectedGateway.value = gateway
    testAmount.value = ''
    testResult.value = null
    showViewOverlay.value = true
  }

  const openEditOverlay = (gateway) => {
    showViewOverlay.value = false
    editForm.value = {
      id: gateway.id, name: gateway.name, provider: gateway.provider,
      environment: gateway.environment, is_active: gateway.is_active,
      is_default: gateway.is_default, credentials: {}
    }
    formError.value = null
    showEditOverlay.value = true
  }

  const handleCreate = async () => {
    formSubmitting.value = true
    formError.value = null
    try {
      await axios.post('/settings/payment-gateways', form.value)
      showCreateOverlay.value = false
      await fetchGateways()
    } catch (err) {
      formError.value = err.response?.data?.message || 'Failed to create gateway'
    } finally {
      formSubmitting.value = false
    }
  }

  const handleUpdate = async () => {
    formSubmitting.value = true
    formError.value = null
    try {
      const payload = { ...editForm.value }
      delete payload.id
      delete payload.provider
      const filtered = Object.fromEntries(
        Object.entries(payload.credentials || {}).filter(([, v]) => v && v.trim())
      )
      if (Object.keys(filtered).length === 0) delete payload.credentials
      else payload.credentials = filtered
      await axios.patch(`/settings/payment-gateways/${editForm.value.id}`, payload)
      showEditOverlay.value = false
      await fetchGateways()
    } catch (err) {
      formError.value = err.response?.data?.message || 'Failed to update gateway'
    } finally {
      formSubmitting.value = false
    }
  }

  const handleDelete = async (gateway) => {
    const confirmed = await confirmStore.open({
      title: 'Delete Gateway',
      message: `Delete gateway "${gateway.name}"? This cannot be undone.`,
      confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger'
    })
    if (!confirmed) return
    try {
      await axios.delete(`/settings/payment-gateways/${gateway.id}`)
      await fetchGateways()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to delete gateway')
    }
  }

  const setDefault = async (gateway) => {
    try {
      await axios.patch(`/settings/payment-gateways/${gateway.id}`, { is_default: true })
      await fetchGateways()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to set as default')
    }
  }

  const testGateway = async () => {
    if (!selectedGateway.value || !testAmount.value) return
    testing.value = true
    testResult.value = null
    try {
      const res = await axios.post(`/settings/payment-gateways/${selectedGateway.value.id}/test`, { amount: testAmount.value })
      testResult.value = { success: true, message: res.data?.message || 'Test initiated' }
    } catch (err) {
      testResult.value = { success: false, message: err.response?.data?.message || 'Test failed' }
    } finally {
      testing.value = false
    }
  }

  const getGatewayActions = (gateway) => [
    { label: 'View', onClick: () => openViewOverlay(gateway), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' },
    { label: 'Edit', onClick: () => openEditOverlay(gateway), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
    ...(gateway.is_active && !gateway.is_default
      ? [{ label: 'Set Default', onClick: () => setDefault(gateway), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' }]
      : [])
  ]

  return {
    loading, error, gateways, selectedGateway, formSubmitting, formError,
    testing, testAmount, testResult,
    showCreateOverlay, showViewOverlay, showEditOverlay,
    form, editForm, activeGateways, credentialFields, editCredentialFields,
    getCredentialFields, formatProvider, onProviderChange,
    fetchGateways, openCreateOverlay, openViewOverlay, openEditOverlay,
    handleCreate, handleUpdate, handleDelete, setDefault, testGateway, getGatewayActions
  }
}
