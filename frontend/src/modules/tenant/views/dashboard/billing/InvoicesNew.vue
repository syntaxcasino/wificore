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
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Invoices</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
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
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
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
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { 
  FileText, Plus, Download, RefreshCw, X, Eye, Send,
  CheckCircle, Clock, AlertCircle
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
  { label: 'Invoices' }
]

// State
const loading = ref(false)
const error = ref(null)
const invoices = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

const filters = ref({
  status: '',
  period: ''
})

// Mock data
const mockInvoices = [
  {
    id: 1,
    invoice_number: 'INV-2025-001',
    customer_name: 'John Doe',
    customer_email: 'john@example.com',
    total_amount: 5000,
    paid_amount: 5000,
    status: 'paid',
    due_date: new Date(Date.now() + 7 * 86400000).toISOString(),
    created_at: new Date().toISOString()
  },
  {
    id: 2,
    invoice_number: 'INV-2025-002',
    customer_name: 'Jane Smith',
    customer_email: 'jane@example.com',
    total_amount: 3500,
    paid_amount: 0,
    status: 'pending',
    due_date: new Date(Date.now() + 14 * 86400000).toISOString(),
    created_at: new Date(Date.now() - 86400000).toISOString()
  },
  {
    id: 3,
    invoice_number: 'INV-2025-003',
    customer_name: 'Bob Johnson',
    customer_email: 'bob@example.com',
    total_amount: 7500,
    paid_amount: 0,
    status: 'overdue',
    due_date: new Date(Date.now() - 5 * 86400000).toISOString(),
    created_at: new Date(Date.now() - 20 * 86400000).toISOString()
  },
  {
    id: 4,
    invoice_number: 'INV-2025-004',
    customer_name: 'Alice Williams',
    customer_email: 'alice@example.com',
    total_amount: 2000,
    paid_amount: 2000,
    status: 'paid',
    due_date: new Date(Date.now() - 2 * 86400000).toISOString(),
    created_at: new Date(Date.now() - 10 * 86400000).toISOString()
  },
  {
    id: 5,
    invoice_number: 'INV-2025-005',
    customer_name: 'Charlie Brown',
    customer_email: 'charlie@example.com',
    total_amount: 4200,
    paid_amount: 0,
    status: 'pending',
    due_date: new Date(Date.now() + 3 * 86400000).toISOString(),
    created_at: new Date(Date.now() - 2 * 86400000).toISOString()
  }
]

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
  loading.value = true
  error.value = null
  
  try {
    // TODO: Replace with actual API call
    await new Promise(resolve => setTimeout(resolve, 500))
    invoices.value = mockInvoices
  } catch (err) {
    error.value = 'Failed to load invoices. Please try again.'
    console.error('Error fetching invoices:', err)
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
  console.log('View invoice:', invoice)
  // TODO: Implement invoice details view/modal
}

const downloadInvoice = (invoice) => {
  console.log('Download invoice:', invoice)
  // TODO: Implement PDF download
  alert('PDF download feature coming soon!')
}

const sendReminder = async (invoice) => {
  if (!confirm(`Send payment reminder to ${invoice.customer_name}?`)) return
  
  try {
    // TODO: Implement send reminder API call
    await new Promise(resolve => setTimeout(resolve, 500))
    alert('Reminder sent successfully!')
  } catch (err) {
    console.error('Error sending reminder:', err)
    alert('Failed to send reminder')
  }
}

const markAsPaid = async (invoice) => {
  if (!confirm(`Mark invoice ${invoice.invoice_number} as paid?`)) return
  
  try {
    // TODO: Implement mark as paid API call
    await new Promise(resolve => setTimeout(resolve, 500))
    invoice.status = 'paid'
    invoice.paid_amount = invoice.total_amount
    alert('Invoice marked as paid!')
  } catch (err) {
    console.error('Error marking as paid:', err)
    alert('Failed to update invoice')
  }
}

const openCreateInvoice = () => {
  console.log('Open create invoice modal')
  // TODO: Implement create invoice modal/page
  alert('Create invoice feature coming soon!')
}

const exportInvoices = () => {
  console.log('Export invoices')
  // TODO: Implement export functionality
  alert('Export feature coming soon!')
}

// Lifecycle
onMounted(() => {
  fetchInvoices()
})
</script>
