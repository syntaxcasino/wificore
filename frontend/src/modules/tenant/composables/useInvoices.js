import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'

export function useInvoices() {
  const { error: showError } = useToast()

  const loading = ref(false)
  const invoices = ref([])
  const customers = ref([])
  const formSubmitting = ref(false)
  const formMessage = ref({ type: '', text: '' })

  const defaultFormData = () => ({
    customer_id: '',
    due_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    items: [{ description: '', quantity: 1, unit_price: 0 }]
  })

  const formData = ref(defaultFormData())

  const stats = computed(() => {
    const paid = invoices.value.filter(i => i.status === 'paid').length
    const pending = invoices.value.filter(i => i.status === 'sent').length
    const overdue = invoices.value.filter(i => i.status === 'overdue').length
    return { total: invoices.value.length, paid, pending, overdue }
  })

  const calculateTotal = computed(() =>
    formData.value.items.reduce((sum, item) =>
      sum + (Number(item.quantity) || 0) * (Number(item.unit_price) || 0), 0)
  )

  const formatMoney = (amount) =>
    new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(amount)

  const formatDate = (date) =>
    date ? new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A'

  const fetchInvoices = async () => {
    loading.value = true
    try {
      const response = await axios.get('/billing/invoices')
      const data = response.data?.invoices?.data || response.data?.invoices || response.data?.data || []
      invoices.value = data.map(i => ({
        id: i.id,
        invoice_number: i.invoice_number || `#${i.id}`,
        customer_name: i.customer_name || i.customer?.name || 'Unknown',
        customer_email: i.customer_email || i.customer?.email || '',
        invoice_date: i.invoice_date || i.created_at,
        due_date: i.due_date,
        total_amount: Number(i.total_amount) || 0,
        status: i.status || 'draft'
      }))
    } catch (err) {
      console.error('fetchInvoices error:', err)
    } finally {
      loading.value = false
    }
  }

  const fetchCustomers = async () => {
    try {
      const response = await axios.get('/users/customers', { params: { per_page: 1000 } })
      customers.value = response.data?.customers?.data || response.data?.customers || []
    } catch (err) {
      console.error('fetchCustomers error:', err)
    }
  }

  const markAsPaid = async (invoice) => {
    try {
      await axios.patch(`/billing/invoices/${invoice.id}`, { status: 'paid', paid_at: new Date().toISOString() })
      await fetchInvoices()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to mark as paid')
    }
  }

  const resetForm = () => {
    formData.value = defaultFormData()
    formMessage.value = { type: '', text: '' }
  }

  const addItem = () => formData.value.items.push({ description: '', quantity: 1, unit_price: 0 })

  const removeItem = (index) => {
    if (formData.value.items.length > 1) formData.value.items.splice(index, 1)
  }

  const submitInvoice = async (onSuccess) => {
    if (!formData.value.customer_id) {
      formMessage.value = { type: 'error', text: 'Please select a customer' }
      return
    }
    if (formData.value.items.some(i => !i.description)) {
      formMessage.value = { type: 'error', text: 'All items must have a description' }
      return
    }
    formSubmitting.value = true
    try {
      await axios.post('/billing/invoices', { ...formData.value, total_amount: calculateTotal.value })
      onSuccess?.()
      await fetchInvoices()
    } catch (err) {
      formMessage.value = { type: 'error', text: err.response?.data?.message || 'Failed to create invoice' }
    } finally {
      formSubmitting.value = false
    }
  }

  const viewInvoice = (invoice) => window.open(`/billing/invoices/${invoice.id}/view`, '_blank')
  const downloadInvoice = (invoice) => window.open(`/billing/invoices/${invoice.id}/pdf`, '_blank')

  const exportInvoices = () => {
    const csv = [
      ['Invoice #', 'Customer', 'Date', 'Due Date', 'Amount', 'Status'].join(','),
      ...invoices.value.map(i =>
        [i.invoice_number, i.customer_name, i.invoice_date, i.due_date, i.total_amount, i.status].join(','))
    ].join('\n')
    const blob = new Blob([csv], { type: 'text/csv' })
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`
    a.click()
    URL.revokeObjectURL(url)
  }

  return {
    loading, invoices, customers, formSubmitting, formMessage, formData,
    stats, calculateTotal,
    formatMoney, formatDate,
    fetchInvoices, fetchCustomers, markAsPaid,
    resetForm, addItem, removeItem, submitInvoice,
    viewInvoice, downloadInvoice, exportInvoices
  }
}
