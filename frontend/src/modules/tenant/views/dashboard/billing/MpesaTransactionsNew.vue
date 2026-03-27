<template>
  <DataViewContainer
    title="M-Pesa Transactions"
    subtitle="View and manage M-Pesa payments"
    color-theme="green"
    v-model:search-model="searchQuery"
    search-placeholder="Search transactions..."
    :stats="[
      { color: 'bg-green-500', value: formatMoney(stats.totalReceived) },
      { color: 'bg-emerald-500', value: stats.successCount },
      { color: 'bg-amber-500', value: stats.pendingCount },
      { color: 'bg-red-500', value: stats.failedCount }
    ]"
    :total="transactions.length"
    :loading="loading"
    @refresh="refreshTransactions"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="completed">Completed</option>
        <option value="pending">Pending</option>
        <option value="failed">Failed</option>
        <option value="reversed">Reversed</option>
      </BaseSelect>
      <BaseSelect v-model="filters.period" placeholder="All Periods" class="w-36">
        <option value="">All Periods</option>
        <option value="today">Today</option>
        <option value="yesterday">Yesterday</option>
        <option value="week">Last 7 Days</option>
        <option value="month">This Month</option>
      </BaseSelect>
      <BaseButton v-if="filteredData.length" @click="exportTransactions" variant="secondary" size="sm">
        <Download class="w-4 h-4 mr-1" /> Export
      </BaseButton>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <X class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchTransactions" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="tx in paginatedData"
          :key="tx.id"
          :title="tx.mpesa_receipt"
          :subtitle="tx.customer_name"
          :meta-lines="[{ text: formatPhone(tx.phone_number) }, { text: formatDateTime(tx.transaction_date) }, { text: 'KES ' + formatMoney(tx.amount), class: 'font-semibold text-green-600' }]"
          :status="tx.status"
          :actions="getTxActions(tx)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Receipt</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Phone</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Date</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="tx in paginatedData" :key="tx.id" class="hover:bg-green-50/50 transition-colors cursor-pointer" @click="viewTransaction(tx)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="getIconBg(tx.status)">
                      <Smartphone class="w-5 h-5" :class="getIconColor(tx.status)" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ tx.mpesa_receipt }}</div>
                      <div class="text-xs text-slate-500 font-mono">{{ tx.transaction_id }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ tx.customer_name }}</div>
                  <div class="text-xs text-slate-500">Ref: {{ tx.account_reference || 'N/A' }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-900">{{ formatPhone(tx.phone_number) }}</td>
                <td class="px-6 py-4">
                  <div class="text-sm font-bold text-green-600">KES {{ formatMoney(tx.amount) }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="tx.status" size="sm" />
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDateTime(tx.transaction_date) }}</td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewTransaction(tx)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors"><Eye class="w-3 h-3 mr-1" /> View</button>
                    <button v-if="tx.status === 'pending'" @click="checkStatus(tx)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"><RefreshCw class="w-3 h-3 mr-1" /> Check</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="transactions" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No M-Pesa Transactions'"
      :description="searchQuery ? 'No transactions match your search criteria.' : 'No M-Pesa transactions have been recorded yet.'"
      icon="smartphone"
      color-theme="green"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>

  <!-- Transaction Details SlideOverlay -->
  <SlideOverlay
    v-model="showDetailsModal"
    title="Transaction Details"
    subtitle="View M-Pesa payment information"
    icon="Smartphone"
    width="480px"
    @close="showDetailsModal = false"
  >
    <div v-if="selectedTransaction" class="p-6 space-y-4">
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
      <div class="flex gap-3">
        <button
          @click="showDetailsModal = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
        <button
          v-if="selectedTransaction?.status === 'pending'"
          @click="checkStatus(selectedTransaction)"
          class="flex-1 inline-flex items-center justify-center gap-2 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
        >
          <RefreshCw class="w-4 h-4" />
          Check Status
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Smartphone, RefreshCw, Download, X, Eye, CheckCircle, Clock, XCircle, TrendingUp } from 'lucide-vue-next'
import axios from 'axios'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

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
const filters = ref({ status: '', period: '' })

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
  const failedRate = transactions.value.length > 0 ? Math.round((failedCount / transactions.value.length) * 100) : 0
  return { totalReceived, successCount: completed.length, todayAmount, todayCount: today.length, pendingCount, failedCount, failedRate }
})

const filteredData = computed(() => {
  let data = transactions.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(t => t.mpesa_receipt.toLowerCase().includes(query) || t.transaction_id.toLowerCase().includes(query) || t.customer_name.toLowerCase().includes(query) || t.phone_number.includes(query))
  }
  if (filters.value.status) {
    data = data.filter(t => t.status === filters.value.status)
  }
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(t => {
      const txDate = new Date(t.transaction_date)
      switch (filters.value.period) {
        case 'today': return txDate.toDateString() === now.toDateString()
        case 'yesterday': return txDate.toDateString() === new Date(now.getTime() - 86400000).toDateString()
        case 'week': return txDate >= new Date(now.getTime() - 7 * 86400000)
        case 'month': return txDate.getMonth() === now.getMonth() && txDate.getFullYear() === now.getFullYear()
        default: return true
      }
    })
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredData.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.period || searchQuery.value)

// Helpers
const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
const formatPhone = (phone) => phone ? `+${phone.slice(0, 3)} ${phone.slice(3, 6)} ${phone.slice(6, 9)} ${phone.slice(9)}` : 'N/A'
const formatDateTime = (date) => date ? new Date(date).toLocaleString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A'

const getIconBg = (status) => {
  const bgs = { completed: 'bg-green-100', pending: 'bg-amber-100', failed: 'bg-red-100', reversed: 'bg-slate-100' }
  return bgs[status] || 'bg-slate-100'
}

const getIconColor = (status) => {
  const colors = { completed: 'text-green-600', pending: 'text-amber-600', failed: 'text-red-600', reversed: 'text-slate-600' }
  return colors[status] || 'text-slate-600'
}

const getStatusBanner = (status) => {
  const banners = { completed: 'bg-green-50 border border-green-200 text-green-900', pending: 'bg-amber-50 border border-amber-200 text-amber-900', failed: 'bg-red-50 border border-red-200 text-red-900', reversed: 'bg-slate-50 border border-slate-200 text-slate-900' }
  return banners[status] || 'bg-slate-50 border border-slate-200 text-slate-900'
}

const getStatusIcon = (status) => {
  const icons = { completed: CheckCircle, pending: Clock, failed: XCircle, reversed: RefreshCw }
  return icons[status] || Clock
}

const getStatusMessage = (status) => {
  const messages = { completed: 'Payment successfully received and processed', pending: 'Awaiting M-Pesa confirmation', failed: 'Transaction failed or was cancelled', reversed: 'Transaction has been reversed' }
  return messages[status] || 'Unknown status'
}

const getTxActions = (tx) => [
  { label: 'View', onClick: () => viewTransaction(tx), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  ...(tx.status === 'pending' ? [{ label: 'Check Status', onClick: () => checkStatus(tx), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' }] : [])
]

const clearFilters = () => { filters.value = { status: '', period: '' }; searchQuery.value = '' }

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
    console.error('fetchTransactions error:', err)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const refreshTransactions = async () => await fetchTransactions()
const viewTransaction = (transaction) => { selectedTransaction.value = transaction; showDetailsModal.value = true }

const checkStatus = async (transaction) => {
  try {
    const response = await axios.get(`/billing/paybill/transactions/${transaction.id}/status`)
    const updated = response.data?.transaction || response.data
    if (updated?.status) {
      const idx = transactions.value.findIndex(t => t.id === transaction.id)
      if (idx !== -1) transactions.value[idx].status = updated.status
      if (selectedTransaction.value?.id === transaction.id) selectedTransaction.value.status = updated.status
    }
  } catch (err) {
    console.error('Check status error:', err)
    alert(err.response?.data?.message || 'Failed to check transaction status')
  }
}

const retryTransaction = async (transaction) => {
  if (!confirm(`Retry transaction for ${transaction.customer_name}?`)) return
  try {
    await axios.post(`/billing/paybill/transactions/${transaction.id}/retry`)
    await fetchTransactions()
  } catch (err) {
    console.error('Retry error:', err)
    alert(err.response?.data?.message || 'Failed to retry transaction')
  }
}

const exportTransactions = () => {
  const csv = [
    ['Receipt', 'Customer', 'Phone', 'Amount', 'Status', 'Date'].join(','),
    ...filteredData.value.map(t => [t.mpesa_receipt, t.customer_name, t.phone_number, t.amount, t.status, t.transaction_date].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `mpesa-transactions-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// Auto-refresh
let refreshInterval
onMounted(() => { fetchTransactions(); refreshInterval = setInterval(refreshTransactions, 30000) })
onUnmounted(() => { if (refreshInterval) clearInterval(refreshInterval) })
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
