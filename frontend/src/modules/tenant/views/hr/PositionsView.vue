<template>
  <DataViewContainer
    title="Position Management"
    subtitle="Manage job roles and titles"
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search positions by title or code..."
    :stats="[
      { color: 'bg-emerald-500', value: activeCount },
      { color: 'bg-slate-400', value: inactiveCount }
    ]"
    :total="positions.length"
    :loading="loading"
    add-button-text="Add Position"
    @refresh="fetchPositions"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Position' : 'Add Position'"
      :subtitle="isEditing ? 'Update position details' : 'Create a new position'"
      icon="briefcase"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Title -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Position Title</label>
          <input
            v-model="formData.title"
            type="text"
            placeholder="e.g., Software Engineer"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none"
          />
        </div>

        <!-- Code -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Position Code</label>
          <input
            v-model="formData.code"
            type="text"
            placeholder="e.g., SWE"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Enter position description..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none resize-none"
          />
        </div>

        <!-- Department -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Department</label>
          <select
            v-model="formData.department_id"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none bg-white"
          >
            <option value="">Select Department</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
        </div>

        <!-- Level -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Level</label>
          <select
            v-model="formData.level"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none bg-white"
          >
            <option value="entry">Entry Level</option>
            <option value="mid">Mid Level</option>
            <option value="senior">Senior Level</option>
            <option value="lead">Lead</option>
            <option value="manager">Manager</option>
            <option value="director">Director</option>
            <option value="executive">Executive</option>
          </select>
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500 outline-none bg-white"
          >
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
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
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-cyan-600 to-blue-600 rounded-lg hover:from-cyan-700 hover:to-blue-700 disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <span v-if="formSubmitting" class="flex items-center justify-center gap-2">
              <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              Saving...
            </span>
            <span v-else>{{ isEditing ? 'Update' : 'Create' }}</span>
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
      <button @click="fetchPositions" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredPositions.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="position in paginatedPositions"
          :key="position.id"
          :title="position.title"
          :subtitle="position.code || 'No code'"
          :meta-lines="getPositionMetaLines(position)"
          :status="position.status"
          :actions="getPositionActions(position)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Position</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Code</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Department</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Level</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="position in paginatedPositions" :key="position.id" class="hover:bg-cyan-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-cyan-500 to-blue-600 flex items-center justify-center text-white font-semibold flex-shrink-0">
                      {{ getInitials(position.title) }}
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ position.title }}</div>
                      <div v-if="position.description" class="text-xs text-slate-500 truncate max-w-xs">{{ position.description }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-sm text-slate-600 font-mono">{{ position.code || '-' }}</span>
                </td>
                <td class="px-6 py-4 hidden xl:table-cell">
                  <span class="text-sm text-slate-600">{{ position.department?.name || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="position.status" size="sm" />
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-sm text-slate-600 capitalize">{{ position.level || '-' }}</span>
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openEditModal(position)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(position)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
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
        :total-items="filteredPositions.length"
        item-name="positions"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Positions Found'"
      :description="searchQuery ? 'No positions match your search criteria. Try adjusting your filters.' : 'Get started by adding your first position to define job roles in your organization.'"
      icon="briefcase"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Position"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { usePositions } from '@/modules/tenant/composables/usePositions'
import { useDepartments } from '@/modules/tenant/composables/useDepartments'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  positions,
  activePositions,
  inactivePositions,
  loading,
  error,
  fetchPositions,
  fetchStatistics,
  createPosition,
  updatePosition,
  deletePosition,
  searchPositions,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = usePositions()

const { departments, fetchDepartments } = useDepartments()

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
  title: '',
  code: '',
  description: '',
  department_id: '',
  level: 'entry',
  status: 'active'
})

// Stats
const activeCount = computed(() => activePositions.value.length)
const inactiveCount = computed(() => inactivePositions.value.length)

// Filter and paginate
const filteredPositions = computed(() => {
  if (!searchQuery.value) return positions.value
  return searchPositions(searchQuery.value)
})

const paginatedPositions = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredPositions.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredPositions.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Helpers
const getInitials = (title) => title ? title.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '?'

const getPositionMetaLines = (position) => {
  const lines = []
  if (position.department?.name) lines.push({ text: position.department.name })
  if (position.level) lines.push({ text: `Level: ${position.level}`, class: 'text-slate-400' })
  return lines
}

// Form helpers
const resetForm = () => {
  formData.value = {
    title: '',
    code: '',
    description: '',
    department_id: '',
    level: 'entry',
    status: 'active'
  }
  formError.value = ''
  isEditing.value = false
  editingId.value = null
}

const openCreateModal = () => {
  resetForm()
  showFormOverlay.value = true
}

const openEditModal = (position) => {
  isEditing.value = true
  editingId.value = position.id
  formData.value = {
    title: position.title || '',
    code: position.code || '',
    description: position.description || '',
    department_id: position.department_id || '',
    level: position.level || 'entry',
    status: position.status || 'active'
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
      await updatePosition(editingId.value, formData.value)
    } else {
      await createPosition(formData.value)
    }
    closeForm()
    await fetchPositions()
  } catch (err) {
    formError.value = err.message || 'Failed to save position'
  } finally {
    formSubmitting.value = false
  }
}

const getPositionActions = (position) => [
  { label: 'Edit', onClick: () => openEditModal(position), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: 'Delete', onClick: () => handleDelete(position), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

const handleDelete = async (position) => {
  if (confirm(`Are you sure you want to delete "${position.title}"?`)) {
    try { await deletePosition(position.id) } catch (err) { console.error('Failed to delete position:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchPositions()
  await fetchStatistics()
  await fetchDepartments()
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
