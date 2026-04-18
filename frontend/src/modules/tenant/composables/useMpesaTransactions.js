import { ref, computed, onUnmounted } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'

export function useMpesaTransactions(autoRefreshMs = 30000) {
  const { error: showError } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const refreshing = ref(false)
  const error = ref(null)
  const transactions = ref([])

  const stats = computed(() => {
    const completed = transactions.value.filter(t => t.status === 'completed')
    const today = transactions.value.filter(t => {
      const txDate = new Date(t.transaction_date)
      return txDate.toDateString() === new Date().toDateString()
    })
    const totalReceived = completed.reduce((sum, t) => sum + t.amount, 0)
    const todayAmount = today.filter(t => t.status === 'completed').reduce((sum, t) => sum + t.amount, 0)
    const pendingCount = transactions.value.filter(t => t.status === 'pending').length
    const failedCount = transactions.value.filter(t => t.status === 'failed').length
    const failedRate = transactions.value.length > 0
      ? Math.round((failedCount / transactions.value.length) * 100)
      : 0
    return {
      totalReceived, successCount: completed.length,
      todayAmount, todayCount: today.length,
      pendingCount, failedCount, failedRate
    }
  })

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
  const formatPhone = (phone) =>
    phone ? `+${phone.slice(0, 3)} ${phone.slice(3, 6)} ${phone.slice(6, 9)} ${phone.slice(9)}` : 'N/A'
  const formatDateTime = (date) =>
    date ? new Date(date).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A'

  const getIconBg = (status) =>
    ({ completed: 'bg-green-100', pending: 'bg-amber-100', failed: 'bg-red-100', reversed: 'bg-slate-100' }[status] || 'bg-slate-100')

  const getIconColor = (status) =>
    ({ completed: 'text-green-600', pending: 'text-amber-600', failed: 'text-red-600', reversed: 'text-slate-600' }[status] || 'text-slate-600')

  const getStatusBanner = (status) =>
    ({
      completed: 'bg-green-50 border border-green-200 text-green-900',
      pending: 'bg-amber-50 border border-amber-200 text-amber-900',
      failed: 'bg-red-50 border border-red-200 text-red-900',
      reversed: 'bg-slate-50 border border-slate-200 text-slate-900'
    }[status] || 'bg-slate-50 border border-slate-200 text-slate-900')

  const getStatusMessage = (status) =>
    ({
      completed: 'Payment successfully received and processed',
      pending: 'Awaiting M-Pesa confirmation',
      failed: 'Transaction failed or was cancelled',
      reversed: 'Transaction has been reversed'
    }[status] || 'Unknown status')

  const fetchTransactions = async () => {
    const isInitial = transactions.value.length === 0
    if (isInitial) { loading.value = true; error.value = null } else { refreshing.value = true }
    try {
      const response = await axios.get('/billing/paybill/transactions', { params: { per_page: 100 } })
      const data = response.data?.transactions?.data || response.data?.transactions || response.data?.data || []
      transactions.value = data.map(t => ({
        id: t.id,
        mpesa_receipt: t.mpesa_receipt || t.receipt_number || t.transaction_id || '',
        transaction_id: t.transaction_id || t.checkout_request_id || '',
        customer_name: t.customer_name || t.first_name || t.phone_number || 'Unknown',
        phone_number: t.phone_number || t.msisdn || '',
        amount: Number(t.amount) || 0,
        status: t.status || 'pending',
        account_reference: t.account_reference || t.bill_ref_number || '',
        business_short_code: t.business_short_code || t.paybill_number || '',
        transaction_date: t.transaction_date || t.created_at || new Date().toISOString(),
        description: t.description || t.transaction_desc || '',
        _raw: t
      }))
    } catch (err) {
      if (isInitial) error.value = err.response?.data?.message || 'Failed to load transactions.'
    } finally {
      loading.value = false
      refreshing.value = false
    }
  }

  const checkStatus = async (transaction, selectedTransaction) => {
    try {
      const response = await axios.get(`/billing/paybill/transactions/${transaction.id}/status`)
      const updated = response.data?.transaction || response.data
      if (updated?.status) {
        const idx = transactions.value.findIndex(t => t.id === transaction.id)
        if (idx !== -1) transactions.value[idx].status = updated.status
        if (selectedTransaction?.value?.id === transaction.id) selectedTransaction.value.status = updated.status
      }
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to check transaction status')
    }
  }

  const retryTransaction = async (transaction) => {
    const confirmed = await confirmStore.open({
      title: 'Retry Transaction',
      message: `Retry transaction for ${transaction.customer_name}?`,
      confirmText: 'Retry', cancelText: 'Cancel', variant: 'info'
    })
    if (!confirmed) return
    try {
      await axios.post(`/billing/paybill/transactions/${transaction.id}/retry`)
      await fetchTransactions()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to retry transaction')
    }
  }

  const exportTransactions = (filteredData) => {
    const csv = [
      ['Receipt', 'Customer', 'Phone', 'Amount', 'Status', 'Date'].join(','),
      ...filteredData.map(t =>
        [t.mpesa_receipt, t.customer_name, t.phone_number, t.amount, t.status, t.transaction_date].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `mpesa-transactions-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  let refreshInterval
  const startAutoRefresh = () => {
    refreshInterval = setInterval(fetchTransactions, autoRefreshMs)
  }
  const stopAutoRefresh = () => {
    if (refreshInterval) clearInterval(refreshInterval)
  }

  onUnmounted(stopAutoRefresh)

  return {
    loading, refreshing, error, transactions, stats,
    formatMoney, formatPhone, formatDateTime,
    getIconBg, getIconColor, getStatusBanner, getStatusMessage,
    fetchTransactions, checkStatus, retryTransaction, exportTransactions,
    startAutoRefresh, stopAutoRefresh
  }
}
