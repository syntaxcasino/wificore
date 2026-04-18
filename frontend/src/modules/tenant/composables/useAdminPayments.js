import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'
import { CreditCard, Smartphone, Banknote, Building } from 'lucide-vue-next'

export function useAdminPayments() {
  const { error: showError, info: showInfo } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const refreshing = ref(false)
  const error = ref(null)
  const payments = ref([])
  const recordSubmitting = ref(false)
  const selectedPayment = ref(null)
  const showDetailsOverlay = ref(false)
  const showRecordOverlay = ref(false)

  const recordForm = ref({
    phone_number: '', amount: 0, payment_method: 'cash', transaction_id: ''
  })

  const stats = computed(() => {
    const total = payments.value.reduce((sum, p) => sum + p.amount, 0)
    const mpesa = payments.value.filter(p => p.method === 'mpesa').reduce((sum, p) => sum + p.amount, 0)
    const cash = payments.value.filter(p => p.method === 'cash').reduce((sum, p) => sum + p.amount, 0)
    const bank = payments.value.filter(p => p.method === 'bank').reduce((sum, p) => sum + p.amount, 0)
    const today = payments.value.filter(p => {
      const payDate = new Date(p.payment_date)
      return payDate.toDateString() === new Date().toDateString()
    }).reduce((sum, p) => sum + p.amount, 0)
    return { total, mpesa, cash, bank, today }
  })

  const getMethodVariant = (method) =>
    ({ mpesa: 'success', cash: 'warning', bank: 'info', card: 'purple' }[method] || 'default')

  const getMethodIcon = (method) =>
    ({ mpesa: Smartphone, cash: Banknote, bank: Building, card: CreditCard }[method] || CreditCard)

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
  const formatDate = (date) =>
    date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A'
  const formatTime = (date) =>
    date ? new Date(date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'N/A'

  const fetchPayments = async (filters = {}) => {
    const isInitial = payments.value.length === 0
    if (isInitial) { loading.value = true; error.value = null }
    else refreshing.value = true
    try {
      const params = { per_page: 100, ...filters }
      const response = await axios.get('/payments', { params })
      const data = response.data?.payments?.data || response.data?.payments || response.data?.data || []
      payments.value = data.map(p => ({
        id: p.id,
        reference: p.reference || `PAY-${p.id}`,
        transaction_id: p.transaction_id || p.mpesa_receipt || '',
        customer_name: p.user?.name || p.phone_number || 'Unknown',
        customer_email: p.user?.email || '',
        phone_number: p.phone_number || p.user?.phone_number || '',
        amount: Number(p.amount) || 0,
        method: p.payment_method || 'mpesa',
        status: p.status || 'completed',
        invoice_number: p.invoice_number || null,
        payment_date: p.created_at || p.paid_at || new Date().toISOString(),
        package: p.package || null,
        user: p.user || null,
        _raw: p
      }))
    } catch (err) {
      if (isInitial) error.value = err.response?.data?.message || 'Failed to load payments.'
      console.error('fetchPayments error:', err)
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  const viewPayment = (payment) => {
    selectedPayment.value = payment
    showDetailsOverlay.value = true
  }

  const recordPayment = () => {
    recordForm.value = { phone_number: '', amount: 0, payment_method: 'cash', transaction_id: '' }
    showRecordOverlay.value = true
  }

  const downloadReceipt = (payment) => {
    const csv = [
      'Reference,Customer,Amount,Method,Date',
      `${payment.reference},${payment.customer_name},${payment.amount},${payment.method},${payment.payment_date}`
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `receipt-${payment.reference || payment.id}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  const sendReceipt = async (payment) => {
    const confirmed = await confirmStore.open({
      title: 'Send Receipt',
      message: `Send receipt to ${payment.customer_email || payment.phone_number}?`,
      confirmText: 'Send', cancelText: 'Cancel', variant: 'info'
    })
    if (confirmed) showInfo('Receipt sending is not yet configured.')
  }

  const submitRecordPayment = async () => {
    if (!recordForm.value.phone_number || !recordForm.value.amount) {
      showError('Phone number and amount are required.')
      return
    }
    recordSubmitting.value = true
    try {
      await axios.post('/pppoe/payments', recordForm.value)
      showRecordOverlay.value = false
      await fetchPayments()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to record payment')
    } finally {
      recordSubmitting.value = false
    }
  }

  const exportPayments = (filteredData) => {
    const csv = [
      ['Reference', 'Customer', 'Amount', 'Method', 'Status', 'Date'].join(','),
      ...filteredData.map(p =>
        [p.reference, p.customer_name, p.amount, p.method, p.status, p.payment_date].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `payments-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  return {
    loading, refreshing, error, payments, recordSubmitting,
    selectedPayment, showDetailsOverlay, showRecordOverlay, recordForm,
    stats,
    getMethodVariant, getMethodIcon,
    formatMoney, formatDate, formatTime,
    fetchPayments, viewPayment, recordPayment,
    downloadReceipt, sendReceipt, submitRecordPayment, exportPayments
  }
}
