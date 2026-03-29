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

    <!-- Todo Modal (Create/Edit) -->
    <TodoModal
      v-model="showFormOverlay"
      :is-editing="isEditing"
      :todo="editingTodo"
      :submitting="formSubmitting"
      :error="formError"
      @close="closeForm"
      @submit="handleSubmit"
    />

    <!-- Todo Details Modal -->
    <TodoDetailsModal
      v-model="showDetailsOverlay"
      :todo-details="selectedTodo"
      :loading="detailsLoading"
      :error="detailsError"
      @close="closeDetails"
      @complete="handleComplete(selectedTodo)"
      @reopen="handleReopen(selectedTodo)"
    />

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
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 border-b border-slate-200">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Title</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell">Description</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Priority</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Due Date</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
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
                      @click="viewTodo(todo)"
                      class="px-2 py-1 text-xs font-medium text-blue-700 bg-blue-50 rounded hover:bg-blue-100 transition-colors"
                    >
                      View
                    </button>
                    <div class="relative">
                      <button data-menu-button @click="toggleMenu(todo.id, $event)" class="p-1 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded transition-colors">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01M12 6a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2zm0 7a1 1 0 110-2 1 1 0 010 2z" />
                        </svg>
                      </button>
                    </div>
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

    <!-- Global Dropdown Menu Portal -->
    <Teleport to="body">
      <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white rounded-lg shadow-2xl border border-slate-200 py-1 z-[9999] overflow-hidden">
        <button @click="viewTodo(todos.find(t => t.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
          </svg>
          View
        </button>
        <button @click="openEditModal(todos.find(t => t.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
          </svg>
          Edit
        </button>
        <div class="border-t border-slate-200 my-1"></div>
        <button @click="handleDelete(todos.find(t => t.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
          </svg>
          Delete
        </button>
      </div>
    </Teleport>
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
import { useConfirmStore } from '@/stores/confirm'
import TodoModal from '@/modules/tenant/components/todos/TodoModal.vue'
import TodoDetailsModal from '@/modules/tenant/components/todos/TodoDetailsModal.vue'

const confirmStore = useConfirmStore()

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
  markAsCompleted,
  fetchTodo,
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
const editingTodo = ref(null)
const formSubmitting = ref(false)
const formError = ref('')

// Details state
const showDetailsOverlay = ref(false)
const selectedTodo = ref(null)
const detailsLoading = ref(false)
const detailsError = ref('')

// Menu state
const activeMenu = ref(null)
const menuPosition = ref({})

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

// Menu toggle
const toggleMenu = (todoId, event) => {
  event.stopPropagation()
  if (activeMenu.value === todoId) {
    activeMenu.value = null
    menuPosition.value = {}
  } else {
    activeMenu.value = todoId
    const button = event.currentTarget
    const rect = button.getBoundingClientRect()
    const menuWidth = 192
    const menuHeight = 140
    const viewportHeight = window.innerHeight
    const viewportWidth = window.innerWidth
    let top = rect.bottom + 4
    let left = rect.right - menuWidth
    if (rect.bottom + menuHeight > viewportHeight) top = rect.top - menuHeight - 4
    if (left < 0) left = rect.left
    if (left + menuWidth > viewportWidth) left = viewportWidth - menuWidth - 10
    menuPosition.value = { top: `${top}px`, left: `${left}px` }
  }
}

const closeMenu = () => {
  activeMenu.value = null
  menuPosition.value = {}
}

// Click outside handler
const handleClickOutside = (event) => {
  const menu = document.querySelector('[data-dropdown-menu]')
  const menuButton = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(event.target) && menuButton && !menuButton.contains(event.target)) {
    closeMenu()
  }
}

// Keyboard handler
const handleKeydown = (event) => {
  if (event.key === 'Escape') closeMenu()
}

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
  formError.value = ''
  isEditing.value = false
  editingTodo.value = null
}

const openCreateModal = () => {
  resetForm()
  showFormOverlay.value = true
}

const openEditModal = (todo) => {
  closeMenu()
  isEditing.value = true
  editingTodo.value = todo
  showFormOverlay.value = true
}

const closeForm = () => {
  showFormOverlay.value = false
  setTimeout(resetForm, 300)
}

const handleSubmit = async (formDataValue) => {
  formSubmitting.value = true
  formError.value = ''

  try {
    if (isEditing.value) {
      await updateTodo(editingTodo.value.id, formDataValue)
    } else {
      await createTodo(formDataValue)
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
  actions.push({ label: 'View', onClick: () => viewTodo(todo), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' })
  actions.push({ label: 'Edit', onClick: () => openEditModal(todo), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
  actions.push({ label: 'Delete', onClick: () => handleDelete(todo), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  return actions
}

const handleComplete = async (todo) => {
  closeMenu()
  try { 
    await markAsCompleted(todo.id)
    // If details overlay is open, refresh the selected todo
    if (showDetailsOverlay.value && selectedTodo.value?.id === todo.id) {
      selectedTodo.value = { ...selectedTodo.value, status: 'completed' }
    }
  } catch (err) { 
    console.error('Failed to complete todo:', err) 
  }
}

const handleDelete = async (todo) => {
  closeMenu()
  const confirmed = await confirmStore.open({
    title: 'Delete Todo',
    message: `Are you sure you want to delete "${todo.title}"? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger'
  })
  
  if (!confirmed) return
  
  try { 
    await deleteTodo(todo.id) 
  } catch (err) { 
    console.error('Failed to delete todo:', err) 
  }
}

const handleReopen = async (todo) => {
  closeMenu()
  try {
    await updateTodo(todo.id, { status: 'pending' })
    // If details overlay is open, refresh the selected todo
    if (showDetailsOverlay.value && selectedTodo.value?.id === todo.id) {
      selectedTodo.value = { ...selectedTodo.value, status: 'pending' }
    }
    await fetchTodos()
  } catch (err) {
    console.error('Failed to reopen todo:', err)
  }
}

const viewTodo = async (todo) => {
  closeMenu()
  selectedTodo.value = todo
  showDetailsOverlay.value = true
  detailsLoading.value = true
  detailsError.value = ''
  
  try {
    // Fetch fresh todo details from API
    const freshTodo = await fetchTodo(todo.id)
    if (freshTodo) {
      selectedTodo.value = freshTodo
    }
  } catch (err) {
    detailsError.value = err.message || 'Failed to load todo details'
    console.error('Failed to fetch todo details:', err)
  } finally {
    detailsLoading.value = false
  }
}

const closeDetails = () => {
  showDetailsOverlay.value = false
  setTimeout(() => { selectedTodo.value = null }, 300)
}

// Lifecycle
onMounted(async () => {
  await fetchTodos()
  await fetchStatistics()
  setupWebSocketListeners()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  cleanupWebSocketListeners()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
