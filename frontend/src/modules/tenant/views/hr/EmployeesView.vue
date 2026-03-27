<template>
  <DataViewContainer
    title="Employee Management"
    subtitle="Manage staff and HR records"
    color-theme="emerald"
    v-model:search-model="searchQuery"
    search-placeholder="Search employees by name, number, or email..."
    :stats="[
      { color: 'bg-emerald-500', value: activeCount },
      { color: 'bg-yellow-500', value: onLeaveCount }
    ]"
    :total="employees.length"
    :loading="loading"
    add-button-text="Add Employee"
    @refresh="fetchEmployees"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Employee' : 'Add Employee'"
      :subtitle="isEditing ? 'Update employee details' : 'Create a new employee record'"
      icon="users"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Full Name -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Full Name</label>
          <input
            v-model="formData.full_name"
            type="text"
            placeholder="John Doe"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Email -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Email</label>
          <input
            v-model="formData.email"
            type="email"
            placeholder="john@example.com"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Employee Number -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Employee Number</label>
          <input
            v-model="formData.employee_number"
            type="text"
            placeholder="EMP-001"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none"
          />
        </div>

        <!-- Department -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Department</label>
          <select
            v-model="formData.department_id"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none bg-white"
          >
            <option value="">Select Department</option>
            <option v-for="dept in departments" :key="dept.id" :value="dept.id">{{ dept.name }}</option>
          </select>
        </div>

        <!-- Position -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Position</label>
          <select
            v-model="formData.position_id"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none bg-white"
          >
            <option value="">Select Position</option>
            <option v-for="pos in positions" :key="pos.id" :value="pos.id">{{ pos.title }}</option>
          </select>
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 outline-none bg-white"
          >
            <option value="active">Active</option>
            <option value="on_leave">On Leave</option>
            <option value="terminated">Terminated</option>
            <option value="suspended">Suspended</option>
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
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-gradient-to-r from-emerald-600 to-green-600 rounded-lg hover:from-emerald-700 hover:to-green-700 disabled:opacity-50 disabled:cursor-not-allowed"
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
      <button @click="fetchEmployees" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredEmployees.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="employee in paginatedEmployees"
          :key="employee.id"
          :title="employee.full_name"
          :subtitle="employee.email"
          :meta-lines="getEmployeeMetaLines(employee)"
          :status="employee.status"
          :actions="getEmployeeActions(employee)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Employee</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Employee #</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Department</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Position</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="employee in paginatedEmployees" :key="employee.id" class="hover:bg-emerald-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-emerald-500 to-green-600 flex items-center justify-center text-white font-semibold flex-shrink-0">
                      {{ getInitials(employee.full_name) }}
                    </div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ employee.full_name }}</div>
                      <div class="text-xs text-slate-500">{{ employee.email }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-sm text-slate-600 font-mono">{{ employee.employee_number || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ employee.department?.name || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ employee.position?.title || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="employee.status" size="sm" />
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openEditModal(employee)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(employee)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
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
        :total-items="filteredEmployees.length"
        item-name="employees"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Employees Found'"
      :description="searchQuery ? 'No employees match your search criteria. Try adjusting your filters.' : 'Get started by adding your first employee to your organization.'"
      icon="users"
      color-theme="emerald"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Employee"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useEmployees } from '@/modules/tenant/composables/useEmployees'
import { useDepartments } from '@/modules/tenant/composables/useDepartments'
import { usePositions } from '@/modules/tenant/composables/usePositions'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  employees,
  activeEmployees,
  onLeaveEmployees,
  loading,
  error,
  fetchEmployees,
  fetchStatistics,
  createEmployee,
  updateEmployee,
  deleteEmployee,
  searchEmployees,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useEmployees()

const { departments, fetchDepartments } = useDepartments()
const { positions, fetchPositions } = usePositions()

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
  full_name: '',
  email: '',
  employee_number: '',
  department_id: '',
  position_id: '',
  status: 'active'
})

// Stats
const activeCount = computed(() => activeEmployees.value.length)
const onLeaveCount = computed(() => onLeaveEmployees.value.length)

// Filter and paginate
const filteredEmployees = computed(() => {
  if (!searchQuery.value) return employees.value
  return searchEmployees(searchQuery.value)
})

const paginatedEmployees = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredEmployees.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredEmployees.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Helpers
const getInitials = (name) => name ? name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2) : '?'

const getEmployeeMetaLines = (employee) => {
  const lines = []
  if (employee.department?.name) lines.push({ text: employee.department.name })
  if (employee.position?.title) lines.push({ text: employee.position.title, class: 'text-slate-400' })
  return lines
}

// Form helpers
const resetForm = () => {
  formData.value = {
    full_name: '',
    email: '',
    employee_number: '',
    department_id: '',
    position_id: '',
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

const openEditModal = (employee) => {
  isEditing.value = true
  editingId.value = employee.id
  formData.value = {
    full_name: employee.full_name || '',
    email: employee.email || '',
    employee_number: employee.employee_number || '',
    department_id: employee.department_id || '',
    position_id: employee.position_id || '',
    status: employee.status || 'active'
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
      await updateEmployee(editingId.value, formData.value)
    } else {
      await createEmployee(formData.value)
    }
    closeForm()
    await fetchEmployees()
  } catch (err) {
    formError.value = err.message || 'Failed to save employee'
  } finally {
    formSubmitting.value = false
  }
}

const getEmployeeActions = (employee) => [
  { label: 'Edit', onClick: () => openEditModal(employee), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: 'Delete', onClick: () => handleDelete(employee), class: 'text-red-600 bg-red-50 hover:bg-red-100' }
]

const handleDelete = async (employee) => {
  if (confirm(`Are you sure you want to delete ${employee.full_name}?`)) {
    try { await deleteEmployee(employee.id) } catch (err) { console.error('Failed to delete employee:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchEmployees()
  await fetchStatistics()
  await fetchDepartments()
  await fetchPositions()
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
