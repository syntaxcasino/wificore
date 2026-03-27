<template>
  <DataViewContainer
    title="Todo Management"
    subtitle="Track and manage tasks"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search todos by title or description..."
    :stats="[
      { color: 'bg-blue-500', value: pendingCount },
      { color: 'bg-emerald-500', value: completedCount }
    ]"
    :total="todos.length"
    :loading="loading"
    add-button-text="Add Todo"
    @refresh="fetchTodos"
    @add="openCreateModal"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
      </svg>
    </template>

    <!-- SlideOverlay for Create/Edit -->
    <SlideOverlay
      v-model="showFormOverlay"
      :title="isEditing ? 'Edit Todo' : 'Add Todo'"
      :subtitle="isEditing ? 'Update task details' : 'Create a new task'"
      icon="checklist"
      width="480px"
      @close="closeForm"
    >
      <div class="p-6 space-y-4">
        <!-- Title -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Title</label>
          <input
            v-model="formData.title"
            type="text"
            placeholder="Enter task title..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
          />
        </div>

        <!-- Description -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Enter task description..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none resize-none"
          />
        </div>

        <!-- Priority -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Priority</label>
          <select
            v-model="formData.priority"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
          >
            <option value="low">Low</option>
            <option value="medium">Medium</option>
            <option value="high">High</option>
          </select>
        </div>

        <!-- Due Date -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Due Date</label>
          <input
            v-model="formData.due_date"
            type="date"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
          />
        </div>

        <!-- Status -->
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Status</label>
          <select
            v-model="formData.status"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white"
          >
            <option value="pending">Pending</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed</option>
          </select>
        </div>

        <!-- Error Message -->
        <div v-if="formError" class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-600">
          {{ formError }}
        </div>
      </div>

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
            class="flex-1 px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors"
            :class="isEditing ? 'bg-blue-600 hover:bg-blue-700' : 'bg-emerald-600 hover:bg-emerald-700'"
          >
            <span v-if="formSubmitting">{{ isEditing ? 'Updating...' : 'Creating...' }}</span>
            <span v-else>{{ isEditing ? 'Update Todo' : 'Create Todo' }}</span>
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
      <button @click="fetchTodos" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">
        Retry
      </button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredTodos.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="todo in paginatedTodos"
          :key="todo.id"
          :title="todo.title"
          :subtitle="todo.description"
          :meta-lines="getTodoMetaLines(todo)"
          :status="todo.status"
          :actions="getTodoActions(todo)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Title</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Description</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Priority</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Due Date</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="todo in paginatedTodos" :key="todo.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <span :class="getStatusDotClass(todo.status)" class="w-1.5 h-1.5 rounded-full"></span>
                    <span class="text-sm font-medium text-slate-900">{{ todo.title }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell">
                  <span class="text-sm text-slate-600 truncate max-w-xs block">{{ todo.description || '-' }}</span>
                </td>
                <td class="px-6 py-4">
                  <span :class="priorityBadgeClass(todo.priority)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                    {{ todo.priority || 'normal' }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <span class="text-sm text-slate-600">{{ formatDate(todo.due_date) }}</span>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="todo.status === 'completed' ? 'completed' : 'pending'" size="sm" />
                </td>
                <td class="px-6 py-4 text-right">
                  <div class="flex items-center justify-end gap-1">
                    <button
                      v-if="todo.status !== 'completed'"
                      @click="handleComplete(todo)"
                      class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors"
                    >
                      Complete
                    </button>
                    <button @click="openEditModal(todo)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">
                      Edit
                    </button>
                    <button @click="handleDelete(todo)" class="px-2 py-1 text-xs font-medium text-red-600 bg-red-50 rounded hover:bg-red-100 transition-colors">
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
        :total-items="filteredTodos.length"
        item-name="todos"
        class="mt-auto"
      />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Todos Found'"
      :description="searchQuery ? 'No todos match your search criteria. Try adjusting your filters.' : 'Get started by adding your first task to stay organized.'"
      icon="checklist"
      color-theme="blue"
      :show-clear="!!searchQuery"
      :has-filters="!!searchQuery"
      clear-text="Clear Search"
      add-text="Add Your First Todo"
      @clear="searchQuery = ''"
      @add="openCreateModal"
    />
  </DataViewContainer>
</template>

<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import { useTodos } from '@/modules/tenant/composables/useTodos'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const {
  todos,
  pendingTodos,
  completedTodos,
  loading,
  error,
  fetchTodos,
  fetchStatistics,
  createTodo,
  updateTodo,
  deleteTodo,
  completeTodo,
  searchTodos,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useTodos()

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
  description: '',
  priority: 'medium',
  due_date: '',
  status: 'pending'
})

// Stats
const pendingCount = computed(() => pendingTodos.value.length)
const completedCount = computed(() => completedTodos.value.length)

// Filter and paginate
const filteredTodos = computed(() => {
  if (!searchQuery.value) return todos.value
  return searchTodos(searchQuery.value)
})

const paginatedTodos = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredTodos.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil(filteredTodos.value.length / itemsPerPage.value))

// Reset page on search change
watch(searchQuery, () => { currentPage.value = 1 })
watch(itemsPerPage, () => { currentPage.value = 1 })

// Helpers
const getStatusDotClass = (status) => status === 'completed' ? 'bg-emerald-500' : 'bg-blue-500'

const priorityBadgeClass = (priority) => {
  const classes = {
    high: 'bg-red-100 text-red-800',
    medium: 'bg-yellow-100 text-yellow-800',
    low: 'bg-slate-100 text-slate-800'
  }
  return classes[priority] || classes.medium
}

const formatDate = (dateString) => dateString ? new Date(dateString).toLocaleDateString() : '-'

// Form helpers
const resetForm = () => {
  formData.value = {
    title: '',
    description: '',
    priority: 'medium',
    due_date: '',
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

const openEditModal = (todo) => {
  isEditing.value = true
  editingId.value = todo.id
  formData.value = {
    title: todo.title || '',
    description: todo.description || '',
    priority: todo.priority || 'medium',
    due_date: todo.due_date ? todo.due_date.split('T')[0] : '',
    status: todo.status || 'pending'
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
      await updateTodo(editingId.value, formData.value)
    } else {
      await createTodo(formData.value)
    }
    closeForm()
    await fetchTodos()
  } catch (err) {
    formError.value = err.message || 'Failed to save todo'
  } finally {
    formSubmitting.value = false
  }
}

const getTodoMetaLines = (todo) => {
  const lines = []
  if (todo.due_date) lines.push({ text: `Due: ${formatDate(todo.due_date)}` })
  if (todo.priority) lines.push({ text: `Priority: ${todo.priority}`, class: priorityBadgeClass(todo.priority) })
  return lines
}

const getTodoActions = (todo) => {
  const actions = []
  if (todo.status !== 'completed') {
    actions.push({ label: 'Complete', onClick: () => handleComplete(todo), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' })
  }
  actions.push({ label: 'Edit', onClick: () => openEditModal(todo), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  actions.push({ label: 'Delete', onClick: () => handleDelete(todo), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  return actions
}

const handleComplete = async (todo) => {
  try { await completeTodo(todo.id) } catch (err) { console.error('Failed to complete todo:', err) }
}

const handleDelete = async (todo) => {
  if (confirm(`Are you sure you want to delete "${todo.title}"?`)) {
    try { await deleteTodo(todo.id) } catch (err) { console.error('Failed to delete todo:', err) }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchTodos()
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
