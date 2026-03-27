<template>
  <DataViewContainer
    title="Revenue Management"
    subtitle="Track income and revenue streams"
    color-theme="emerald"
    v-model:search-model="searchQuery"
    search-placeholder="Search revenues by number, source, or description..."
    :stats="[
      { color: 'bg-emerald-500', value: confirmedCount },
      { color: 'bg-yellow-500', value: pendingCount }
    ]"
    :total="revenues.length"
    :loading="loading"
    add-button-text="Add Revenue"
    @refresh="fetchRevenues"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Revenue' : 'Add Revenue'"
      :subtitle="isEditing ? 'Update revenue details' : 'Create a new revenue entry'"
      icon="currency"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Revenue Number -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Revenue Number</label>
          <input
            v-model="formData.revenue_number"
            type="text"
            placeholder="REV-001"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Enter revenue description..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none resize-none"
          />
        </div>

        <!-- Source -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Source</label>
          <input
            v-model="formData.source"
            type="text"
            placeholder="e.g., Product Sales, Services"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Amount -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Amount</label>
          <div class="relative">
            <span class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-500">$</span>
            <input
              v-model="formData.amount"
              type="number"
              step="0.01"
              placeholder="0.00"
              class="w-full pl-8 pr-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
            />
          </div>
        </div>

        <!-- Revenue Date -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Revenue Date</label>
          <input
            v-model="formData.revenue_date"
            type="date"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none bg-white"
          >
            <option value="pending">Pending</option>
            <option value="confirmed">Confirmed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>

        <!-- Error Message -->
        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">
          {{ formError }}
        </div>
      </div>

      <!-- Actions -->
      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeForm"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            @click="handleSubmit"
            :disabled="formSubmitting"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ formSubmitting ? 'Saving...' : (isEditing ? 'Save Changes' : 'Create Revenue') }}
          </button>
        </div>
      </template>
    </SlideOverlay>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchRevenues" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredRevenues.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="revenue in paginatedRevenues"
          :key="revenue.id"
          :title="revenue.revenue_number"
          :subtitle="revenue.description"
          :meta-lines="[{ text: revenue.source || 'No source' }]"
          :status="revenue.status"
          :value="`$${formatAmount(revenue.amount)}`"
          value-class="text-emerald-600"
          :actions="getRevenueActions(revenue)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Revenue #</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Description</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Source</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Amount</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Date</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="revenue in paginatedRevenues" :key="revenue.id" class="hover:bg-emerald-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <span :class="getStatusDotClass(revenue.status)" class="w-1.5 h-1.5 rounded-full"></span>
                    <span class="text-sm font-medium text-slate-900">{{ revenue.revenue_number }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-700">{{ revenue.description }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ revenue.source || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm font-semibold text-emerald-600">${{ formatAmount(revenue.amount) }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="revenue.status === 'confirmed' ? 'confirmed' : 'pending'" size="sm" />
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-xs text-slate-500">{{ formatDate(revenue.revenue_date) }}</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      v-if="revenue.status === 'pending'"
                      @click="handleConfirm(revenue)"
                      class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors"
                    >
                      Confirm
                    </button>
                    <button @click="openEditModal(revenue)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(revenue)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination
        v-model:current-page="currentPage"
        v-model:items-per-page="itemsPerPage"
        :total-pages="totalPages"
        :total-items="filteredRevenues.length"
        item-name="revenues"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Revenues Found'"
      :description="searchQuery ? 'No revenues match your search criteria. Try adjusting your filters.' : 'Get started by adding your first revenue entry to track your income.'"
      icon="currency"
      color-theme="emerald"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Revenue"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useRevenues } from '@/modules/tenant/composables/useRevenues'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  revenues,
  pendingRevenues,
  confirmedRevenues,
  loading,
  error,
  fetchRevenues,
  fetchStatistics,
  createRevenue,
  updateRevenue,
  deleteRevenue,
  confirmRevenue,
  searchRevenues,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useRevenues()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)

// Form state
const showFormOverlay = ref(false)
const isEditing = ref(false)
const editingId = ref(null)
const formSubmitting = ref(false)
const formError = ref('')
const formData = ref({
  revenue_number: '',
  description: '',
  source: '',
  amount: '',
  revenue_date: '',
  status: 'pending'
})

// Stats
const pendingCount = computed(() => pendingRevenues.value.length)
const confirmedCount = computed(() => confirmedRevenues.value.length)

// Filter and paginate
const filteredRevenues = computed(() => {
  if (!searchQuery.value) return revenues.value
  return searchRevenues(searchQuery.value)
})

const paginatedRevenues = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredRevenues.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredRevenues.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Helpers
const getStatusDotClass = (status) => status === 'confirmed' ? 'bg-emerald-500' : 'bg-yellow-500'
const formatAmount = (amount) => Number(amount).toFixed(2)
const formatDate = (dateString) => dateString ? new Date(dateString).toLocaleDateString() : '-'

// Form helpers
const resetForm = () => {
  formData.value = {
    revenue_number: '',
    description: '',
    source: '',
    amount: '',
    revenue_date: '',
    status: 'pending'
  }
  formError.value = ''
  isEditing.value = false
  editingId.value = null
}

const openCreateModal = () => {
  resetForm()
  showFormOverlay.value = true
}

const openEditModal = (revenue) => {
  isEditing.value = true
  editingId.value = revenue.id
  formData.value = {
    revenue_number: revenue.revenue_number || '',
    description: revenue.description || '',
    source: revenue.source || '',
    amount: revenue.amount || '',
    revenue_date: revenue.revenue_date ? revenue.revenue_date.split('T')[0] : '',
    status: revenue.status || 'pending'
  }
  showFormOverlay.value = true
}

const closeForm = () => {
  showFormOverlay.value = false
  setTimeout(resetForm, 300)
}

const handleSubmit = async () => {
  formSubmitting.value = true
  formError.value = ''

  try {
    if (isEditing.value) {
      await updateRevenue(editingId.value, formData.value)
    } else {
      await createRevenue(formData.value)
    }
    closeForm()
    await fetchRevenues()
  } catch (err) {
    formError.value = err.message || 'Failed to save revenue'
  } finally {
    formSubmitting.value = false
  }
}

const getRevenueActions = (revenue) => {
  const actions = []
  if (revenue.status === 'pending') {
    actions.push({ label: 'Confirm', onClick: () => handleConfirm(revenue), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' })
  }
  actions.push({ label: 'Edit', onClick: () => openEditModal(revenue), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  actions.push({ label: 'Delete', onClick: () => handleDelete(revenue), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  return actions
}

const handleConfirm = async (revenue) => {
  try { await confirmRevenue(revenue.id) } catch (err) { console.error('Failed to confirm revenue:', err) }
}

const handleDelete = async (revenue) => {
  if (confirm(`Are you sure you want to delete ${revenue.revenue_number}?`)) {
    try { await deleteRevenue(revenue.id) } catch (err) { console.error('Failed to delete revenue:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchRevenues()
  await fetchStatistics()
  setupWebSocketListeners()
})

onUnmounted(() => cleanupWebSocketListeners())
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
