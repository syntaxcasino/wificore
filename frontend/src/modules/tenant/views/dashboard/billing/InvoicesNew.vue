<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Invoices"
      subtitle="View and manage customer invoices"
      icon="FileText"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="exportInvoices" variant="ghost">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
        <BaseButton @click="openCreateInvoice" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Create Invoice
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Stats Cards -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-3 sm:p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Invoices</div>
              <div class="text-xl sm:text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <div class="p-3 bg-blue-100 rounded-lg">
              <FileText class="w-6 h-6 text-blue-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Paid</div>
              <div class="text-2xl font-bold text-green-900">KES {{ formatMoney(stats.paid) }}</div>
            </div>
            <div class="p-3 bg-green-100 rounded-lg">
              <CheckCircle class="w-6 h-6 text-green-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Pending</div>
              <div class="text-2xl font-bold text-amber-900">KES {{ formatMoney(stats.pending) }}</div>
            </div>
            <div class="p-3 bg-amber-100 rounded-lg">
              <Clock class="w-6 h-6 text-amber-600" />
            </div>
          </div>
        </div>

        <div class="bg-gradient-to-br from-red-50 to-rose-50 rounded-lg p-4 border border-red-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-red-600 font-medium mb-1">Overdue</div>
              <div class="text-2xl font-bold text-red-900">KES {{ formatMoney(stats.overdue) }}</div>
            </div>
            <div class="p-3 bg-red-100 rounded-lg">
              <AlertCircle class="w-6 h-6 text-red-600" />
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
          <BaseSearch v-model="searchQuery" placeholder="Search invoices by number, customer..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
            <option value="overdue">Overdue</option>
            <option value="cancelled">Cancelled</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.period" placeholder="All Time" class="w-36">
            <option value="">All Time</option>
            <option value="today">Today</option>
            <option value="week">This Week</option>
            <option value="month">This Month</option>
            <option value="year">This Year</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Results Count -->
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} invoices</BaseBadge>
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
            <BaseButton @click="fetchInvoices" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No invoices found' : 'No invoices yet'"
          :description="searchQuery ? 'No invoices match your search criteria.' : 'Get started by creating your first invoice.'"
          icon="FileText"
          actionText="Create Invoice"
          actionIcon="Plus"
          @action="openCreateInvoice"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Invoice</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Due Date</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="invoice in paginatedData"
                  :key="invoice.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="viewInvoice(invoice)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="p-2 bg-blue-100 rounded-lg">
                        <FileText class="w-4 h-4 text-blue-600" />
                      </div>
                      <div>
                        <div class="text-sm font-semibold text-slate-900">{{ invoice.invoice_number }}</div>
                        <div class="text-xs text-slate-500">{{ formatDateTime(invoice.created_at) }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ invoice.customer_name }}</div>
                    <div class="text-xs text-slate-500">{{ invoice.customer_email }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-bold text-slate-900">KES {{ formatMoney(invoice.total_amount) }}</div>
                    <div v-if="invoice.paid_amount > 0" class="text-xs text-green-600">
                      Paid: KES {{ formatMoney(invoice.paid_amount) }}
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge 
                      :variant="getStatusVariant(invoice.status)" 
                      :dot="invoice.status === 'paid'"
                    >
                      {{ invoice.status }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDate(invoice.due_date) }}</div>
                    <div v-if="invoice.status === 'overdue'" class="text-xs text-red-600 font-medium">
                      {{ getDaysOverdue(invoice.due_date) }} days overdue
                    </div>
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewInvoice(invoice)" variant="ghost" size="sm" title="View">
                        <Eye class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="downloadInvoice(invoice)" variant="ghost" size="sm" title="Download">
                        <Download class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        v-if="invoice.status === 'pending' || invoice.status === 'overdue'"
                        @click="sendReminder(invoice)" 
                        variant="ghost" 
                        size="sm"
                        title="Send Reminder"
                      >
                        <Send class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        v-if="invoice.status !== 'paid' && invoice.status !== 'cancelled'"
                        @click="markAsPaid(invoice)" 
                        variant="success" 
                        size="sm"
                      >
                        Mark Paid
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
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} invoices
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :total-items="filteredData.length"
      />
    </PageFooter>

    <!-- View Invoice Overlay -->
    <SlideOverlay v-model="showViewOverlay" title="Invoice Details" :subtitle="selectedInvoice?.invoice_number" icon="FileText" width="lg">
      <div v-if="selectedInvoice" class="p-6 space-y-6">
        <div class="flex items-center gap-2">
          <BaseBadge :variant="getStatusVariant(selectedInvoice.status)">{{ selectedInvoice.status }}</BaseBadge>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-xs text-slate-500">Invoice Number</span><div class="text-sm font-semibold text-slate-900">{{ selectedInvoice.invoice_number }}</div></div>
          <div><span class="text-xs text-slate-500">Customer</span><div class="text-sm font-medium text-slate-900">{{ selectedInvoice.customer_name }}</div></div>
          <div><span class="text-xs text-slate-500">Email</span><div class="text-sm text-slate-900">{{ selectedInvoice.customer_email }}</div></div>
          <div><span class="text-xs text-slate-500">Total Amount</span><div class="text-sm font-bold text-slate-900">KES {{ formatMoney(selectedInvoice.total_amount) }}</div></div>
          <div><span class="text-xs text-slate-500">Paid Amount</span><div class="text-sm font-medium text-green-600">KES {{ formatMoney(selectedInvoice.paid_amount) }}</div></div>
          <div><span class="text-xs text-slate-500">Due Date</span><div class="text-sm text-slate-900">{{ formatDate(selectedInvoice.due_date) }}</div></div>
          <div><span class="text-xs text-slate-500">Created</span><div class="text-sm text-slate-900">{{ formatDateTime(selectedInvoice.created_at) }}</div></div>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton v-if="selectedInvoice?.status === 'pending' || selectedInvoice?.status === 'overdue'" @click="markAsPaid(selectedInvoice)" variant="success" size="sm">Mark Paid</BaseButton>
          <BaseButton @click="showViewOverlay = false" variant="ghost" size="sm">Close</BaseButton>
        </div>
      </template>
    </SlideOverlay>

    <!-- Create Invoice Overlay -->
    <SlideOverlay v-model="showCreateOverlay" title="Create Invoice" subtitle="Generate a new customer invoice" icon="Plus" width="lg">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Name</label>
          <input v-model="newInvoice.customer_name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Customer name" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Email</label>
          <input v-model="newInvoice.customer_email" type="email" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="customer@example.com" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount (KES)</label>
          <input v-model.number="newInvoice.total_amount" type="number" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="0" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Due Date</label>
          <input v-model="newInvoice.due_date" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea v-model="newInvoice.description" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Invoice description..."></textarea>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton @click="submitInvoice" variant="primary" :loading="submitting">Create Invoice</BaseButton>
          <BaseButton @click="showCreateOverlay = false" variant="ghost">Cancel</BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  FileText, Plus, Download, RefreshCw, X, Eye, Send,
  CheckCircle, Clock, AlertCircle
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
  { label: 'Invoices' }
]

// State
const loading = ref(false)
const error = ref(null)
const invoices = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showViewOverlay = ref(false)
const showCreateOverlay = ref(false)
const selectedInvoice = ref(null)
const submitting = ref(false)

const newInvoice = ref({
  customer_name: '',
  customer_email: '',
  total_amount: 0,
  due_date: '',
  description: ''
})

const filters = ref({
  status: '',
  period: ''
})

// Computed
const stats = computed(() => {
  const paid = invoices.value
    .filter(inv => inv.status === 'paid')
    .reduce((sum, inv) => sum + inv.total_amount, 0)
  
  const pending = invoices.value
    .filter(inv => inv.status === 'pending')
    .reduce((sum, inv) => sum + inv.total_amount, 0)
  
  const overdue = invoices.value
    .filter(inv => inv.status === 'overdue')
    .reduce((sum, inv) => sum + inv.total_amount, 0)
  
  return {
    total: invoices.value.length,
    paid,
    pending,
    overdue
  }
})

const filteredData = computed(() => {
  let data = invoices.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(inv =>
      inv.invoice_number.toLowerCase().includes(query) ||
      inv.customer_name.toLowerCase().includes(query) ||
      inv.customer_email.toLowerCase().includes(query)
    )
  }

  // Status filter
  if (filters.value.status) {
    data = data.filter(inv => inv.status === filters.value.status)
  }

  // Period filter
  if (filters.value.period) {
    const now = new Date()
    data = data.filter(inv => {
      const created = new Date(inv.created_at)
      switch (filters.value.period) {
        case 'today':
          return created.toDateString() === now.toDateString()
        case 'week':
          const weekAgo = new Date(now.getTime() - 7 * 86400000)
          return created >= weekAgo
        case 'month':
          return created.getMonth() === now.getMonth() && created.getFullYear() === now.getFullYear()
        case 'year':
          return created.getFullYear() === now.getFullYear()
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
const fetchInvoices = async () => {
  const isInitial = invoices.value.length === 0
  if (isInitial) {
    loading.value = true
    error.value = null
  }
  
  try {
    const response = await axios.get('/billing/invoices')
    const data = response.data?.invoices?.data || response.data?.invoices || response.data?.data || []
    invoices.value = data.map(inv => ({
      id: inv.id,
      invoice_number: inv.invoice_number || inv.number || `INV-${inv.id}`,
      customer_name: inv.customer_name || inv.user?.name || 'Unknown',
      customer_email: inv.customer_email || inv.user?.email || '',
      total_amount: Number(inv.total_amount || inv.amount || 0),
      paid_amount: Number(inv.paid_amount || 0),
      status: inv.status || 'pending',
      due_date: inv.due_date || inv.due_at || '',
      created_at: inv.created_at || new Date().toISOString()
    }))
  } catch (err) {
    if (isInitial) {
      error.value = err.response?.data?.message || 'Failed to load invoices.'
    }
    console.error('fetchInvoices error:', err)
  } finally {
    loading.value = false
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
    paid: 'success',
    pending: 'warning',
    overdue: 'danger',
    cancelled: 'secondary'
  }
  return variants[status] || 'default'
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

const getDaysOverdue = (dueDate) => {
  const due = new Date(dueDate)
  const now = new Date()
  const diff = Math.floor((now - due) / (1000 * 60 * 60 * 24))
  return diff
}

const viewInvoice = (invoice) => {
  selectedInvoice.value = invoice
  showViewOverlay.value = true
}

const downloadInvoice = async (invoice) => {
  try {
    const response = await axios.get(`/billing/invoices/${invoice.id}/download`, { responseType: 'blob' })
    const url = URL.createObjectURL(response.data)
    const a = document.createElement('a')
    a.href = url
    a.download = `${invoice.invoice_number}.pdf`
    a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    console.error('Download error:', err)
    alert(err.response?.data?.message || 'Failed to download invoice')
  }
}

const sendReminder = async (invoice) => {
  if (!confirm(`Send payment reminder to ${invoice.customer_name}?`)) return
  try {
    await axios.post(`/billing/invoices/${invoice.id}/remind`)
    alert('Reminder sent successfully!')
  } catch (err) {
    console.error('Send reminder error:', err)
    alert(err.response?.data?.message || 'Failed to send reminder')
  }
}

const markAsPaid = async (invoice) => {
  if (!invoice || !confirm(`Mark invoice ${invoice.invoice_number} as paid?`)) return
  try {
    await axios.patch(`/billing/invoices/${invoice.id}`, { status: 'paid', paid_amount: invoice.total_amount })
    const idx = invoices.value.findIndex(i => i.id === invoice.id)
    if (idx !== -1) {
      invoices.value[idx].status = 'paid'
      invoices.value[idx].paid_amount = invoice.total_amount
    }
    if (selectedInvoice.value?.id === invoice.id) {
      selectedInvoice.value.status = 'paid'
      selectedInvoice.value.paid_amount = invoice.total_amount
    }
  } catch (err) {
    console.error('Mark paid error:', err)
    alert(err.response?.data?.message || 'Failed to update invoice')
  }
}

const openCreateInvoice = () => {
  newInvoice.value = { customer_name: '', customer_email: '', total_amount: 0, due_date: '', description: '' }
  showCreateOverlay.value = true
}

const submitInvoice = async () => {
  if (!newInvoice.value.customer_name || !newInvoice.value.total_amount) {
    alert('Customer name and amount are required.')
    return
  }
  submitting.value = true
  try {
    await axios.post('/billing/invoices', newInvoice.value)
    showCreateOverlay.value = false
    await fetchInvoices()
  } catch (err) {
    console.error('Create invoice error:', err)
    alert(err.response?.data?.message || 'Failed to create invoice')
  } finally {
    submitting.value = false
  }
}

const exportInvoices = () => {
  const csv = [
    ['Invoice #', 'Customer', 'Email', 'Amount', 'Paid', 'Status', 'Due Date', 'Created'].join(','),
    ...filteredData.value.map(inv => [
      inv.invoice_number, inv.customer_name, inv.customer_email,
      inv.total_amount, inv.paid_amount, inv.status, inv.due_date, inv.created_at
    ].join(','))
  ].join('\n')
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `invoices-${new Date().toISOString().slice(0,10)}.csv`
  a.click()
  URL.revokeObjectURL(url)
}

// Lifecycle
onMounted(() => {
  fetchInvoices()
})
</script>
