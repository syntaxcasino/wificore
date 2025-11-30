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
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
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
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
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
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  CreditCard, RefreshCw, Download, Plus, X, Eye, Send,
  Smartphone, Banknote, Building, TrendingUp
} from 'lucide-vue-next'
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

const filters = ref({
  method: '',
  period: ''
})

// Mock data
const mockPayments = [
  {
    id: 1,
    reference: 'PAY-2025-001',
    transaction_id: 'TXN001',
    customer_name: 'John Doe',
    customer_email: 'john@example.com',
    amount: 5000,
    method: 'mpesa',
    invoice_number: 'INV-2025-001',
    payment_date: new Date().toISOString()
  },
  {
    id: 2,
    reference: 'PAY-2025-002',
    transaction_id: 'TXN002',
    customer_name: 'Jane Smith',
    customer_email: 'jane@example.com',
    amount: 3500,
    method: 'cash',
    invoice_number: 'INV-2025-002',
    payment_date: new Date(Date.now() - 86400000).toISOString()
  },
  {
    id: 3,
    reference: 'PAY-2025-003',
    transaction_id: 'TXN003',
    customer_name: 'Bob Johnson',
    customer_email: 'bob@example.com',
    amount: 7500,
    method: 'bank',
    invoice_number: 'INV-2025-003',
    payment_date: new Date(Date.now() - 172800000).toISOString()
  },
  {
    id: 4,
    reference: 'PAY-2025-004',
    transaction_id: 'TXN004',
    customer_name: 'Alice Williams',
    customer_email: 'alice@example.com',
    amount: 2000,
    method: 'mpesa',
    invoice_number: null,
    payment_date: new Date(Date.now() - 259200000).toISOString()
  }
]

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
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    payments.value = mockPayments
  } catch (err) {
    error.value = 'Failed to load payments. Please try again.'
    console.error('Error fetching payments:', err)
  } finally {
    loading.value = false
  }
}

const refreshPayments = async () => {
  refreshing.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    payments.value = mockPayments
  } catch (err) {
    error.value = 'Failed to refresh payments.'
    console.error('Error refreshing payments:', err)
  } finally {
    refreshing.value = false
  }
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
  console.log('View payment:', payment)
  // TODO: Implement payment details modal
}

const downloadReceipt = (payment) => {
  console.log('Download receipt:', payment)
  // TODO: Implement PDF download
  alert('Receipt download feature coming soon!')
}

const sendReceipt = async (payment) => {
  if (!confirm(`Send receipt to ${payment.customer_email}?`)) return
  
  try {
    // TODO: Implement send receipt API call
    await new Promise(resolve => setTimeout(resolve, 500))
    alert('Receipt sent successfully!')
  } catch (err) {
    console.error('Error sending receipt:', err)
    alert('Failed to send receipt')
  }
}

const recordPayment = () => {
  console.log('Record payment')
  // TODO: Implement record payment modal/page
  alert('Record payment feature coming soon!')
}

const exportPayments = () => {
  console.log('Export payments')
  // TODO: Implement export functionality
  alert('Export feature coming soon!')
}

// Lifecycle
onMounted(() => {
  fetchPayments()
})
</script>
