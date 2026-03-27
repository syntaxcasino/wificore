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
      <CreditCard class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="exportPayments" variant="outline" size="sm">
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
    <div v-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
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
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
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
            <tbody class="divide-y divide-slate-100">
              <tr v-for="payment in paginatedData" :key="payment.id" class="hover:bg-emerald-50/50 transition-colors cursor-pointer" @click="viewPayment(payment)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="p-2 bg-emerald-100 rounded-lg">
                      <CreditCard class="w-4 h-4 text-emerald-600" />
                    </div>
                    <div>
                      <p class="text-sm font-semibold text-slate-900">{{ payment.reference }}</p>
                      <p class="text-xs text-slate-500">{{ payment.transaction_id }}</p>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <p class="text-sm font-medium text-slate-900">{{ payment.customer_name }}</p>
                  <p class="text-xs text-slate-500">{{ payment.customer_email }}</p>
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
                  <p class="text-xs text-slate-500">{{ formatTime(payment.payment_date) }}</p>
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
  <SlideOverlay v-model="showDetailsOverlay" title="Payment Details" :subtitle="selectedPayment?.reference || selectedPayment?.transaction_id" icon="CreditCard" width="480px">
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
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- Record Payment Overlay -->
  <SlideOverlay v-model="showRecordOverlay" title="Record Payment" subtitle="Manually record a customer payment" icon="Plus" width="480px">
    <div class="space-y-4 p-6">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Customer Phone</label>
        <BaseInput v-model="recordForm.phone_number" placeholder="e.g. 254712345678" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Amount (KES)</label>
        <BaseInput v-model.number="recordForm.amount" type="number" placeholder="0" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Payment Method</label>
        <BaseSelect v-model="recordForm.payment_method" class="w-full">
          <option value="cash">Cash</option>
          <option value="mpesa">M-Pesa</option>
          <option value="bank">Bank Transfer</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Transaction ID (optional)</label>
        <BaseInput v-model="recordForm.transaction_id" placeholder="e.g. TXN123" />
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showRecordOverlay = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
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
import { ref, computed, onMounted } from 'vue'
import { CreditCard, Download, Plus, Eye, Send, Smartphone, Banknote, Building } from 'lucide-vue-next'
import axios from 'axios'
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
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

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
const recordForm = ref({ phone_number: '', amount: 0, payment_method: 'cash', transaction_id: '' })

const filters = ref({ method: '', period: '' })

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

// Helpers
const getMethodVariant = (method) => ({ mpesa: 'success', cash: 'warning', bank: 'info', card: 'purple' }[method] || 'default')
const getMethodIcon = (method) => ({ mpesa: Smartphone, cash: Banknote, bank: Building, card: CreditCard }[method] || CreditCard)
const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : 'N/A'
const formatTime = (date) => date ? new Date(date).toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }) : 'N/A'

// Actions
const fetchPayments = async () => {
  const isInitial = payments.value.length === 0
  if (isInitial) { loading.value = true; error.value = null }
  else refreshing.value = true

  try {
    const params = {}
    if (searchQuery.value) params.search = searchQuery.value
    if (filters.value.method) params.payment_method = filters.value.method
    params.per_page = 100

    const response = await axios.get('/payments', { params })
    const data = response.data?.payments?.data || response.data?.payments || response.data?.data || []

    payments.value = data.map(p => ({
      id: p.id, reference: p.reference || `PAY-${p.id}`, transaction_id: p.transaction_id || p.mpesa_receipt || '',
      customer_name: p.user?.name || p.phone_number || 'Unknown', customer_email: p.user?.email || '',
      phone_number: p.phone_number || p.user?.phone_number || '', amount: Number(p.amount) || 0,
      method: p.payment_method || 'mpesa', status: p.status || 'completed', invoice_number: p.invoice_number || null,
      payment_date: p.created_at || p.paid_at || new Date().toISOString(), package: p.package || null, user: p.user || null, _raw: p
    }))
  } catch (err) {
    if (isInitial) error.value = err.response?.data?.message || 'Failed to load payments.'
    console.error('fetchPayments error:', err)
  } finally { loading.value = false; refreshing.value = false }
}

const viewPayment = (payment) => { selectedPayment.value = payment; showDetailsOverlay.value = true }
const recordPayment = () => { recordForm.value = { phone_number: '', amount: 0, payment_method: 'cash', transaction_id: '' }; showRecordOverlay.value = true }

const downloadReceipt = (payment) => {
  const csv = ['Reference,Customer,Amount,Method,Date', `${payment.reference},${payment.customer_name},${payment.amount},${payment.method},${payment.payment_date}`].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `receipt-${payment.reference || payment.id}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

const sendReceipt = async (payment) => {
  const confirmed = await confirmStore.confirm(`Send receipt to ${payment.customer_email || payment.phone_number}?`)
  if (confirmed) alert('Receipt sending is not yet configured.')
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
  } finally { recordSubmitting.value = false }
}

const exportPayments = () => {
  const csv = [
    ['Reference', 'Customer', 'Amount', 'Method', 'Status', 'Date'].join(','),
    ...filteredData.value.map(p => [p.reference, p.customer_name, p.amount, p.method, p.status, p.payment_date].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `payments-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(fetchPayments)
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
