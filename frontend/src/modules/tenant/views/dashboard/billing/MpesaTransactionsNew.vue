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
      <BaseButton v-if="filteredData.length" @click="handleExport" variant="secondary" size="sm">
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
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
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
      <div class="hidden md:flex bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
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
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="tx in paginatedData" :key="tx.id" class="hover:bg-green-50/50 transition-colors cursor-pointer" @click="viewTransaction(tx)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-full flex items-center justify-center" :class="getIconBg(tx.status)">
                      <Smartphone class="w-5 h-5" :class="getIconColor(tx.status)" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ tx.mpesa_receipt }}</div>
                      <div class="text-xs text-slate-500 font-mono">{{ tx.transaction_id }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ tx.customer_name }}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">Ref: {{ tx.account_reference || 'N/A' }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-900">{{ formatPhone(tx.phone_number) }}</td>
                <td class="px-6 py-4">
                  <div class="text-sm font-bold text-green-600">KES {{ formatMoney(tx.amount) }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="tx.status" size="sm" />
                </td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ formatDateTime(tx.transaction_date) }}</td>
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
    width="60%"
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
            <div class="text-xs text-slate-500 dark:text-slate-400">M-Pesa Receipt</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTransaction.mpesa_receipt }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Transaction ID</div>
            <div class="text-sm font-medium text-slate-900 font-mono">{{ selectedTransaction.transaction_id }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Amount</div>
            <div class="text-sm font-bold text-green-600">KES {{ formatMoney(selectedTransaction.amount) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Transaction Date</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatDateTime(selectedTransaction.transaction_date) }}</div>
          </div>
        </div>
      </div>

      <!-- Customer Info -->
      <div class="bg-slate-50 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-slate-700 mb-3">Customer Information</h3>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Name</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTransaction.customer_name }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Phone Number</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatPhone(selectedTransaction.phone_number) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Account Reference</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTransaction.account_reference || 'N/A' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500 dark:text-slate-400">Business Short Code</div>
            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTransaction.business_short_code || 'N/A' }}</div>
          </div>
        </div>
      </div>

      <!-- Additional Details -->
      <div v-if="selectedTransaction.description" class="bg-slate-50 rounded-lg p-4">
        <h3 class="text-sm font-semibold text-slate-700 mb-2">Description</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400">{{ selectedTransaction.description }}</p>
      </div>
    </div>

    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showDetailsModal = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
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
import { ref, computed, onMounted } from 'vue'
import { Smartphone, RefreshCw, Download, X, Eye, CheckCircle, Clock, XCircle, TrendingUp } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import { useMpesaTransactions } from '@/modules/tenant/composables/useMpesaTransactions.js'

const {
  loading, refreshing, error, transactions, stats,
  formatMoney, formatPhone, formatDateTime,
  getIconBg, getIconColor, getStatusBanner, getStatusMessage,
  fetchTransactions, checkStatus, retryTransaction, exportTransactions
} = useMpesaTransactions()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showDetailsModal = ref(false)
const selectedTransaction = ref(null)
const filters = ref({ status: '', period: '' })

const getStatusIcon = (status) => {
  const icons = { completed: CheckCircle, pending: Clock, failed: XCircle, reversed: RefreshCw }
  return icons[status] || Clock
}

const filteredData = computed(() => {
  let data = transactions.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(t =>
      t.mpesa_receipt.toLowerCase().includes(query) ||
      t.transaction_id.toLowerCase().includes(query) ||
      t.customer_name.toLowerCase().includes(query) ||
      t.phone_number.includes(query)
    )
  }
  if (filters.value.status) data = data.filter(t => t.status === filters.value.status)
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
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.period || searchQuery.value)

const getTxActions = (tx) => [
  { label: 'View', onClick: () => viewTransaction(tx), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  ...(tx.status === 'pending' ? [{ label: 'Check Status', onClick: () => checkStatus(tx, selectedTransaction), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' }] : [])
]

const clearFilters = () => { filters.value = { status: '', period: '' }; searchQuery.value = '' }
const viewTransaction = (transaction) => { selectedTransaction.value = transaction; showDetailsModal.value = true }
const handleExport = () => exportTransactions(filteredData.value)

onMounted(() => { fetchTransactions() })
</script>

<style scoped>
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
:global(.dark) ::-webkit-scrollbar-track { background: #1e293b; }
:global(.dark) ::-webkit-scrollbar-thumb { background: #475569; }
</style>
