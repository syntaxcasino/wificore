import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { CreditCard, Smartphone } from 'lucide-vue-next'

export function usePaymentMethods() {
  const { error: showError } = useToast()

  const loading = ref(false)
  const refreshing = ref(false)
  const saving = ref(false)
  const methods = ref([])
  const selectedMethod = ref(null)
  const showEditOverlay = ref(false)
  const showAddOverlay = ref(false)

  const editForm = ref({ name: '', description: '', is_active: true })
  const addForm = ref({ name: '', description: '', is_active: true })

  const iconMap = {
    'mpesa': { icon: Smartphone, iconColor: 'text-green-600', gradient: 'from-green-500 to-emerald-600' },
    'm-pesa': { icon: Smartphone, iconColor: 'text-green-600', gradient: 'from-green-500 to-emerald-600' },
    'cash': { icon: CreditCard, iconColor: 'text-amber-600', gradient: 'from-amber-500 to-yellow-600' },
    'bank': { icon: CreditCard, iconColor: 'text-blue-600', gradient: 'from-blue-500 to-indigo-600' },
    'bank transfer': { icon: CreditCard, iconColor: 'text-blue-600', gradient: 'from-blue-500 to-indigo-600' },
  }

  const getMethodStyle = (name) => {
    const key = (name || '').toLowerCase()
    for (const [k, v] of Object.entries(iconMap)) {
      if (key.includes(k)) return v
    }
    return { icon: CreditCard, iconColor: 'text-slate-600', gradient: 'from-slate-500 to-slate-600' }
  }

  const stats = computed(() => ({
    total: methods.value.length,
    active: methods.value.filter(m => m.is_active).length,
    inactive: methods.value.filter(m => !m.is_active).length
  }))

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)

  const fetchMethods = async () => {
    loading.value = true
    try {
      const response = await axios.get('/billing/payment-methods')
      methods.value = response.data?.methods || response.data?.payment_methods || response.data?.data || []
    } catch (err) {
      console.error('fetchMethods error:', err)
    } finally {
      loading.value = false
    }
  }

  const refreshMethods = async () => {
    refreshing.value = true
    await fetchMethods()
    refreshing.value = false
  }

  const openAddModal = () => {
    addForm.value = { name: '', description: '', is_active: true }
    showAddOverlay.value = true
  }

  const editMethod = (method) => {
    selectedMethod.value = method
    editForm.value = { name: method.name, description: method.description || '', is_active: method.is_active }
    showEditOverlay.value = true
  }

  const saveMethod = async () => {
    if (!editForm.value.name) return
    saving.value = true
    try {
      await axios.put(`/billing/payment-methods/${selectedMethod.value.id}`, editForm.value)
      showEditOverlay.value = false
      await fetchMethods()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to update payment method')
    } finally {
      saving.value = false
    }
  }

  const createMethod = async () => {
    if (!addForm.value.name) return
    saving.value = true
    try {
      await axios.post('/billing/payment-methods', addForm.value)
      showAddOverlay.value = false
      await fetchMethods()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to create payment method')
    } finally {
      saving.value = false
    }
  }

  const toggleStatus = async (method) => {
    try {
      await axios.patch(`/billing/payment-methods/${method.id}`, { is_active: !method.is_active })
      const idx = methods.value.findIndex(m => m.id === method.id)
      if (idx !== -1) methods.value[idx].is_active = !method.is_active
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to update status')
    }
  }

  return {
    loading, refreshing, saving, methods, selectedMethod,
    showEditOverlay, showAddOverlay, editForm, addForm,
    stats, iconMap,
    getMethodStyle, formatMoney,
    fetchMethods, refreshMethods,
    openAddModal, editMethod, saveMethod, createMethod, toggleStatus
  }
}
