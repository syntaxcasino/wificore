<template>
  <DataViewContainer
    title="Department Management"
    subtitle="Manage organizational departments"
    color-theme="violet"
    v-model:search-model="searchQuery"
    search-placeholder="Search departments by name or code..."
    :stats="[
      { color: 'bg-emerald-500', value: activeCount },
      { color: 'bg-slate-400', value: inactiveCount }
    ]"
    :total="departments.length"
    :loading="loading"
    add-button-text="Add Department"
    @refresh="fetchDepartments"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Department' : 'Add Department'"
      :subtitle="isEditing ? 'Update department details' : 'Create a new department'"
      icon="building"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Name -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Department Name</label>
          <input
            v-model="formData.name"
            type="text"
            placeholder="e.g., Engineering"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none"
          />
        </div>

        <!-- Code -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Department Code</label>
          <input
            v-model="formData.code"
            type="text"
            placeholder="e.g., ENG"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Enter department description..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none resize-none"
          />
        </div>

        <!-- Manager -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Department Manager</label>
          <select
            v-model="formData.manager_id"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none bg-white"
          >
            <option value="">Select Manager</option>
            <option v-for="emp in employees" :key="emp.id" :value="emp.id">{{ emp.full_name }}</option>
          </select>
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-violet-500 focus:border-violet-500 outline-none bg-white"
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
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-violet-600 to-purple-600 rounded-lg hover:from-violet-700 hover:to-purple-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
      <button @click="fetchDepartments" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredDepartments.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="department in paginatedDepartments"
          :key="department.id"
          :title="department.name"
          :subtitle="department.code || 'No code'"
          :meta-lines="getDepartmentMetaLines(department)"
          :status="department.status"
          :actions="getDepartmentActions(department)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Department</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Code</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:table-cell">Manager</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Employees</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="department in paginatedDepartments" :key="department.id" class="hover:bg-violet-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-violet-500 to-purple-600 flex items-center justify-center text-white font-semibold flex-shrink-0">
                      {{ getInitials(department.name) }}
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ department.name }}</div>
                      <div v-if="department.description" class="text-xs text-slate-500 truncate max-w-xs">{{ department.description }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-sm text-slate-600 font-mono">{{ department.code || '-' }}</span>
                </td>
                <td class="px-6 py-4 hidden xl:table-cell">
                  <span class="text-sm text-slate-600">{{ department.manager?.full_name || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ department.employees_count || 0 }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="department.status" size="sm" />
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openEditModal(department)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(department)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
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
        :total-items="filteredDepartments.length"
        item-name="departments"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Departments Found'"
      :description="searchQuery ? 'No departments match your search criteria. Try adjusting your filters.' : 'Get started by adding your first department to organize your company structure.'"
      icon="building"
      color-theme="violet"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Department"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useDepartments } from '@/modules/tenant/composables/useDepartments'
import { useEmployees } from '@/modules/tenant/composables/useEmployees'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  departments,
  activeDepartments,
  inactiveDepartments,
  loading,
  error,
  fetchDepartments,
  fetchStatistics,
  createDepartment,
  updateDepartment,
  deleteDepartment,
  searchDepartments,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useDepartments()

const { employees, fetchEmployees } = useEmployees()

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
  name: '',
  code: '',
  description: '',
  manager_id: '',
  status: 'active'
})

// Stats
const activeCount = computed(() => activeDepartments.value.length)
const inactiveCount = computed(() => inactiveDepartments.value.length)

// Filter and paginate
const filteredDepartments = computed(() => {
  if (!searchQuery.value) return departments.value
  return searchDepartments(searchQuery.value)
})

const paginatedDepartments = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredDepartments.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredDepartments.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Helpers
const getInitials = (name) => name ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '?'

const getDepartmentMetaLines = (department) => {
  const lines = []
  if (department.manager?.full_name) lines.push({ text: `Manager: ${department.manager.full_name}` })
  if (department.employees_count !== undefined) lines.push({ text: `${department.employees_count} employees`, class: 'text-slate-400' })
  return lines
}

// Form helpers
const resetForm = () => {
  formData.value = {
    name: '',
    code: '',
    description: '',
    manager_id: '',
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

const openEditModal = (department) => {
  isEditing.value = true
  editingId.value = department.id
  formData.value = {
    name: department.name || '',
    code: department.code || '',
    description: department.description || '',
    manager_id: department.manager_id || '',
    status: department.status || 'active'
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
      await updateDepartment(editingId.value, formData.value)
    } else {
      await createDepartment(formData.value)
    }
    closeForm()
    await fetchDepartments()
  } catch (err) {
    formError.value = err.message || 'Failed to save department'
  } finally {
    formSubmitting.value = false
  }
}

const getDepartmentActions = (department) => [
  { label: 'Edit', onClick: () => openEditModal(department), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: 'Delete', onClick: () => handleDelete(department), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

const handleDelete = async (department) => {
  if (confirm(`Are you sure you want to delete "${department.name}"?`)) {
    try { await deleteDepartment(department.id) } catch (err) { console.error('Failed to delete department:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchDepartments()
  await fetchStatistics()
  await fetchEmployees()
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
