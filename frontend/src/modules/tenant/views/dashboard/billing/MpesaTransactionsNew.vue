<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="M-Pesa Transactions"
      subtitle="Monitor and manage M-Pesa payment transactions"
      icon="Smartphone"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshTransactions" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportTransactions" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Total Received</div>
              <div class="text-2xl font-bold text-green-900">KES {{ formatMoney(stats.totalReceived) }}</div>
              <div class="text-xs text-green-600 mt-1">{{ stats.successCount }} transactions</div>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
              <CheckCircle class="w-6 h-6 text-green-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Today</div>
              <div class="text-2xl font-bold text-blue-900">KES {{ formatMoney(stats.todayAmount) }}</div>
              <div class="text-xs text-blue-600 mt-1">{{ stats.todayCount }} transactions</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <TrendingUp class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Pending</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.pendingCount }}</div>
              <div class="text-xs text-amber-600 mt-1">Awaiting confirmation</div>
            </div>
            <div class="p-3 bg-amber-100 rounded-lg">
              <Clock class="w-6 h-6 text-amber-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-red-50 to-rose-50 rounded-lg p-4 border border-red-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-red-600 font-medium mb-1">Failed</div>
              <div class="text-2xl font-bold text-red-900">{{ stats.failedCount }}</div>
              <div class="text-xs text-red-600 mt-1">{{ stats.failedRate }}% failure rate</div>
            </div>
            <div class="p-3 bg-red-100 rounded-lg">
              <XCircle class="w-6 h-6 text-red-600" />
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
          <BaseSearch v-model="searchQuery" placeholder="Search by phone, reference, transaction ID..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="completed">Completed</option>
            <option value="pending">Pending</option>
            <option value="failed">Failed</option>
            <option value="reversed">Reversed</option>
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
          <BaseBadge variant="info">{{ filteredData.length }} transactions</BaseBadge>
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
            <BaseButton @click="fetchTransactions" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No transactions found' : 'No M-Pesa transactions yet'"
          :description="searchQuery ? 'No transactions match your search criteria.' : 'M-Pesa transactions will appear here once customers make payments.'"
          icon="Smartphone"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Transaction</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Date & Time</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="transaction in paginatedData"
                  :key="transaction.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="viewTransaction(transaction)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 rounded-lg" :class="getIconBg(transaction.status)">
                        <Smartphone class="w-4 h-4" :class="getIconColor(transaction.status)" />
                      </div>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">{{ transaction.mpesa_receipt }}</div>
                        <div class="text-xs text-slate-500 font-mono">{{ transaction.transaction_id }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ transaction.customer_name }}</div>
                    <div class="text-xs text-slate-500">{{ formatPhone(transaction.phone_number) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-bold text-slate-900">KES {{ formatMoney(transaction.amount) }}</div>
                    <div v-if="transaction.account_reference" class="text-xs text-slate-500">
                      Ref: {{ transaction.account_reference }}
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge 
                      :variant="getStatusVariant(transaction.status)" 
                      :dot="transaction.status === 'completed'"
                      :pulse="transaction.status === 'pending'"
                    >
                      {{ transaction.status }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDate(transaction.transaction_date) }}</div>
                    <div class="text-xs text-slate-500">{{ formatTime(transaction.transaction_date) }}</div>
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewTransaction(transaction)" variant="ghost" size="sm" title="View Details">
                        <Eye class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        v-if="transaction.status === 'pending'"
                        @click="checkStatus(transaction)" 
                        variant="ghost" 
                        size="sm"
                        title="Check Status"
                      >
                        <RefreshCw class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        v-if="transaction.status === 'failed'"
                        @click="retryTransaction(transaction)" 
                        variant="warning" 
                        size="sm"
                      >
                        Retry
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
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} transactions
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
      />
    </PageFooter>

    <!-- Transaction Details Modal -->
    <BaseModal v-model="showDetailsModal" title="Transaction Details" size="lg">
      <div v-if="selectedTransaction" class="space-y-4">
        <!-- Status Banner -->
        <div class="rounded-lg p-4" :class="getStatusBanner(selectedTransaction.status)">
          <div class="flex items-center gap-3">
            <component :is="getStatusIcon(selectedTransaction.status)" class="w-6 h-6" />
            <div>
              <div class="font-semibold text-sm">{{ selectedTransaction.status.toUpperCase() }}</div>
              <div class="text-xs opacity-90">{{ getStatusMessage(selectedTransaction.status) }}</div>
            </div>
          </div>
        </div>

        <!-- Transaction Info -->
        <div class="bg-slate-50 rounded-lg p-4">
          <h3 class="text-sm font-semibold text-slate-700 mb-3">Transaction Information</h3>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-slate-500">M-Pesa Receipt</div>
              <div class="text-sm font-medium text-slate-900">{{ selectedTransaction.mpesa_receipt }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Transaction ID</div>
              <div class="text-sm font-medium text-slate-900 font-mono">{{ selectedTransaction.transaction_id }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Amount</div>
              <div class="text-sm font-bold text-green-600">KES {{ formatMoney(selectedTransaction.amount) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Transaction Date</div>
              <div class="text-sm font-medium text-slate-900">{{ formatDateTime(selectedTransaction.transaction_date) }}</div>
            </div>
          </div>
        </div>

        <!-- Customer Info -->
        <div class="bg-slate-50 rounded-lg p-4">
          <h3 class="text-sm font-semibold text-slate-700 mb-3">Customer Information</h3>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <div class="text-xs text-slate-500">Name</div>
              <div class="text-sm font-medium text-slate-900">{{ selectedTransaction.customer_name }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Phone Number</div>
              <div class="text-sm font-medium text-slate-900">{{ formatPhone(selectedTransaction.phone_number) }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Account Reference</div>
              <div class="text-sm font-medium text-slate-900">{{ selectedTransaction.account_reference || 'N/A' }}</div>
            </div>
            <div>
              <div class="text-xs text-slate-500">Business Short Code</div>
              <div class="text-sm font-medium text-slate-900">{{ selectedTransaction.business_short_code || 'N/A' }}</div>
            </div>
          </div>
        </div>

        <!-- Additional Details -->
        <div v-if="selectedTransaction.description" class="bg-slate-50 rounded-lg p-4">
          <h3 class="text-sm font-semibold text-slate-700 mb-2">Description</h3>
          <p class="text-sm text-slate-600">{{ selectedTransaction.description }}</p>
        </div>
      </div>

      <template #footer>
        <BaseButton @click="showDetailsModal = false" variant="ghost">Close</BaseButton>
        <BaseButton 
          v-if="selectedTransaction?.status === 'pending'"
          @click="checkStatus(selectedTransaction)" 
          variant="primary"
        >
          <RefreshCw class="w-4 h-4 mr-1" />
          Check Status
        </BaseButton>
      </template>
    </BaseModal>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { 
  Smartphone, RefreshCw, Download, X, Eye,
  CheckCircle, Clock, XCircle, TrendingUp
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
import BaseModal from '@/modules/common/components/base/BaseModal.vue'

// Breadcrumbs
const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Billing', to: '/dashboard/billing' },
  { label: 'M-Pesa Transactions' }
]

// State
const loading = ref(false)
const refreshing = ref(false)
const error = ref(null)
const transactions = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsModal = ref(false)
const selectedTransaction = ref(null)

const filters = ref({
  status: '',
  period: ''
})

// Mock data
const mockTransactions = [
  {
    id: 1,
    mpesa_receipt: 'QGH7KLM9XY',
    transaction_id: 'ws_CO_12102025120145',
    customer_name: 'John Doe',
    phone_number: '254712345678',
    amount: 1000,
    status: 'completed',
    account_reference: 'ACC001',
    business_short_code: '174379',
    transaction_date: new Date().toISOString(),
    description: 'Package payment'
  },
  {
    id: 2,
    mpesa_receipt: 'QGH7KLM9XZ',
    transaction_id: 'ws_CO_12102025120246',
    customer_name: 'Jane Smith',
    phone_number: '254723456789',
    amount: 500,
    status: 'pending',
    account_reference: 'ACC002',
    business_short_code: '174379',
    transaction_date: new Date(Date.now() - 300000).toISOString(),
    description: 'Top-up payment'
  },
  {
    id: 3,
    mpesa_receipt: 'QGH7KLM9YA',
    transaction_id: 'ws_CO_12102025120347',
    customer_name: 'Bob Johnson',
    phone_number: '254734567890',
    amount: 2000,
    status: 'failed',
    account_reference: 'ACC003',
    business_short_code: '174379',
    transaction_date: new Date(Date.now() - 600000).toISOString(),
    description: 'Insufficient funds'
  }
]

// Computed
const stats = computed(() => {
  const completed = transactions.value.filter(t => t.status === 'completed')
  const today = transactions.value.filter(t => {
    const txDate = new Date(t.transaction_date)
    const now = new Date()
    return txDate.toDateString() === now.toDateString()
  })
  
  const totalReceived = completed.reduce((sum, t) => sum + t.amount, 0)
  const todayAmount = today.filter(t => t.status === 'completed').reduce((sum, t) => sum + t.amount, 0)
  const pendingCount = transactions.value.filter(t => t.status === 'pending').length
  const failedCount = transactions.value.filter(t => t.status === 'failed').length
  const failedRate = transactions.value.length > 0 
    ? Math.round((failedCount / transactions.value.length) * 100) 
    : 0
  
  return {
    totalReceived,
    successCount: completed.length,
    todayAmount,
    todayCount: today.length,
    pendingCount,
    failedCount,
    failedRate
  }
})

const filteredData = computed(() => {
  let data = transactions.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(t =>
      t.mpesa_receipt.toLowerCase().includes(query) ||
      t.transaction_id.toLowerCase().includes(query) ||
      t.customer_name.toLowerCase().includes(query) ||
      t.phone_number.includes(query)
    )
  }

  // Status filter
  if (filters.value.status) {
    data = data.filter(t => t.status === filters.value.status)
  }

  // Period filter
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(t => {
      const txDate = new Date(t.transaction_date)
      switch (filters.value.period) {
        case 'today':
          return txDate.toDateString() === now.toDateString()
        case 'yesterday':
          const yesterday = new Date(now.getTime() - 86400000)
          return txDate.toDateString() === yesterday.toDateString()
        case 'week':
          const weekAgo = new Date(now.getTime() - 7 * 86400000)
          return txDate >= weekAgo
        case 'month':
          return txDate.getMonth() === now.getMonth() && txDate.getFullYear() === now.getFullYear()
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
  return filters.value.status || filters.value.period || searchQuery.value
})

// Methods
const fetchTransactions = async () => {
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    transactions.value = mockTransactions
  } catch (err) {
    error.value = 'Failed to load transactions. Please try again.'
    console.error('Error fetching transactions:', err)
  } finally {
    loading.value = false
  }
}

const refreshTransactions = async () => {
  refreshing.value = true
  error.value = null
  
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    transactions.value = mockTransactions
  } catch (err) {
    error.value = 'Failed to refresh transactions.'
    console.error('Error refreshing transactions:', err)
  } finally {
    refreshing.value = false
  }
}

const clearFilters = () => {
  filters.value = {
    status: '',
    period: ''
  }
  searchQuery.value = ''
}

const getStatusVariant = (status) => {
  const variants = {
    completed: 'success',
    pending: 'warning',
    failed: 'danger',
    reversed: 'secondary'
  }
  return variants[status] || 'default'
}

const getIconBg = (status) => {
  const bgs = {
    completed: 'bg-green-100',
    pending: 'bg-amber-100',
    failed: 'bg-red-100',
    reversed: 'bg-slate-100'
  }
  return bgs[status] || 'bg-slate-100'
}

const getIconColor = (status) => {
  const colors = {
    completed: 'text-green-600',
    pending: 'text-amber-600',
    failed: 'text-red-600',
    reversed: 'text-slate-600'
  }
  return colors[status] || 'text-slate-600'
}

const getStatusBanner = (status) => {
  const banners = {
    completed: 'bg-green-50 border border-green-200 text-green-900',
    pending: 'bg-amber-50 border border-amber-200 text-amber-900',
    failed: 'bg-red-50 border border-red-200 text-red-900',
    reversed: 'bg-slate-50 border border-slate-200 text-slate-900'
  }
  return banners[status] || 'bg-slate-50 border border-slate-200 text-slate-900'
}

const getStatusIcon = (status) => {
  const icons = {
    completed: CheckCircle,
    pending: Clock,
    failed: XCircle,
    reversed: RefreshCw
  }
  return icons[status] || Clock
}

const getStatusMessage = (status) => {
  const messages = {
    completed: 'Payment successfully received and processed',
    pending: 'Awaiting M-Pesa confirmation',
    failed: 'Transaction failed or was cancelled',
    reversed: 'Transaction has been reversed'
  }
  return messages[status] || 'Unknown status'
}

const formatMoney = (amount) => {
  return new Intl.NumberFormat('en-KE').format(amount)
}

const formatPhone = (phone) => {
  // Format: 254712345678 -> +254 712 345 678
  if (!phone) return 'N/A'
  return `+${phone.slice(0, 3)} ${phone.slice(3, 6)} ${phone.slice(6, 9)} ${phone.slice(9)}`
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

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const viewTransaction = (transaction) => {
  selectedTransaction.value = transaction
  showDetailsModal.value = true
}

const checkStatus = async (transaction) => {
  console.log('Check status:', transaction)
  // TODO: Implement status check API call
  alert('Checking transaction status...')
}

const retryTransaction = async (transaction) => {
  if (!confirm(`Retry transaction for ${transaction.customer_name}?`)) return
  
  console.log('Retry transaction:', transaction)
  // TODO: Implement retry logic
  alert('Retry feature coming soon!')
}

const exportTransactions = () => {
  console.log('Export transactions')
  // TODO: Implement export functionality
  alert('Export feature coming soon!')
}

// Auto-refresh every 30 seconds
let refreshInterval

onMounted(() => {
  fetchTransactions()
  refreshInterval = setInterval(refreshTransactions, 30000)
})

onUnmounted(() => {
  if (refreshInterval) clearInterval(refreshInterval)
})
</script>
