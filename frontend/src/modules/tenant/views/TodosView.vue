<template>
  <div class="bg-gradient-to-br from-blue-50 via-indigo-50/50 to-purple-50/30 -mx-6 -my-6 px-6 py-8 pb-16">
    <!-- Header -->
    <div class="mb-10">
      <div class="flex items-center justify-between flex-wrap gap-6">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-indigo-600 rounded-xl flex items-center justify-center shadow-lg">
              <CheckSquare class="w-7 h-7 text-white" />
            </div>
            <div>
              <h1 class="text-4xl font-bold bg-gradient-to-r from-gray-900 to-gray-700 bg-clip-text text-transparent">My Todos</h1>
              <p class="text-sm text-gray-600 mt-1 font-medium">Manage your tasks and priorities</p>
            </div>
          </div>
        </div>
        <div class="flex items-center gap-3">
          <button
            @click="openCreateForm"
            class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 flex items-center gap-2 font-semibold"
          >
            <Plus class="w-5 h-5" />
            Add Todo
          </button>
        </div>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="mb-8">
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6">
        <!-- Total Tasks -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
              <ListTodo class="w-6 h-6 text-blue-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Total Tasks</p>
          <h3 class="text-3xl font-bold text-gray-900">{{ stats.total }}</h3>
        </div>

        <!-- Pending -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-orange-100 rounded-lg flex items-center justify-center">
              <Clock class="w-6 h-6 text-orange-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Pending</p>
          <h3 class="text-3xl font-bold text-orange-600">{{ stats.pending }}</h3>
        </div>

        <!-- In Progress -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-blue-100 rounded-lg flex items-center justify-center">
              <PlayCircle class="w-6 h-6 text-blue-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">In Progress</p>
          <h3 class="text-3xl font-bold text-blue-600">{{ stats.in_progress }}</h3>
        </div>

        <!-- Completed -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 hover:shadow-lg transition-all duration-300">
          <div class="flex items-center justify-between mb-4">
            <div class="w-12 h-12 bg-green-100 rounded-lg flex items-center justify-center">
              <CheckCircle2 class="w-6 h-6 text-green-600" />
            </div>
          </div>
          <p class="text-sm font-medium text-gray-600 mb-1">Completed</p>
          <h3 class="text-3xl font-bold text-green-600">{{ stats.completed }}</h3>
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
            ? 'bg-blue-100 text-blue-700 shadow-sm' 
            : 'text-gray-600 hover:bg-gray-100'"
        >
          {{ filter.label }}
          <span 
            class="ml-2 px-2 py-0.5 rounded-full text-xs font-bold"
            :class="activeFilter === filter.value ? 'bg-blue-200 text-blue-800' : 'bg-gray-200 text-gray-700'"
          >
            {{ getFilterCount(filter.value) }}
          </span>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 border-4 border-blue-200 border-t-blue-600 rounded-full animate-spin mx-auto mb-4"></div>
        <p class="text-gray-600">Loading todos...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredTodos.length === 0" class="text-center py-20">
      <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-12">
        <div class="w-20 h-20 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-6">
          <CheckSquare class="w-10 h-10 text-blue-600" />
        </div>
        <h3 class="text-2xl font-bold text-gray-900 mb-2">No todos found</h3>
        <p class="text-gray-600 mb-6">Create your first todo to get started</p>
        <button
          @click="openCreateForm"
          class="px-6 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 text-white rounded-xl shadow-lg hover:shadow-xl transition-all duration-300 inline-flex items-center gap-2 font-semibold"
        >
          <Plus class="w-5 h-5" />
          Add Todo
        </button>
      </div>
    </div>

    <!-- Todo List -->
    <div v-else class="space-y-4">
      <TodoCard
        v-for="todo in filteredTodos"
        :key="todo.id"
        :todo="todo"
        @edit="openEditForm"
        @delete="handleDelete"
        @view="viewTodoDetails"
        @start="handleStart"
        @complete="handleComplete"
      />
    </div>

    <!-- Slide Overlay for Create/Edit -->
    <SlideOverlay
      v-model="showForm"
      :title="isEdit ? 'Edit Todo' : 'Create New Todo'"
      :subtitle="isEdit ? 'Update todo details' : 'Add a new task to your list'"
      icon="CheckSquare"
      width="40%"
    >
      <TodoForm
        :todo="selectedTodo"
        :is-edit="isEdit"
        :loading="formLoading"
        @submit="handleSubmit"
        @cancel="closeForm"
      />
    </SlideOverlay>

    <!-- Slide Overlay for View Details -->
    <SlideOverlay
      v-model="showViewModal"
      title="Todo Details"
      subtitle="View task information and activity history"
      icon="Eye"
      width="50%"
    >
      <div v-if="viewingTodo" class="space-y-6">
        <!-- Todo Details -->
        <div class="bg-gray-50 rounded-lg p-6 border border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <CheckSquare class="w-5 h-5 text-blue-600" />
            Task Details
          </h3>
          
          <div class="space-y-4">
            <!-- Title -->
            <div>
              <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Title</label>
              <p class="text-base text-gray-900 font-semibold">{{ viewingTodo.title }}</p>
            </div>

            <!-- Description -->
            <div>
              <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Description</label>
              <p class="text-sm text-gray-700 leading-relaxed">{{ viewingTodo.description || 'No description provided' }}</p>
            </div>

            <!-- Status & Priority -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Status</label>
                <span 
                  class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                  :class="getStatusClass(viewingTodo.status)"
                >
                  {{ viewingTodo.status }}
                </span>
              </div>
              <div>
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-2">Priority</label>
                <span 
                  class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold"
                  :class="getPriorityClass(viewingTodo.priority)"
                >
                  {{ viewingTodo.priority }}
                </span>
              </div>
            </div>

            <!-- Dates -->
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Created</label>
                <p class="text-sm text-gray-700 flex items-center gap-1">
                  <Clock class="w-3 h-3" />
                  {{ formatDate(viewingTodo.created_at) }}
                </p>
              </div>
              <div v-if="viewingTodo.due_date">
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Due Date</label>
                <p class="text-sm text-gray-700 flex items-center gap-1">
                  <Calendar class="w-3 h-3" />
                  {{ formatDate(viewingTodo.due_date) }}
                </p>
              </div>
            </div>

            <!-- People -->
            <div class="grid grid-cols-2 gap-4">
              <div v-if="viewingTodo.user">
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Assigned To</label>
                <p class="text-sm text-gray-700">{{ viewingTodo.user.name }}</p>
              </div>
              <div v-if="viewingTodo.creator">
                <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">Created By</label>
                <p class="text-sm text-gray-700">{{ viewingTodo.creator.name }}</p>
              </div>
            </div>
          </div>
        </div>

        <!-- Activity Timeline -->
        <div class="bg-white rounded-lg border border-gray-200 p-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-4 flex items-center gap-2">
            <ListTodo class="w-5 h-5 text-orange-600" />
            Activity Timeline
          </h3>
          
          <TodoActivityLog :todo-id="viewingTodo.id" />
        </div>

        <!-- Close Button -->
        <div class="flex justify-end pt-4 border-t border-gray-200">
          <button
            @click="showViewModal = false"
            class="px-6 py-2.5 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 transition-colors font-medium"
          >
            Close
          </button>
        </div>
      </div>
    </SlideOverlay>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Plus, Edit2, Trash2, CheckSquare, ListTodo, Clock, PlayCircle, CheckCircle2, Calendar, Eye } from 'lucide-vue-next'
import { useTodos } from '@/composables/useTodos'
import { useAuthStore } from '@/stores/auth'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import TodoCard from '@/modules/tenant/components/TodoCard.vue'
import TodoForm from '@/modules/tenant/components/TodoForm.vue'
import TodoActivityLog from '@/modules/tenant/components/TodoActivityLog.vue'

// Auth store for tenant context
const authStore = useAuthStore()

// Use todos composable
const { 
  todos, 
  stats, 
  loading, 
  fetchTodos, 
  createTodo, 
  updateTodo, 
  deleteTodo,
  markAsCompleted,
  markAsInProgress,
  setupWebSocketListeners,
  cleanupWebSocketListeners
} = useTodos()

// State
const showForm = ref(false)
const isEdit = ref(false)
const formLoading = ref(false)
const activeFilter = ref('all')
const selectedTodo = ref(null)
const showViewModal = ref(false)
const viewingTodo = ref(null)

const filters = [
  { label: 'All', value: 'all' },
  { label: 'Pending', value: 'pending' },
  { label: 'In Progress', value: 'in_progress' },
  { label: 'Completed', value: 'completed' }
]

// Computed
const filteredTodos = computed(() => {
  if (activeFilter.value === 'all') return todos.value
  return todos.value.filter(t => t.status === activeFilter.value)
})

// Methods
const getFilterCount = (filter) => {
  if (filter === 'all') return todos.value.length
  return todos.value.filter(t => t.status === filter).length
}

const getStatusClass = (status) => {
  const classes = {
    pending: 'bg-orange-100 text-orange-700',
    in_progress: 'bg-blue-100 text-blue-700',
    completed: 'bg-green-100 text-green-700'
  }
  return classes[status] || 'bg-gray-100 text-gray-700'
}

const getPriorityClass = (priority) => {
  const classes = {
    low: 'bg-gray-100 text-gray-700',
    medium: 'bg-yellow-100 text-yellow-700',
    high: 'bg-red-100 text-red-700'
  }
  return classes[priority] || 'bg-gray-100 text-gray-700'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const openCreateForm = () => {
  isEdit.value = false
  selectedTodo.value = null
  showForm.value = true
}

const openEditForm = (todo) => {
  if (todo.status === 'completed') {
    alert('Cannot edit completed todos')
    return
  }
  
  isEdit.value = true
  selectedTodo.value = todo
  showForm.value = true
}

const closeForm = () => {
  showForm.value = false
  selectedTodo.value = null
}

const handleSubmit = async (formData) => {
  formLoading.value = true
  
  try {
    if (isEdit.value) {
      await updateTodo(selectedTodo.value.id, formData)
    } else {
      await createTodo(formData)
    }
    
    closeForm()
  } catch (error) {
    console.error('Failed to save todo:', error)
  } finally {
    formLoading.value = false
  }
}

const handleDelete = async (todo) => {
  if (confirm(`Are you sure you want to delete "${todo.title}"?`)) {
    try {
      await deleteTodo(todo.id)
    } catch (error) {
      console.error('Failed to delete todo:', error)
    }
  }
}

const handleStart = async (todo) => {
  try {
    await markAsInProgress(todo.id)
  } catch (error) {
    console.error('Failed to start todo:', error)
  }
}

const handleComplete = async (todo) => {
  try {
    await markAsCompleted(todo.id)
  } catch (error) {
    console.error('Failed to complete todo:', error)
  }
}

const viewTodoDetails = (todo) => {
  viewingTodo.value = todo
  showViewModal.value = true
}

// Lifecycle
onMounted(async () => {
  // Fetch todos for current tenant (multi-tenancy enforced by backend)
  await fetchTodos()
  
  // Setup WebSocket listeners for real-time updates
  setupWebSocketListeners()
  
  console.log('✅ TodosView mounted - tenant context:', authStore.tenantId)
})

onUnmounted(() => {
  // Cleanup WebSocket listeners
  cleanupWebSocketListeners()
  
  console.log('📴 TodosView unmounted - cleaned up listeners')
})
</script>

<style scoped>
/* Add any custom styles here */
</style>
