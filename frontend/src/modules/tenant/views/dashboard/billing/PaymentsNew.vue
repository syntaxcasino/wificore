<template>
  <DataViewContainer
    title="Payment History"
    subtitle="Track all customer payments and transactions"
    color-theme="emerald"
    v-model:search-model="searchQuery"
    search-placeholder="Search by customer, reference, invoice..."
    :stats="[
      { color: 'bg-blue-500', value: formatMoney(stats.total), label: 'Total' },
      { color: 'bg-emerald-500', value: formatMoney(stats.mpesa), label: 'M-Pesa' },
      { color: 'bg-purple-500', value: formatMoney(stats.cash), label: 'Cash' },
      { color: 'bg-cyan-500', value: formatMoney(stats.bank), label: 'Bank' },
      { color: 'bg-amber-500', value: formatMoney(stats.today), label: 'Today' }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchPayments"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
      </svg>
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="handleExportPayments" variant="outline" size="sm">
        <Download class="w-4 h-4 mr-1.5" /> Export
      </BaseButton>
      <BaseButton @click="recordPayment" variant="primary" size="sm">
        <Plus class="w-4 h-4 mr-1.5" /> Record Payment
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
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
    </template>

    <!-- Data Content -->
    <div v-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="payment in paginatedData"
          :key="payment.id"
          :title="payment.reference"
          :subtitle="payment.transaction_id"
          :meta="[
            { label: 'Customer', value: payment.customer_name },
            { label: 'Amount', value: 'KES ' + formatMoney(payment.amount), highlight: true },
            { label: 'Method', value: payment.method },
            { label: 'Invoice', value: payment.invoice_number || 'None' },
            { label: 'Date', value: formatDate(payment.payment_date) }
          ]"
          :badge="{ text: payment.method, variant: getMethodVariant(payment.method) }"
          @click="viewPayment(payment)"
        >
          <template #actions>
            <button @click.stop="viewPayment(payment)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
              <Eye class="w-4 h-4" />
            </button>
            <button @click.stop="downloadReceipt(payment)" class="p-2 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
              <Download class="w-4 h-4" />
            </button>
          </template>
        </MobileDataCard>
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
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
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="payment in paginatedData" :key="payment.id" class="hover:bg-emerald-50/50 transition-colors cursor-pointer" @click="viewPayment(payment)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-emerald-100 rounded-lg">
                      <CreditCard class="w-4 h-4 text-emerald-600" />
                    </div>
                    <div>
                      <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ payment.reference }}</p>
                      <p class="text-xs text-slate-500 dark:text-slate-400">{{ payment.transaction_id }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ payment.customer_name }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">{{ payment.customer_email }}</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-bold text-emerald-600">KES {{ formatMoney(payment.amount) }}</p>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="payment.method" :variant="getMethodVariant(payment.method)" />
                </td>
                <td class="px-6 py-4">
                  <p v-if="payment.invoice_number" class="text-sm text-slate-900">{{ payment.invoice_number }}</p>
                  <p v-else class="text-xs text-slate-400">No invoice</p>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm text-slate-900">{{ formatDate(payment.payment_date) }}</p>
                  <p class="text-xs text-slate-500 dark:text-slate-400">{{ formatTime(payment.payment_date) }}</p>
                </td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewPayment(payment)" class="p-1.5 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
                      <Eye class="w-4 h-4" />
                    </button>
                    <button @click="downloadReceipt(payment)" class="p-1.5 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
                      <Download class="w-4 h-4" />
                    </button>
                    <button @click="sendReceipt(payment)" class="p-1.5 text-slate-600 hover:bg-slate-100 rounded-md transition-colors">
                      <Send class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="payments" class="mt-auto" />
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No payments found' : 'No payments yet'"
      :description="searchQuery ? 'No payments match your search criteria.' : 'Payment records will appear here once customers make payments.'"
      icon="credit-card"
      color-theme="emerald"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>

  <!-- Payment Details Overlay -->
  <SlideOverlay v-model="showDetailsOverlay" title="Payment Details" :subtitle="selectedPayment?.reference || selectedPayment?.transaction_id" icon="CreditCard" width="60%">
    <div v-if="selectedPayment" class="space-y-6 p-6">
      <div class="grid grid-cols-2 gap-4">
        <div>
          <div class="text-xs text-slate-500 mb-1">Reference</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedPayment.reference || selectedPayment.transaction_id || 'N/A' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Transaction ID</div>
          <div class="text-sm font-mono text-slate-900">{{ selectedPayment.transaction_id || 'N/A' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Customer</div>
          <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedPayment.customer_name || selectedPayment.user?.name || 'N/A' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Phone</div>
          <div class="text-sm text-slate-900">{{ selectedPayment.phone_number || selectedPayment.user?.phone_number || 'N/A' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Amount</div>
          <div class="text-lg font-bold text-emerald-600">KES {{ formatMoney(selectedPayment.amount) }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Method</div>
          <EntityStatusBadge :status="selectedPayment.method || selectedPayment.payment_method || 'N/A'" :variant="getMethodVariant(selectedPayment.method || selectedPayment.payment_method)" />
        </div>
        <div>
          <div class="text-xs text-slate-500 mb-1">Status</div>
          <EntityStatusBadge :status="selectedPayment.status || 'N/A'" :variant="selectedPayment.status === 'completed' ? 'success' : selectedPayment.status === 'pending' ? 'warning' : 'danger'" />
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
      <div class="flex gap-3">
        <button
          @click="showDetailsOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Close
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- Record Payment Overlay -->
  <SlideOverlay v-model="showRecordOverlay" title="Record Payment" subtitle="Manually record a customer payment" icon="Plus" width="60%">
    <div class="space-y-4 p-6">
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Customer Phone</label>
        <BaseInput v-model="recordForm.phone_number" placeholder="e.g. 254712345678" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Amount (KES)</label>
        <BaseInput v-model.number="recordForm.amount" type="number" placeholder="0" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Payment Method</label>
        <BaseSelect v-model="recordForm.payment_method" class="w-full">
          <option value="cash">Cash</option>
          <option value="mpesa">M-Pesa</option>
          <option value="bank">Bank Transfer</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Transaction ID (optional)</label>
        <BaseInput v-model="recordForm.transaction_id" placeholder="e.g. TXN123" />
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showRecordOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Cancel
        </button>
        <button
          @click="submitRecordPayment"
          :disabled="recordSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ recordSubmitting ? 'Recording...' : 'Record Payment' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { CreditCard, Download, Plus, Eye, Send, Smartphone, Banknote, Building } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useAdminPayments } from '@/modules/tenant/composables/useAdminPayments.js'

const {
  loading, refreshing, error, payments, recordSubmitting,
  selectedPayment, showDetailsOverlay, showRecordOverlay, recordForm,
  stats,
  getMethodVariant, getMethodIcon,
  formatMoney, formatDate, formatTime,
  fetchPayments, viewPayment, recordPayment,
  downloadReceipt, sendReceipt, submitRecordPayment, exportPayments
} = useAdminPayments()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const filters = ref({ method: '', period: '' })
let lastFreshFetchAt = 0
const FRESH_FETCH_MIN_INTERVAL_MS = 5000

const filteredData = computed(() => {
  let data = payments.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(p =>
      p.reference.toLowerCase().includes(query) ||
      p.customer_name.toLowerCase().includes(query) ||
      p.customer_email.toLowerCase().includes(query) ||
      (p.invoice_number && p.invoice_number.toLowerCase().includes(query))
    )
  }
  if (filters.value.method) data = data.filter(p => p.method === filters.value.method)
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(p => {
      const payDate = new Date(p.payment_date)
      switch (filters.value.period) {
        case 'today': return payDate.toDateString() === now.toDateString()
        case 'yesterday': return payDate.toDateString() === new Date(now.getTime() - 86400000).toDateString()
        case 'week': return payDate >= new Date(now.getTime() - 7 * 86400000)
        case 'month': return payDate.getMonth() === now.getMonth() && payDate.getFullYear() === now.getFullYear()
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
const hasActiveFilters = computed(() => filters.value.method || filters.value.period)

const handleExportPayments = () => exportPayments(filteredData.value)

const refreshIfStale = () => {
  const nowTs = Date.now()
  if (nowTs - lastFreshFetchAt < FRESH_FETCH_MIN_INTERVAL_MS) return
  lastFreshFetchAt = nowTs
  void fetchPayments()
}

const handleWindowFocus = () => {
  refreshIfStale()
}

const handleVisibilityChange = () => {
  if (document.visibilityState === 'visible') {
    refreshIfStale()
  }
}

onMounted(() => {
  refreshIfStale()
  window.addEventListener('focus', handleWindowFocus)
  document.addEventListener('visibilitychange', handleVisibilityChange)
})

onUnmounted(() => {
  window.removeEventListener('focus', handleWindowFocus)
  document.removeEventListener('visibilitychange', handleVisibilityChange)
})
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
