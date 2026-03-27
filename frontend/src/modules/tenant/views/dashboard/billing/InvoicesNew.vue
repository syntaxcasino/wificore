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
      <FileText class="h-5 w-5 md:h-6 md:w-6 text-white" />
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
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
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
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
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
            <tbody class="divide-y divide-slate-100">
              <tr v-for="invoice in paginatedData" :key="invoice.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <FileText class="w-4 h-4 text-blue-600" />
                    <span class="text-sm font-medium text-slate-900">{{ invoice.invoice_number }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-slate-900">{{ invoice.customer_name }}</div>
                  <div class="text-xs text-slate-500">{{ invoice.customer_email }}</div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(invoice.invoice_date) }}</td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(invoice.due_date) }}</td>
                <td class="px-6 py-4">
                  <div class="text-sm font-bold text-slate-900">{{ formatMoney(invoice.total_amount) }}</div>
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
    width="480px"
    @close="closeCreateOverlay"
  >
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Customer</label>
        <BaseSelect v-model="formData.customer_id" placeholder="Select customer">
          <option value="">Select customer</option>
          <option v-for="customer in customers" :key="customer.id" :value="customer.id">{{ customer.name }}</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Due Date</label>
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
          <span class="text-sm text-slate-600">Total: </span>
          <span class="text-lg font-bold text-slate-900">{{ formatMoney(calculateTotal) }}</span>
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
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Cancel
        </button>
        <button
          @click="submitInvoice"
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
import BaseInput from '@/modules/common/components/base/BaseInput.vue'

// State
const loading = ref(false)
const invoices = ref([])
const customers = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showCreateOverlay = ref(false)
const formSubmitting = ref(false)
const formMessage = ref({ type: '', text: '' })

const filters = ref({ status: '', dateRange: '' })

const formData = ref({
  customer_id: '',
  due_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
  items: [{ description: '', quantity: 1, unit_price: 0 }]
})

// Computed
const stats = computed(() => {
  const paid = invoices.value.filter(i => i.status === 'paid').length
  const pending = invoices.value.filter(i => i.status === 'sent').length
  const overdue = invoices.value.filter(i => i.status === 'overdue').length
  return { total: invoices.value.length, paid, pending, overdue }
})

const filteredData = computed(() => {
  let data = invoices.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(i =>
      i.invoice_number.toLowerCase().includes(query) ||
      i.customer_name.toLowerCase().includes(query)
    )
  }
  if (filters.value.status) {
    data = data.filter(i => i.status === filters.value.status)
  }
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.dateRange || searchQuery.value)

const calculateTotal = computed(() => {
  return formData.value.items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_price) || 0), 0)
})

// Helpers
const formatMoney = (amount) => new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES' }).format(amount)
const formatDate = (date) => date ? new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' }) : 'N/A'

const getInvoiceActions = (invoice) => [
  { label: 'View', onClick: () => viewInvoice(invoice) },
  { label: 'PDF', onClick: () => downloadInvoice(invoice) },
  ...(invoice.status !== 'paid' ? [{ label: 'Mark Paid', onClick: () => markAsPaid(invoice), class: 'text-green-700 bg-green-50 hover:bg-green-100' }] : [])
]

// Actions
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

const viewInvoice = (invoice) => {
  window.open(`/billing/invoices/${invoice.id}/view`, '_blank')
}

const downloadInvoice = (invoice) => {
  window.open(`/billing/invoices/${invoice.id}/pdf`, '_blank')
}

const markAsPaid = async (invoice) => {
  try {
    await axios.patch(`/billing/invoices/${invoice.id}`, { status: 'paid', paid_at: new Date().toISOString() })
    await fetchInvoices()
  } catch (err) {
    console.error('markAsPaid error:', err)
    alert(err.response?.data?.message || 'Failed to mark as paid')
  }
}

const openCreateOverlay = () => {
  formData.value = {
    customer_id: '',
    due_date: new Date(Date.now() + 14 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
    items: [{ description: '', quantity: 1, unit_price: 0 }]
  }
  formMessage.value = { type: '', text: '' }
  showCreateOverlay.value = true
}

const closeCreateOverlay = () => {
  showCreateOverlay.value = false
  formMessage.value = { type: '', text: '' }
}

const addItem = () => {
  formData.value.items.push({ description: '', quantity: 1, unit_price: 0 })
}

const removeItem = (index) => {
  if (formData.value.items.length > 1) {
    formData.value.items.splice(index, 1)
  }
}

const submitInvoice = async () => {
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
    await axios.post('/billing/invoices', {
      ...formData.value,
      total_amount: calculateTotal.value
    })
    closeCreateOverlay()
    await fetchInvoices()
  } catch (err) {
    formMessage.value = { type: 'error', text: err.response?.data?.message || 'Failed to create invoice' }
  } finally {
    formSubmitting.value = false
  }
}

const exportInvoices = () => {
  const csv = [
    ['Invoice #', 'Customer', 'Date', 'Due Date', 'Amount', 'Status'].join(','),
    ...invoices.value.map(i => [i.invoice_number, i.customer_name, i.invoice_date, i.due_date, i.total_amount, i.status].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `invoices-${new Date().toISOString().slice(0, 10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

onMounted(() => {
  fetchInvoices()
  fetchCustomers()
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
