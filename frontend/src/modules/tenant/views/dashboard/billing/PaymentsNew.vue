<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Payment History"
      subtitle="Track all customer payments and transactions"
      icon="CreditCard"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshPayments" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportPayments" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
        <BaseButton @click="recordPayment" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Record Payment
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Payments</div>
              <div class="text-2xl font-bold text-blue-900">KES {{ formatMoney(stats.total) }}</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <CreditCard class="w-5 h-5 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">M-Pesa</div>
              <div class="text-2xl font-bold text-green-900">KES {{ formatMoney(stats.mpesa) }}</div>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
              <Smartphone class="w-5 h-5 text-green-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Cash</div>
              <div class="text-2xl font-bold text-purple-900">KES {{ formatMoney(stats.cash) }}</div>
            </div>
            <div class="p-3 bg-purple-100 rounded-lg">
              <Banknote class="w-5 h-5 text-purple-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-cyan-50 to-blue-50 rounded-lg p-4 border border-cyan-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-cyan-600 font-medium mb-1">Bank Transfer</div>
              <div class="text-2xl font-bold text-cyan-900">KES {{ formatMoney(stats.bank) }}</div>
            </div>
            <div class="p-3 bg-cyan-100 rounded-lg">
              <Building class="w-5 h-5 text-cyan-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Today</div>
              <div class="text-2xl font-bold text-amber-900">KES {{ formatMoney(stats.today) }}</div>
            </div>
            <div class="p-3 bg-amber-100 rounded-lg">
              <TrendingUp class="w-5 h-5 text-amber-600" />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Search and Filters Bar -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search by customer, reference, invoice..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.method" placeholder="All Methods" class="w-40">
            <option value="">All Methods</option>
            <option value="mpesa">M-Pesa</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank Transfer</option>
            <option value="card">Card</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.period" placeholder="All Time" class="w-36">
            <option value="">All Time</option>
            <option value="today">Today</option>
            <option value="yesterday">Yesterday</option>
            <option value="week">This Week</option>
            <option value="month">This Month</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Results Count -->
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} payments</BaseBadge>
        </div>
      </div>
    </div>

    <!-- Content -->
    <PageContent :padding="false">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="5" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="p-6">
        <BaseAlert variant="danger" :title="error" dismissible>
          <div class="mt-2">
            <BaseButton @click="fetchPayments" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No payments found' : 'No payments yet'"
          :description="searchQuery ? 'No payments match your search criteria.' : 'Payment records will appear here once customers make payments.'"
          icon="CreditCard"
          actionText="Record Payment"
          actionIcon="Plus"
          @action="recordPayment"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Payment</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Method</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Invoice</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Date</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="payment in paginatedData"
                  :key="payment.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="viewPayment(payment)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 bg-blue-100 rounded-lg">
                        <CreditCard class="w-4 h-4 text-blue-600" />
                      </div>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">{{ payment.reference }}</div>
                        <div class="text-xs text-slate-500">{{ payment.transaction_id }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ payment.customer_name }}</div>
                    <div class="text-xs text-slate-500">{{ payment.customer_email }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-bold text-green-600">KES {{ formatMoney(payment.amount) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="getMethodVariant(payment.method)">
                      <component :is="getMethodIcon(payment.method)" class="w-3 h-3 mr-1" />
                      {{ payment.method }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div v-if="payment.invoice_number" class="text-sm text-slate-900">{{ payment.invoice_number }}</div>
                    <div v-else class="text-xs text-slate-400">No invoice</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDate(payment.payment_date) }}</div>
                    <div class="text-xs text-slate-500">{{ formatTime(payment.payment_date) }}</div>
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewPayment(payment)" variant="ghost" size="sm" title="View Details">
                        <Eye class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="downloadReceipt(payment)" variant="ghost" size="sm" title="Download Receipt">
                        <Download class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="sendReceipt(payment)" variant="ghost" size="sm" title="Email Receipt">
                        <Send class="w-3 h-3" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Footer -->
    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} payments
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
      />
    </PageFooter>
    <!-- Payment Details Overlay -->
    <SlideOverlay v-model="showDetailsOverlay" title="Payment Details" :subtitle="selectedPayment?.reference || selectedPayment?.transaction_id" icon="CreditCard" width="lg">
      <div v-if="selectedPayment" class="space-y-6 p-6">
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500 mb-1">Reference</div>
            <div class="text-sm font-medium text-slate-900">{{ selectedPayment.reference || selectedPayment.transaction_id || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Transaction ID</div>
            <div class="text-sm font-mono text-slate-900">{{ selectedPayment.transaction_id || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Customer</div>
            <div class="text-sm font-medium text-slate-900">{{ selectedPayment.customer_name || selectedPayment.user?.name || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Phone</div>
            <div class="text-sm text-slate-900">{{ selectedPayment.phone_number || selectedPayment.user?.phone_number || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Amount</div>
            <div class="text-lg font-bold text-green-600">KES {{ formatMoney(selectedPayment.amount) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Method</div>
            <BaseBadge :variant="getMethodVariant(selectedPayment.method || selectedPayment.payment_method)">{{ selectedPayment.method || selectedPayment.payment_method || 'N/A' }}</BaseBadge>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Status</div>
            <BaseBadge :variant="selectedPayment.status === 'completed' ? 'success' : selectedPayment.status === 'pending' ? 'warning' : 'danger'">{{ selectedPayment.status || 'N/A' }}</BaseBadge>
          </div>
          <div>
            <div class="text-xs text-slate-500 mb-1">Date</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedPayment.payment_date || selectedPayment.created_at) }}</div>
          </div>
          <div v-if="selectedPayment.package">
            <div class="text-xs text-slate-500 mb-1">Package</div>
            <div class="text-sm text-slate-900">{{ selectedPayment.package?.name || 'N/A' }}</div>
          </div>
          <div v-if="selectedPayment.invoice_number">
            <div class="text-xs text-slate-500 mb-1">Invoice</div>
            <div class="text-sm text-slate-900">{{ selectedPayment.invoice_number }}</div>
          </div>
        </div>
      </div>
      <template #footer>
        <BaseButton @click="showDetailsOverlay = false" variant="ghost">Close</BaseButton>
      </template>
    </SlideOverlay>

    <!-- Record Payment Overlay -->
    <SlideOverlay v-model="showRecordOverlay" title="Record Payment" subtitle="Manually record a customer payment" icon="Plus" width="lg">
      <div class="space-y-4 p-6">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Phone</label>
          <input v-model="recordForm.phone_number" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. 254712345678" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount (KES)</label>
          <input v-model.number="recordForm.amount" type="number" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Payment Method</label>
          <select v-model="recordForm.payment_method" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="cash">Cash</option>
            <option value="mpesa">M-Pesa</option>
            <option value="bank">Bank Transfer</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Transaction ID (optional)</label>
          <input v-model="recordForm.transaction_id" type="text" class="w-full px-3 py-2 border border-slate-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="e.g. TXN123" />
        </div>
      </div>
      <template #footer>
        <div class="flex items-center justify-between w-full">
          <BaseButton @click="showRecordOverlay = false" variant="ghost">Cancel</BaseButton>
          <BaseButton @click="submitRecordPayment" variant="primary" :loading="recordSubmitting">Record Payment</BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  CreditCard, RefreshCw, Download, Plus, X, Eye, Send,
  Smartphone, Banknote, Building, TrendingUp
} from 'lucide-vue-next'
import axios from 'axios'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'Payments' }
]

// State
const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const payments = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsOverlay = ref(false)
const selectedPayment = ref(null)
const showRecordOverlay = ref(false)
const recordSubmitting = ref(false)
const recordForm = ref({
  phone_number: '',
  amount: 0,
  payment_method: 'cash',
  transaction_id: ''
})

const filters = ref({
  method: '',
  period: ''
})

// Computed
const stats = computed(() => {
  const total = payments.value.reduce((sum, p) => sum + p.amount, 0)
  const mpesa = payments.value.filter(p => p.method === 'mpesa').reduce((sum, p) => sum + p.amount, 0)
  const cash = payments.value.filter(p => p.method === 'cash').reduce((sum, p) => sum + p.amount, 0)
  const bank = payments.value.filter(p => p.method === 'bank').reduce((sum, p) => sum + p.amount, 0)
  
  const today = payments.value.filter(p => {
    const payDate = new Date(p.payment_date)
    const now = new Date()
    return payDate.toDateString() === now.toDateString()
  }).reduce((sum, p) => sum + p.amount, 0)
  
  return { total, mpesa, cash, bank, today }
})

const filteredData = computed(() => {
  let data = payments.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(p =>
      p.reference.toLowerCase().includes(query) ||
      p.customer_name.toLowerCase().includes(query) ||
      p.customer_email.toLowerCase().includes(query) ||
      (p.invoice_number && p.invoice_number.toLowerCase().includes(query))
    )
  }

  // Method filter
  if (filters.value.method) {
    data = data.filter(p => p.method === filters.value.method)
  }

  // Period filter
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(p => {
      const payDate = new Date(p.payment_date)
      switch (filters.value.period) {
        case 'today':
          return payDate.toDateString() === now.toDateString()
        case 'yesterday':
          const yesterday = new Date(now.getTime() - 86400000)
          return payDate.toDateString() === yesterday.toDateString()
        case 'week':
          const weekAgo = new Date(now.getTime() - 7 * 86400000)
          return payDate >= weekAgo
        case 'month':
          return payDate.getMonth() === now.getMonth() && payDate.getFullYear() === now.getFullYear()
        default:
          return true
      }
    })
  }

  return data
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const paginationInfo = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(start + itemsPerPage.value - 1, filteredData.value.length)
  return {
    start,
    end,
    total: filteredData.value.length
  }
})

const hasActiveFilters = computed(() => {
  return filters.value.method || filters.value.period || searchQuery.value
})

// Methods
const fetchPayments = async () => {
  const isInitial = payments.value.length === 0
  if (isInitial) {
    loading.value = true
    error.value = null
  } else {
    refreshing.value = true
  }
  
  try {
    const params = {}
    if (searchQuery.value) params.search = searchQuery.value
    if (filters.value.method) params.payment_method = filters.value.method
    params.per_page = 100

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
    if (isInitial) {
      error.value = err.response?.data?.message || 'Failed to load payments.'
    }
    console.error('fetchPayments error:', err)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const refreshPayments = async () => {
  await fetchPayments()
}

const clearFilters = () => {
  filters.value = {
    method: '',
    period: ''
  }
  searchQuery.value = ''
}

const getMethodVariant = (method) => {
  const variants = {
    mpesa: 'success',
    cash: 'warning',
    bank: 'info',
    card: 'purple'
  }
  return variants[method] || 'default'
}

const getMethodIcon = (method) => {
  const icons = {
    mpesa: Smartphone,
    cash: Banknote,
    bank: Building,
    card: CreditCard
  }
  return icons[method] || CreditCard
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const formatTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit'
  })
}

const viewPayment = (payment) => {
  selectedPayment.value = payment
  showDetailsOverlay.value = true
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
  if (!confirm(`Send receipt to ${payment.customer_email || payment.phone_number}?`)) return
  alert('Receipt sending is not yet configured.')
}

const recordPayment = () => {
  recordForm.value = { phone_number: '', amount: 0, payment_method: 'cash', transaction_id: '' }
  showRecordOverlay.value = true
}

const submitRecordPayment = async () => {
  if (!recordForm.value.phone_number || !recordForm.value.amount) {
    alert('Phone number and amount are required.')
    return
  }
  recordSubmitting.value = true
  try {
    await axios.post('/pppoe/payments', recordForm.value)
    showRecordOverlay.value = false
    await fetchPayments()
  } catch (err) {
    console.error('Record payment error:', err)
    alert(err.response?.data?.message || 'Failed to record payment')
  } finally {
    recordSubmitting.value = false
  }
}

const exportPayments = () => {
  const csv = [
    ['Reference', 'Customer', 'Amount', 'Method', 'Status', 'Date'].join(','),
    ...filteredData.value.map(p => [
      p.reference, p.customer_name, p.amount, p.method, p.status, p.payment_date
    ].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `payments-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// Lifecycle
onMounted(() => {
  fetchPayments()
})
</script>
