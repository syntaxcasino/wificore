<template>
  <PageContainer>
    <PageHeader title="Support Tickets" subtitle="Manage customer support requests" icon="MessageSquare" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="refreshTickets" variant="ghost" :loading="refreshing">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': refreshing }" />
          Refresh
        </BaseButton>
        <BaseButton @click="openCreateModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          New Ticket
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-2 md:grid-cols-4 gap-3 sm:gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Tickets</div>
              <div class="text-2xl font-bold text-blue-900">{{ stats.total }}</div>
            </div>
            <MessageSquare class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-amber-50 to-yellow-50 rounded-lg p-4 border border-amber-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-amber-600 font-medium mb-1">Open</div>
              <div class="text-2xl font-bold text-amber-900">{{ stats.open }}</div>
            </div>
            <AlertCircle class="w-6 h-6 text-amber-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">In Progress</div>
              <div class="text-2xl font-bold text-purple-900">{{ stats.inProgress }}</div>
            </div>
            <Clock class="w-6 h-6 text-purple-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Resolved</div>
              <div class="text-2xl font-bold text-green-900">{{ stats.resolved }}</div>
            </div>
            <CheckCircle class="w-6 h-6 text-green-600" />
          </div>
        </div>
      </div>
    </div>

    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search tickets..." />
        </div>
        
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" class="w-36">
            <option value="">All Status</option>
            <option value="open">Open</option>
            <option value="in_progress">In Progress</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.priority" class="w-36">
            <option value="">All Priority</option>
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <div class="ml-auto">
          <BaseBadge variant="info">{{ filteredData.length }} tickets</BaseBadge>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div v-if="loading" class="p-6">
        <BaseLoading type="list" :rows="8" />
      </div>

      <div v-else-if="!filteredData.length" class="p-6">
        <BaseEmpty title="No tickets found" description="No support tickets match your criteria." icon="MessageSquare" actionText="Create Ticket" @action="openCreateModal" />
      </div>

      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="divide-y divide-slate-100">
            <div v-for="ticket in paginatedData" :key="ticket.id" class="p-4 hover:bg-slate-50 transition-colors cursor-pointer" @click="viewTicket(ticket)">
              <div class="flex items-start gap-4">
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-2">
                    <span class="text-sm font-semibold text-slate-900">#{{ ticket.id }}</span>
                    <BaseBadge :variant="getStatusVariant(ticket.status)" size="sm">{{ ticket.status }}</BaseBadge>
                    <BaseBadge :variant="getPriorityVariant(ticket.priority)" size="sm">{{ ticket.priority }}</BaseBadge>
                    <span class="text-xs text-slate-500">{{ formatDateTime(ticket.created_at) }}</span>
                  </div>
                  
                  <h3 class="text-base font-medium text-slate-900 mb-1">{{ ticket.subject }}</h3>
                  <p class="text-sm text-slate-600 mb-2">{{ ticket.description }}</p>
                  
                  <div class="flex items-center gap-4 text-xs text-slate-500">
                    <span>Customer: {{ ticket.customer_name }}</span>
                    <span>Category: {{ ticket.category }}</span>
                    <span v-if="ticket.assigned_to">Assigned: {{ ticket.assigned_to }}</span>
                  </div>
                </div>
                
                <div class="flex items-center gap-2">
                  <BaseButton @click.stop="replyTicket(ticket)" variant="ghost" size="sm">
                    <MessageCircle class="w-3 h-3" />
                  </BaseButton>
                  <BaseButton @click.stop="closeTicket(ticket)" variant="success" size="sm" v-if="ticket.status !== 'closed'">
                    Close
                  </BaseButton>
                </div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} tickets
      </div>
      <BasePagination v-model="currentPage" :total-pages="totalPages" :total-items="filteredData.length" />
    </PageFooter>

    <!-- View Ticket Overlay -->
    <SlideOverlay v-model="showViewOverlay" title="Ticket Details" :subtitle="selectedTicket ? `#${selectedTicket.id}` : ''" icon="MessageSquare" width="lg">
      <div v-if="selectedTicket" class="p-6 space-y-6">
        <div class="flex items-center gap-2 mb-4">
          <BaseBadge :variant="getStatusVariant(selectedTicket.status)" size="sm">{{ selectedTicket.status }}</BaseBadge>
          <BaseBadge :variant="getPriorityVariant(selectedTicket.priority)" size="sm">{{ selectedTicket.priority }}</BaseBadge>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-slate-900 mb-2">{{ selectedTicket.subject }}</h3>
          <p class="text-sm text-slate-600">{{ selectedTicket.description }}</p>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div><span class="text-xs text-slate-500">Customer</span><div class="text-sm font-medium text-slate-900">{{ selectedTicket.customer_name }}</div></div>
          <div><span class="text-xs text-slate-500">Category</span><div class="text-sm font-medium text-slate-900">{{ selectedTicket.category }}</div></div>
          <div><span class="text-xs text-slate-500">Assigned To</span><div class="text-sm font-medium text-slate-900">{{ selectedTicket.assigned_to || 'Unassigned' }}</div></div>
          <div><span class="text-xs text-slate-500">Created</span><div class="text-sm font-medium text-slate-900">{{ formatDateTime(selectedTicket.created_at) }}</div></div>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton v-if="selectedTicket?.status !== 'closed'" @click="closeTicket(selectedTicket)" variant="success" size="sm">Close Ticket</BaseButton>
          <BaseButton @click="showViewOverlay = false" variant="ghost" size="sm">Dismiss</BaseButton>
        </div>
      </template>
    </SlideOverlay>

    <!-- Create Ticket Overlay -->
    <SlideOverlay v-model="showCreateOverlay" title="New Ticket" subtitle="Create a new support ticket" icon="Plus" width="lg">
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Subject</label>
          <input v-model="newTicket.subject" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Brief description of the issue" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Customer Name</label>
          <input v-model="newTicket.customer_name" type="text" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Customer name" />
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Category</label>
          <select v-model="newTicket.category" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="">Select category</option>
            <option value="Technical">Technical</option>
            <option value="Billing">Billing</option>
            <option value="General">General</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Priority</label>
          <select v-model="newTicket.priority" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea v-model="newTicket.description" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe the issue in detail..."></textarea>
        </div>
      </div>
      <template #footer>
        <div class="flex items-center gap-2">
          <BaseButton @click="submitTicket" variant="primary" :loading="submitting">Create Ticket</BaseButton>
          <BaseButton @click="showCreateOverlay = false" variant="ghost">Cancel</BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { MessageSquare, RefreshCw, Plus, X, AlertCircle, Clock, CheckCircle, MessageCircle } from 'lucide-vue-next'
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
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Support', to: '/dashboard/support' },
  { label: 'All Tickets' }
]

const loading = ref(false)
const refreshing = ref(false)
const tickets = ref([])
const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const showViewOverlay = ref(false)
const showCreateOverlay = ref(false)
const selectedTicket = ref(null)
const submitting = ref(false)

const newTicket = ref({
  subject: '',
  customer_name: '',
  category: '',
  priority: 'medium',
  description: ''
})

const filters = ref({
  status: '',
  priority: ''
})

const stats = computed(() => ({
  total: tickets.value.length,
  open: tickets.value.filter(t => t.status === 'open').length,
  inProgress: tickets.value.filter(t => t.status === 'in_progress').length,
  resolved: tickets.value.filter(t => t.status === 'resolved').length
}))

const filteredData = computed(() => {
  let data = tickets.value

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(t => t.subject.toLowerCase().includes(query) || t.description.toLowerCase().includes(query))
  }

  if (filters.value.status) data = data.filter(t => t.status === filters.value.status)
  if (filters.value.priority) data = data.filter(t => t.priority === filters.value.priority)

  return data
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const paginationInfo = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value + 1
  const end = Math.min(start + itemsPerPage.value - 1, filteredData.value.length)
  return { start, end, total: filteredData.value.length }
})

const hasActiveFilters = computed(() => filters.value.status || filters.value.priority || searchQuery.value)

const getStatusVariant = (status) => {
  const variants = { open: 'warning', in_progress: 'info', resolved: 'success', closed: 'secondary' }
  return variants[status] || 'default'
}

const getPriorityVariant = (priority) => {
  const variants = { low: 'secondary', medium: 'info', high: 'warning', urgent: 'danger' }
  return variants[priority] || 'default'
}

const formatDateTime = (date) => new Date(date).toLocaleString()

const fetchTickets = async () => {
  const isInitial = tickets.value.length === 0
  if (isInitial) {
    loading.value = true
  } else {
    refreshing.value = true
  }
  
  try {
    const response = await axios.get('/support/tickets')
    const data = response.data?.tickets?.data || response.data?.tickets || response.data?.data || []
    tickets.value = data.map(t => ({
      id: t.id,
      subject: t.subject || t.title || '',
      description: t.description || t.body || '',
      customer_name: t.customer_name || t.user?.name || 'Unknown',
      category: t.category || 'General',
      status: t.status || 'open',
      priority: t.priority || 'medium',
      assigned_to: t.assigned_to || t.assignee?.name || null,
      created_at: t.created_at || new Date().toISOString()
    }))
  } catch (err) {
    console.error('fetchTickets error:', err)
  } finally {
    loading.value = false
    refreshing.value = false
  }
}

const refreshTickets = async () => {
  await fetchTickets()
}

const clearFilters = () => {
  filters.value = { status: '', priority: '' }
  searchQuery.value = ''
}

const openCreateModal = () => {
  newTicket.value = { subject: '', customer_name: '', category: '', priority: 'medium', description: '' }
  showCreateOverlay.value = true
}

const viewTicket = (ticket) => {
  selectedTicket.value = ticket
  showViewOverlay.value = true
}

const replyTicket = (ticket) => {
  selectedTicket.value = ticket
  showViewOverlay.value = true
}

const submitTicket = async () => {
  if (!newTicket.value.subject || !newTicket.value.description) {
    alert('Subject and description are required.')
    return
  }
  submitting.value = true
  try {
    await axios.post('/support/tickets', newTicket.value)
    showCreateOverlay.value = false
    await fetchTickets()
  } catch (err) {
    console.error('submitTicket error:', err)
    alert(err.response?.data?.message || 'Failed to create ticket')
  } finally {
    submitting.value = false
  }
}

const closeTicket = async (ticket) => {
  if (!ticket || !confirm(`Close ticket #${ticket.id}?`)) return
  try {
    await axios.patch(`/support/tickets/${ticket.id}`, { status: 'closed' })
    const idx = tickets.value.findIndex(t => t.id === ticket.id)
    if (idx !== -1) tickets.value[idx].status = 'closed'
    if (selectedTicket.value?.id === ticket.id) selectedTicket.value.status = 'closed'
  } catch (err) {
    console.error('closeTicket error:', err)
    alert(err.response?.data?.message || 'Failed to close ticket')
  }
}

onMounted(() => fetchTickets())
</script>
