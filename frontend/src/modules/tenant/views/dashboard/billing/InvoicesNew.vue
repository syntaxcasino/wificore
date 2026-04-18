<template>
  <DataViewContainer
    title="Invoices"
    subtitle="View and manage customer invoices"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search invoices..."
    :stats="[
      { color: 'bg-blue-500', value: stats.total },
      { color: 'bg-green-500', value: stats.paid },
      { color: 'bg-amber-500', value: stats.pending },
      { color: 'bg-red-500', value: stats.overdue }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchInvoices"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
      </svg>
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="exportInvoices" variant="secondary" size="sm" class="shrink-0">
        <Download class="w-4 h-4 mr-1" /> Export
      </BaseButton>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm" class="shrink-0">
        <Plus class="w-4 h-4 mr-1" /> Create Invoice
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="draft">Draft</option>
        <option value="sent">Sent</option>
        <option value="paid">Paid</option>
        <option value="overdue">Overdue</option>
        <option value="cancelled">Cancelled</option>
      </BaseSelect>
      <BaseSelect v-model="filters.dateRange" placeholder="All Dates" class="w-36">
        <option value="">All Dates</option>
        <option value="today">Today</option>
        <option value="week">This Week</option>
        <option value="month">This Month</option>
        <option value="quarter">This Quarter</option>
      </BaseSelect>
    </template>

    <!-- Loading Skeleton -->
    <DataSkeleton v-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="invoice in paginatedData"
          :key="invoice.id"
          :title="`#${invoice.invoice_number}`"
          :subtitle="invoice.customer_name"
          :meta-lines="[{ text: formatDate(invoice.invoice_date) }, { text: formatMoney(invoice.total_amount), class: 'font-semibold text-slate-900' }]"
          :status="invoice.status"
          :actions="getInvoiceActions(invoice)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Invoice #</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Date</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Due Date</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="invoice in paginatedData" :key="invoice.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <FileText class="w-4 h-4 text-blue-600" />
                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ invoice.invoice_number }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ invoice.customer_name }}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">{{ invoice.customer_email }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ formatDate(invoice.invoice_date) }}</td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ formatDate(invoice.due_date) }}</td>
                <td class="px-6 py-4">
                  <div class="text-sm font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(invoice.total_amount) }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="invoice.status" size="sm" />
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewInvoice(invoice)" class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors">View</button>
                    <button @click="downloadInvoice(invoice)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">PDF</button>
                    <button v-if="invoice.status !== 'paid'" @click="markAsPaid(invoice)" class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 rounded hover:bg-green-100 transition-colors">Mark Paid</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="invoices" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Invoices Found' : 'No Invoices'"
      :description="searchQuery ? 'No invoices match your search criteria.' : 'No invoices have been created yet.'"
      icon="file-text"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    >
      <template #action>
        <BaseButton @click="openCreateOverlay" variant="primary" size="sm">
          <Plus class="w-4 h-4 mr-1" /> Create Invoice
        </BaseButton>
      </template>
    </DataEmptyState>
  </DataViewContainer>

  <!-- Create Invoice SlideOverlay -->
  <SlideOverlay
    v-model="showCreateOverlay"
    title="Create Invoice"
    subtitle="Generate a new customer invoice"
    icon="FileText"
    width="60%"
    @close="closeCreateOverlay"
  >
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Customer</label>
        <BaseSelect v-model="formData.customer_id" placeholder="Select customer">
          <option value="">Select customer</option>
          <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Due Date</label>
        <BaseInput v-model="formData.due_date" type="date" />
      </div>
      <div class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-900 mb-3">Invoice Items</h4>
        <div v-for="(item, index) in formData.items" :key="index" class="flex gap-2 mb-2">
          <BaseInput v-model="item.description" placeholder="Description" class="flex-1" />
          <BaseInput v-model="item.quantity" type="number" placeholder="Qty" class="w-20" />
          <BaseInput v-model="item.unit_price" type="number" placeholder="Price" class="w-28" />
          <button @click="removeItem(index)" class="p-2 text-red-600 hover:bg-red-50 rounded transition-colors">
            <Trash2 class="w-4 h-4" />
          </button>
        </div>
        <button @click="addItem" class="text-sm text-blue-600 hover:text-blue-700 font-medium">+ Add Item</button>
        <div class="mt-4 text-right">
          <span class="text-sm text-slate-600 dark:text-slate-400">Total: </span>
          <span class="text-lg font-bold text-slate-900 dark:text-slate-100">{{ formatMoney(calculateTotal) }}</span>
        </div>
      </div>
      <div v-if="formMessage.text" :class="formMessage.type === 'error' ? 'text-red-600' : 'text-green-600'" class="text-sm">
        {{ formMessage.text }}
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="closeCreateOverlay"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600"
        >
          Cancel
        </button>
        <button
          @click="handleSubmitInvoice"
          :disabled="formSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ formSubmitting ? 'Creating...' : 'Create Invoice' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { FileText, Plus, Download, Trash2, CheckCircle } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import { useInvoices } from '@/modules/tenant/composables/useInvoices.js'

const {
  loading, invoices, customers, formSubmitting, formMessage, formData,
  stats, calculateTotal,
  formatMoney, formatDate,
  fetchInvoices, fetchCustomers, markAsPaid,
  resetForm, addItem, removeItem, submitInvoice,
  viewInvoice, downloadInvoice, exportInvoices
} = useInvoices()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showCreateOverlay = ref(false)
const filters = ref({ status: '', dateRange: '' })

const filteredData = computed(() => {
  let data = invoices.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(i =>
      i.invoice_number.toLowerCase().includes(query) ||
      i.customer_name.toLowerCase().includes(query)
    )
  }
  if (filters.value.status) data = data.filter(i => i.status === filters.value.status)
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.dateRange || searchQuery.value)

const getInvoiceActions = (invoice) => [
  { label: 'View', onClick: () => viewInvoice(invoice) },
  { label: 'PDF', onClick: () => downloadInvoice(invoice) },
  ...(invoice.status !== 'paid' ? [{ label: 'Mark Paid', onClick: () => markAsPaid(invoice), class: 'text-green-700 bg-green-50 hover:bg-green-100' }] : [])
]

const openCreateOverlay = () => {
  resetForm()
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
  formMessage.value = { type: '', text: '' }
}

const handleSubmitInvoice = () => submitInvoice(closeCreateOverlay)

onMounted(() => {
  fetchInvoices()
  fetchCustomers()
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
