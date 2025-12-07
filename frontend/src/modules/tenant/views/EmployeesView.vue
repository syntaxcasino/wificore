<template>
  <div class="bg-gradient-to-br from-purple-50 via-indigo-50/50 to-blue-50/30 -mx-6 -my-6 px-6 py-8 pb-16">
    <!-- Header -->
    <div class="mb-10">
      <div class="flex items-center justify-between flex-wrap gap-6">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <Building2 class="w-7 h-7 text-white" />
            </div>
            <div>
              <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 bg-clip-text text-transparent">Departments</h1>
              <p class="text-sm text-gray-600 mt-1 font-medium">Manage organizational departments</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="openCreateForm"
            class="px-5 py-2.5 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 flex items-center gap-2 font-semibold"
          >
            <Plus class="w-5 h-5" />
            Add Department
          </button>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="mb-8">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-purple-100 rounded-lg flex items-center justify-center">
              <Building2 class="w-6 h-6 text-purple-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Total Departments</p>
          <h3 class="text-3xl font-bold text-gray-900">{{ stats.total }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
              <CheckCircle2 class="w-6 h-6 text-green-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Active</p>
          <h3 class="text-3xl font-bold text-green-600">{{ stats.active }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
              <Clock class="w-6 h-6 text-orange-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Pending Approval</p>
          <h3 class="text-3xl font-bold text-orange-600">{{ stats.pending_approval }}</h3>
        </div>

        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-gray-100 rounded-lg flex items-center justify-center">
              <XCircle class="w-6 h-6 text-gray-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Inactive</p>
          <h3 class="text-3xl font-bold text-gray-600">{{ stats.inactive }}</h3>
        </div>
      </div>
    </div>

    <!-- Filters -->
    <div class="mb-6 bg-white rounded-xl shadow-sm border border-gray-200 p-4">
      <div class="flex items-center gap-2 flex-wrap">
        <button
          v-for="filter in filters"
          :key="filter.value"
          @click="activeFilter = filter.value"
          class="px-4 py-2 rounded-lg text-sm font-medium transition-all duration-200"
          :class="activeFilter === filter.value 
            ? 'bg-purple-100 text-purple-700 shadow-sm' 
            : 'text-gray-600 hover:bg-gray-100'"
        >
          {{ filter.label }}
          <span 
            class="ml-2 px-2 py-0.5 rounded-full text-xs font-bold"
            :class="activeFilter === filter.value ? 'bg-purple-200 text-purple-800' : 'bg-gray-200 text-gray-700'"
          >
            {{ getFilterCount(filter.value) }}
          </span>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 border-4 border-purple-200 border-t-purple-600 rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-600">Loading departments...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredDepartments.length === 0" class="text-center py-20">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12">
        <div class="w-20 h-20 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-6">
          <Building2 class="w-10 h-10 text-purple-600" />
        </div>
        <h3 class="text-2xl font-bold text-gray-900 mb-2">No departments found</h3>
        <p class="text-gray-600 mb-6">Create your first department to get started</p>
        <button
          @click="openCreateForm"
          class="px-6 py-3 bg-gradient-to-r from-purple-600 to-indigo-600 text-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 inline-flex items-center gap-2 font-semibold"
        >
          <Plus class="w-5 h-5" />
          Add Department
        </button>
      </div>
    </div>

    <!-- Department List -->
    <div v-else class="space-y-4">
      <DepartmentCard
        v-for="department in filteredDepartments"
        :key="department.id"
        :department="department"
        @edit="openEditForm"
        @delete="handleDelete"
        @approve="handleApprove"
      />
    </div>

    <!-- Slide Overlay for Create/Edit -->
    <SlideOverlay
      v-model="showForm"
      :title="isEdit ? 'Edit Department' : 'Create New Department'"
      :subtitle="isEdit ? 'Update department details' : 'Add a new department'"
      icon="Building2"
      width="40%"
    >
      <DepartmentForm
        :department="selectedDepartment"
        @submit="handleSubmit"
        @cancel="closeForm"
      />
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Building2, Plus, CheckCircle2, Clock, XCircle } from 'lucide-vue-next'
import { useDepartments } from '@/composables/useDepartments'
import DepartmentCard from '@/modules/tenant/components/DepartmentCard.vue'
import DepartmentForm from '@/modules/tenant/components/DepartmentForm.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  departments,
  stats,
  activeDepartments,
  pendingDepartments,
  inactiveDepartments,
  loading,
  fetchDepartments,
  fetchStatistics,
  createDepartment,
  updateDepartment,
  deleteDepartment,
  approveDepartment,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useDepartments()

const activeFilter = ref('all')
const showForm = ref(false)
const isEdit = ref(false)
const selectedDepartment = ref(null)

const filters = [
  { value: 'all', label: 'All Departments' },
  { value: 'active', label: 'Active' },
  { value: 'pending_approval', label: 'Pending Approval' },
  { value: 'inactive', label: 'Inactive' }
]

const filteredDepartments = computed(() => {
  if (activeFilter.value === 'all') return departments.value
  if (activeFilter.value === 'active') return activeDepartments.value
  if (activeFilter.value === 'pending_approval') return pendingDepartments.value
  if (activeFilter.value === 'inactive') return inactiveDepartments.value
  return departments.value
})

const getFilterCount = (filterValue) => {
  if (filterValue === 'all') return departments.value.length
  if (filterValue === 'active') return activeDepartments.value.length
  if (filterValue === 'pending_approval') return pendingDepartments.value.length
  if (filterValue === 'inactive') return inactiveDepartments.value.length
  return 0
}

const openCreateForm = () => {
  isEdit.value = false
  selectedDepartment.value = null
  showForm.value = true
}

const openEditForm = (department) => {
  isEdit.value = true
  selectedDepartment.value = department
  showForm.value = true
}

const closeForm = () => {
  showForm.value = false
  selectedDepartment.value = null
}

const handleSubmit = async (departmentData) => {
  try {
    if (isEdit.value && selectedDepartment.value) {
      await updateDepartment(selectedDepartment.value.id, departmentData)
    } else {
      await createDepartment(departmentData)
    }
    closeForm()
    await fetchStatistics()
  } catch (error) {
    console.error('Error submitting department:', error)
  }
}

const handleDelete = async (department) => {
  if (confirm(`Are you sure you want to delete "${department.name}"?`)) {
    try {
      await deleteDepartment(department.id)
      await fetchStatistics()
    } catch (error) {
      console.error('Error deleting department:', error)
    }
  }
}

const handleApprove = async (department) => {
  try {
    await approveDepartment(department.id)
    await fetchStatistics()
  } catch (error) {
    console.error('Error approving department:', error)
  }
}

onMounted(async () => {
  await fetchDepartments()
  await fetchStatistics()
  setupWebSocketListeners()
})

onUnmounted(() => {
  cleanupWebSocketListeners()
})
</script>
