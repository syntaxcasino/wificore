<template>
  <DataViewContainer
    title="All Tickets"
    subtitle="View and manage support tickets"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search tickets..."
    :stats="[
      { color: 'bg-blue-500', value: stats.total },
      { color: 'bg-amber-500', value: stats.open },
      { color: 'bg-indigo-500', value: stats.inProgress },
      { color: 'bg-emerald-500', value: stats.resolved }
    ]"
    :total="filteredData.length"
    :loading="loading"
    @refresh="fetchTickets"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
      </svg>
    </template>

    <!-- Action Buttons -->
    <template #actions>
      <BaseButton @click="openCreateOverlay" variant="primary" size="sm" class="shrink-0">
        <Plus class="w-4 h-4 mr-1" /> New Ticket
      </BaseButton>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="open">Open</option>
        <option value="in_progress">In Progress</option>
        <option value="resolved">Resolved</option>
        <option value="closed">Closed</option>
      </BaseSelect>
      <BaseSelect v-model="filters.priority" placeholder="All Priority" class="w-36">
        <option value="">All Priority</option>
        <option value="low">Low</option>
        <option value="medium">Medium</option>
        <option value="high">High</option>
        <option value="urgent">Urgent</option>
      </BaseSelect>
    </template>

    <!-- Loading Skeleton -->
    <DataSkeleton v-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="ticket in paginatedData"
          :key="ticket.id"
          :title="`#${ticket.id}: ${ticket.subject}`"
          :subtitle="ticket.customer_name"
          :meta-lines="[{ text: ticket.category }, { text: formatDate(ticket.created_at) }]"
          :status="ticket.status"
          :badges="[{ text: ticket.priority, class: getPriorityBadgeClass(ticket.priority) }]"
          :actions="getTicketActions(ticket)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-700 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Ticket</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Customer</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Priority</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Category</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Assigned</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="ticket in paginatedData" :key="ticket.id" class="hover:bg-slate-50/50 transition-colors cursor-pointer" @click="viewTicket(ticket)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-lg bg-blue-100 flex items-center justify-center shrink-0">
                      <MessageSquare class="w-4 h-4 text-blue-600" />
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900 dark:text-slate-100">#{{ ticket.id }}</div>
                      <div class="text-sm text-slate-600 max-w-xs truncate">{{ ticket.subject }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ ticket.customer_name }}</div>
                  <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatDate(ticket.created_at) }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="ticket.status" size="sm" />
                </td>
                <td class="px-6 py-4">
                  <BaseBadge :variant="getPriorityVariant(ticket.priority)" size="sm">{{ ticket.priority }}</BaseBadge>
                </td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ticket.category }}</td>
                <td class="px-6 py-4 text-sm text-slate-600 dark:text-slate-400">{{ ticket.assigned_to || 'Unassigned' }}</td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="viewTicket(ticket)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">View</button>
                    <button v-if="ticket.status !== 'closed'" @click="closeTicket(ticket)" class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 rounded hover:bg-green-100 transition-colors">Close</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="tickets" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Tickets Found' : 'No Support Tickets'"
      :description="searchQuery ? 'No tickets match your search criteria.' : 'No support tickets have been created yet.'"
      icon="message-square"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>

  <!-- View Ticket SlideOverlay -->
  <SlideOverlay v-model="showViewOverlay" title="Ticket Details" :subtitle="selectedTicket ? `#${selectedTicket.id}` : ''" icon="MessageSquare" width="60%" @close="closeViewOverlay">
    <div v-if="selectedTicket" class="p-6 space-y-6">
      <div class="flex items-center gap-2 mb-4">
        <EntityStatusBadge :status="selectedTicket.status" size="sm" />
        <BaseBadge :variant="getPriorityVariant(selectedTicket.priority)" size="sm">{{ selectedTicket.priority }}</BaseBadge>
      </div>
      <div>
        <h3 class="text-lg font-semibold text-slate-900 mb-2">{{ selectedTicket.subject }}</h3>
        <p class="text-sm text-slate-600 dark:text-slate-400">{{ selectedTicket.description }}</p>
      </div>
      <div class="grid grid-cols-2 gap-4">
        <div><span class="text-xs text-slate-500 dark:text-slate-400">Customer</span><div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTicket.customer_name }}</div></div>
        <div><span class="text-xs text-slate-500 dark:text-slate-400">Category</span><div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTicket.category }}</div></div>
        <div><span class="text-xs text-slate-500 dark:text-slate-400">Assigned To</span><div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ selectedTicket.assigned_to || 'Unassigned' }}</div></div>
        <div><span class="text-xs text-slate-500 dark:text-slate-400">Created</span><div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ formatDateTime(selectedTicket.created_at) }}</div></div>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button v-if="selectedTicket?.status !== 'closed'" @click="closeTicket(selectedTicket)" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors">Close Ticket</button>
        <button @click="closeViewOverlay" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Dismiss</button>
      </div>
    </template>
  </SlideOverlay>

  <!-- Create Ticket SlideOverlay -->
  <SlideOverlay v-model="showCreateOverlay" title="New Ticket" subtitle="Create a new support ticket" icon="Plus" width="60%" @close="closeCreateOverlay">
    <div class="p-6 space-y-4">
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Subject</label>
        <BaseInput v-model="form.subject" placeholder="Brief description of the issue" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Customer Name</label>
        <BaseInput v-model="form.customer_name" placeholder="Customer name" />
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Category</label>
        <BaseSelect v-model="form.category" placeholder="Select category">
          <option value="">Select category</option>
          <option value="Technical">Technical</option>
          <option value="Billing">Billing</option>
          <option value="General">General</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Priority</label>
        <BaseSelect v-model="form.priority" placeholder="Select priority">
          <option value="low">Low</option>
          <option value="medium">Medium</option>
          <option value="high">High</option>
          <option value="urgent">Urgent</option>
        </BaseSelect>
      </div>
      <div>
        <label class="block text-sm font-medium text-slate-700 dark:text-slate-300 mb-1">Description</label>
        <textarea v-model="form.description" rows="4" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500" placeholder="Describe the issue in detail..."></textarea>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button @click="closeCreateOverlay" class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 dark:text-slate-300 bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg hover:bg-slate-50 dark:hover:bg-slate-600">Cancel</button>
        <button @click="submitTicket" :disabled="submitting" class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50">
          {{ submitting ? 'Creating...' : 'Create Ticket' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { MessageSquare, Plus, RefreshCw } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import { useTickets } from '@/modules/tenant/composables/useTickets.js'

const {
  loading, tickets, selectedTicket, submitting,
  showViewOverlay, showCreateOverlay, form,
  stats,
  formatDate, formatDateTime,
  getPriorityVariant, getPriorityBadgeClass, getTicketActions,
  fetchTickets, viewTicket, closeViewOverlay,
  openCreateOverlay, closeCreateOverlay, submitTicket, closeTicket
} = useTickets()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const filters = ref({ status: '', priority: '' })

const filteredData = computed(() => {
  let data = tickets.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    data = data.filter(t =>
      t.subject.toLowerCase().includes(query) ||
      t.description.toLowerCase().includes(query) ||
      t.customer_name.toLowerCase().includes(query)
    )
  }
  if (filters.value.status) data = data.filter(t => t.status === filters.value.status)
  if (filters.value.priority) data = data.filter(t => t.priority === filters.value.priority)
  return data
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.value.status || filters.value.priority || searchQuery.value)

const getTicketActionsInView = (ticket) => getTicketActions(ticket, { view: viewTicket, close: closeTicket })

onMounted(fetchTickets)
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
