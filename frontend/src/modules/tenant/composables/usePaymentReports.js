import { ref, computed, watch } from 'vue'
import axios from 'axios'

export function usePaymentReports() {
  const loading = ref(false)
  const refreshing = ref(false)
  const payments = ref([])
  const filters = ref({ period: 'month', method: '' })

  const stats = computed(() => {
    const total = payments.value.reduce((sum, p) => sum + Number(p.amount || 0), 0)
    const count = payments.value.length
    const mpesaCount = payments.value.filter(p =>
      (p.payment_method || '').toLowerCase().includes('mpesa') ||
      (p.payment_method || '').toLowerCase().includes('m-pesa')
    ).length
    return {
      totalRevenue: total,
      totalPayments: count,
      avgPayment: count > 0 ? Math.round(total / count) : 0,
      mpesaPercentage: count > 0 ? Math.round((mpesaCount / count) * 100) : 0
    }
  })

  const methodColors = { 'M-Pesa': 'bg-green-500', 'Cash': 'bg-amber-500', 'Bank Transfer': 'bg-blue-500' }

  const paymentMethods = computed(() => {
    const grouped = {}
    payments.value.forEach(p => {
      const method = p.payment_method || 'Other'
      if (!grouped[method]) grouped[method] = 0
      grouped[method] += Number(p.amount || 0)
    })
    const total = Object.values(grouped).reduce((s, v) => s + v, 0)
    return Object.entries(grouped).map(([name, amount]) => ({
      name, amount,
      percentage: total > 0 ? Math.round((amount / total) * 100) : 0,
      color: methodColors[name] || 'bg-slate-500'
    })).sort((a, b) => b.amount - a.amount)
  })

  const dailyRevenue = computed(() => {
    const grouped = {}
    payments.value.forEach(p => {
      const date = (p.created_at || '').slice(0, 10)
      if (!date) return
      if (!grouped[date]) grouped[date] = { date, count: 0, total: 0, mpesa: 0, cash: 0 }
      grouped[date].count++
      grouped[date].total += Number(p.amount || 0)
      const method = (p.payment_method || '').toLowerCase()
      if (method.includes('mpesa') || method.includes('m-pesa')) grouped[date].mpesa += Number(p.amount || 0)
      if (method.includes('cash')) grouped[date].cash += Number(p.amount || 0)
    })
    return Object.values(grouped).sort((a, b) => b.date.localeCompare(a.date)).slice(0, 30)
  })

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
  const formatDate = (date) => new Date(date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })

  const fetchPayments = async () => {
    const isInitial = payments.value.length === 0
    if (isInitial) loading.value = true
    try {
      const params = {}
      if (filters.value.method) params.payment_method = filters.value.method
      if (filters.value.period) params.period = filters.value.period
      const response = await axios.get('/payments', { params })
      const data = response.data?.data || response.data?.payments || []
      payments.value = Array.isArray(data) ? data : []
    } catch (err) {
      console.error('fetchPayments error:', err)
    } finally {
      loading.value = false
    }
  }

  const refreshData = async () => {
    refreshing.value = true
    await fetchPayments()
    refreshing.value = false
  }

  const exportReport = (data) => {
    const rows = data || dailyRevenue.value
    const csv = [
      ['Date', 'Payments', 'Total Revenue', 'M-Pesa', 'Cash'].join(','),
      ...rows.map(d => [d.date, d.count, d.total, d.mpesa, d.cash].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `payment-report-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  watch(() => [filters.value.period, filters.value.method], () => fetchPayments())

  return {
    loading, refreshing, payments, filters,
    stats, paymentMethods, dailyRevenue,
    formatMoney, formatDate,
    fetchPayments, refreshData, exportReport
  }
}
