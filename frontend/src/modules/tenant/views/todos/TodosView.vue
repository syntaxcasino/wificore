<template>
  <DataViewContainer
    title="Todo Management"
    subtitle="Track and manage tasks"
    color-theme="blue"
    v-model:search-model="searchQuery"
    search-placeholder="Search todos by title or description..."
    :stats="[
      { color: 'bg-blue-500', value: pendingCount, tooltip: 'Pending - not started' },
      { color: 'bg-amber-500', value: inProgressCount, tooltip: 'In progress' },
      { color: 'bg-emerald-500', value: completedCount, tooltip: 'Completed' }
    ]"
    :total="todos?.length || 0"
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
      @transfer="openTransferModal(selectedTodo)"
    />

    <!-- Transfer Modal -->
    <SlideOverlay
      v-model="showTransferModal"
      title="Transfer Todo"
      subtitle="Assign this task to another user"
      icon="user-plus"
      width="60%"
      @close="closeTransferModal"
    >
      <div class="p-6">
        <div v-if="transferLoading" class="flex items-center justify-center py-8">
          <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        </div>
        <div v-else>
          <p class="text-sm text-slate-600 mb-4">
            Transfer "{{ selectedTodo?.title }}" to another user:
          </p>
          <div class="space-y-2 max-h-64 overflow-y-auto">
            <button
              v-for="user in users"
              :key="user.id"
              @click="handleTransfer(user.id)"
              class="w-full flex items-center gap-3 p-3 rounded-lg border border-slate-200 hover:border-blue-300 hover:bg-blue-50 transition-colors text-left"
              :class="{ 'opacity-50 cursor-not-allowed': user.id === selectedTodo?.assigned_to?.id || user.id === selectedTodo?.handler?.id }"
              :disabled="user.id === selectedTodo?.assigned_to?.id || user.id === selectedTodo?.handler?.id"
            >
              <div class="w-8 h-8 rounded-full bg-gradient-to-br from-blue-500 to-blue-600 flex items-center justify-center text-white font-semibold text-xs">
                {{ user.name?.split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase() || user.email?.slice(0, 2).toUpperCase() || '?' }}
              </div>
              <div class="flex-1">
                <p class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.name || user.full_name || user.email || 'Unknown' }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ user.email || '' }}</p>
              </div>
              <span v-if="user.id === selectedTodo?.assigned_to?.id || user.id === selectedTodo?.handler?.id" class="text-xs text-slate-400">Current</span>
            </button>
          </div>
          <div v-if="users.length === 0" class="text-center py-8 text-slate-500">
            No users available
          </div>
        </div>
      </div>
    </SlideOverlay>
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
    <div v-else-if="filteredTodos?.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="todo in paginatedTodos"
          :key="todo?.id"
          :title="todo?.title || 'Untitled'"
          :subtitle="todo?.description"
          :meta-lines="getTodoMetaLines(todo)"
          :status="todo?.status"
          :actions="getTodoActions(todo)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border-x border-t border-slate-200 flex-col min-h-0 flex-1">
        <!-- Fixed Header -->
        <div class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
          <table class="w-full">
            <thead>
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[25%]">Title</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider hidden lg:table-cell w-[20%]">Description</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Priority</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Due Date</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[10%]">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider w-[12%]">Handler</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider w-[15%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <!-- Scrollable Body -->
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <tbody class="divide-y divide-slate-100 dark:divide-slate-700">
              <tr v-for="todo in paginatedTodos" :key="todo?.id" class="hover:bg-blue-50/50 transition-colors">
                <td class="px-6 py-4 w-[25%]">
                  <div class="flex items-center gap-2">
                    <span :class="getStatusDotClass(todo?.status)" class="w-1.5 h-1.5 rounded-full"></span>
                    <span class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ todo?.title || 'Untitled' }}</span>
                  </div>
                </td>
                <td class="px-6 py-4 hidden lg:table-cell w-[20%]">
                  <span class="text-sm text-slate-600 truncate max-w-xs block">{{ todo?.description || '-' }}</span>
                </td>
                <td class="px-6 py-4 w-[10%]">
                  <span :class="priorityBadgeClass(todo?.priority)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium capitalize">
                    {{ todo?.priority || 'normal' }}
                  </span>
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <span class="text-sm text-slate-600 dark:text-slate-400">{{ formatDate(todo?.due_date) }}</span>
                </td>
                <td class="px-6 py-4 w-[10%]">
                  <EntityStatusBadge :status="todo?.status" size="sm" />
                </td>
                <td class="px-6 py-4 w-[12%]">
                  <span class="text-sm text-slate-600 dark:text-slate-400">{{ getHandlerDisplay(todo) }}</span>
                </td>
                <td class="px-6 py-4 text-right w-[15%]">
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
        :total-items="filteredTodos?.length || 0"
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
      <div v-if="activeMenu !== null" data-dropdown-menu :style="menuPosition" class="fixed w-48 bg-white dark:bg-slate-800 rounded-lg shadow-2xl border border-slate-200 dark:border-slate-700 py-1 z-[9999] overflow-hidden">
        <button v-if="todos.find(t => t.id === activeMenu)?.status === 'pending'" @click="handleStart(todos.find(t => t.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-blue-50 hover:text-blue-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Start
        </button>
        <button v-if="todos.find(t => t.id === activeMenu)?.status === 'in_progress'" @click="handleComplete(todos.find(t => t.id === activeMenu))" class="flex items-center w-full px-4 py-2.5 text-sm text-emerald-700 hover:bg-emerald-50 hover:text-emerald-700 transition-colors">
          <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          Complete
        </button>
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
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import TodoModal from '@/modules/tenant/components/todos/TodoModal.vue'
import TodoDetailsModal from '@/modules/tenant/components/todos/TodoDetailsModal.vue'

const confirmStore = useConfirmStore()

const {
  todos,
  pendingTodos,
  completedTodos,
  loading,
  error,
  users,
  fetchTodos,
  fetchStatistics,
  createTodo,
  updateTodo,
  deleteTodo,
  markAsCompleted,
  markAsInProgress,
  assignTodo,
  fetchTodo,
  searchTodos,
  fetchUsers,
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

// Transfer state
const showTransferModal = ref(false)
const transferLoading = ref(false)

// Menu state
const activeMenu = ref(null)
const menuPosition = ref({})

// Stats
const pendingCount = computed(() => todos.value?.filter(t => t?.status === 'pending')?.length ?? 0)
const inProgressCount = computed(() => todos.value?.filter(t => t?.status === 'in_progress')?.length ?? 0)
const completedCount = computed(() => completedTodos.value?.length ?? 0)
const totalCount = computed(() => todos.value?.length ?? 0)

// Filter and paginate
const filteredTodos = computed(() => {
  if (!searchQuery.value) return todos.value
  return searchTodos(searchQuery.value)
})

const paginatedTodos = computed(() => {
  if (!filteredTodos.value || !Array.isArray(filteredTodos.value)) return []
  const start = (currentPage.value - 1) * itemsPerPage.value
  const end = start + itemsPerPage.value
  return filteredTodos.value.slice(start, end)
})

const totalPages = computed(() => Math.ceil((filteredTodos.value?.length || 0) / itemsPerPage.value))

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
const getStatusDotClass = (status) => {
  if (status === 'completed') return 'bg-emerald-500'
  if (status === 'in_progress') return 'bg-blue-500'
  return 'bg-amber-500'
}

const getHandlerDisplay = (todo) => {
  const handler = todo?.handler || todo?.assigned_to
  if (!handler) return 'Unassigned'
  return handler.name || handler.full_name || handler.email?.split('@')[0] || 'Unknown'
}

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
    // Real-time update via WebSocket - no fetchTodos() needed
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
  
  // Show Start for pending todos
  if (todo.status === 'pending') {
    actions.push({ label: 'Start', onClick: () => handleStart(todo), class: 'text-blue-700 bg-blue-50 hover:bg-blue-100' })
  }
  
  // Show Complete for in_progress todos
  if (todo.status === 'in_progress') {
    actions.push({ label: 'Complete', onClick: () => handleComplete(todo), class: 'text-emerald-700 bg-emerald-50 hover:bg-emerald-100' })
  }
  
  // Only show Edit/Delete for non-completed todos
  if (todo.status !== 'completed') {
    actions.push({ label: 'Edit', onClick: () => openEditModal(todo), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' })
    actions.push({ label: 'Delete', onClick: () => handleDelete(todo), class: 'text-red-600 bg-red-50 hover:bg-red-100' })
  }
  
  return actions
}

const handleStart = async (todo) => {
  closeMenu()
  try {
    await markAsInProgress(todo.id)
    // If details overlay is open, refresh the selected todo
    if (showDetailsOverlay.value && selectedTodo.value?.id === todo.id) {
      selectedTodo.value = { ...selectedTodo.value, status: 'in_progress' }
    }
    // Real-time update via WebSocket - no fetchTodos() needed
  } catch (err) {
    console.error('Failed to start todo:', err)
  }
}

const handleComplete = async (todo) => {
  closeMenu()
  try { 
    await markAsCompleted(todo.id)
    // If details overlay is open, refresh the selected todo
    if (showDetailsOverlay.value && selectedTodo.value?.id === todo.id) {
      selectedTodo.value = { ...selectedTodo.value, status: 'completed' }
    }
    // Real-time update via WebSocket - no fetchTodos() needed
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
    // Real-time update via WebSocket - no fetchTodos() needed
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

const openTransferModal = (todo) => {
  selectedTodo.value = todo
  showTransferModal.value = true
  fetchUsers()
}

const closeTransferModal = () => {
  showTransferModal.value = false
}


const handleTransfer = async (userId) => {
  if (!selectedTodo.value || !userId) return
  
  transferLoading.value = true
  try {
    await assignTodo(selectedTodo.value.id, userId)
    // Real-time update via WebSocket - no fetchTodos() needed
    closeTransferModal()
  } catch (err) {
    console.error('Failed to transfer todo:', err)
  } finally {
    transferLoading.value = false
  }
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
/* Scrollbar — no Tailwind equivalent for ::-webkit-scrollbar pseudo-elements */
::-webkit-scrollbar        { width: 8px; height: 8px; }
::-webkit-scrollbar-track  { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb  { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
:global(.dark) ::-webkit-scrollbar-track { background: #1e293b; }
:global(.dark) ::-webkit-scrollbar-thumb { background: #475569; }
</style>
