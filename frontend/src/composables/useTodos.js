/**
 * Todo Management Composable - Event-Driven (No Polling)
 * WiFi Hotspot System
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

export function useTodos() {
  const loading = ref(false)
  const error = ref(null)
  const todos = ref([])
  const stats = ref({
    total: 0,
    pending: 0,
    in_progress: 0,
    completed: 0,
    overdue: 0
  })
  
  const { toast } = useToast()
  const authStore = useAuthStore()

  // Computed filters
  const pendingTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'pending')
  )
  
  const completedTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'completed')
  )
  
  const inProgressTodos = computed(() => 
    todos.value.filter(todo => todo.status === 'in_progress')
  )
  
  const overdueTodos = computed(() => 
    todos.value.filter(todo => {
      if (todo.status === 'completed' || !todo.due_date) return false
      return new Date(todo.due_date) < new Date()
    })
  )

  // API Functions (trigger events, no polling needed)
  const fetchTodos = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/todos?${params}` : '/todos'
      const response = await axios.get(url)
      
      todos.value = response.data
      updateStats()
      
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch todos'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async (tenantWide = false) => {
    try {
      const params = tenantWide ? '?tenant_wide=true' : ''
      const response = await axios.get(`/todos/statistics${params}`)
      stats.value = response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createTodo = async (todoData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/todos', todoData)
      
      // Add to local state (will be updated by event)
      todos.value.unshift(response.data.todo)
      updateStats()
      
      toast.success('Todo created successfully')
      return response.data.todo
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create todo'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateTodo = async (todoId, updates) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/todos/${todoId}`, updates)
      
      // Update local state (will be updated by event)
      const index = todos.value.findIndex(t => t.id === todoId)
      if (index !== -1) {
        todos.value[index] = response.data.todo
        updateStats()
      }
      
      toast.success('Todo updated successfully')
      return response.data.todo
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update todo'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteTodo = async (todoId) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/todos/${todoId}`)
      
      // Remove from local state (will be updated by event)
      todos.value = todos.value.filter(t => t.id !== todoId)
      updateStats()
      
      toast.success('Todo deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete todo'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const markAsCompleted = async (todoId) => {
    try {
      const response = await axios.post(`/todos/${todoId}/complete`)
      
      // Update local state
      const index = todos.value.findIndex(t => t.id === todoId)
      if (index !== -1) {
        todos.value[index] = response.data.todo
        updateStats()
      }
      
      toast.success('Todo marked as completed')
      return response.data.todo
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to complete todo'
      toast.error(error.value)
      throw err
    }
  }

  const markAsInProgress = async (todoId) => {
    return updateTodo(todoId, { status: 'in_progress' })
  }

  const assignTodo = async (todoId, userId) => {
    try {
      const response = await axios.post(`/todos/${todoId}/assign`, { user_id: userId })
      
      // Update local state
      const index = todos.value.findIndex(t => t.id === todoId)
      if (index !== -1) {
        todos.value[index] = response.data.todo
      }
      
      toast.success('Todo assigned successfully')
      return response.data.todo
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to assign todo'
      toast.error(error.value)
      throw err
    }
  }

  const fetchActivities = async (todoId) => {
    try {
      const response = await axios.get(`/todos/${todoId}/activities`)
      return response.data
    } catch (err) {
      console.error('Failed to fetch activities:', err)
      return []
    }
  }

  // Utility functions
  const updateStats = () => {
    stats.value = {
      total: todos.value.length,
      pending: pendingTodos.value.length,
      in_progress: inProgressTodos.value.length,
      completed: completedTodos.value.length,
      overdue: overdueTodos.value.length
    }
  }

  const getTodoById = (id) => {
    return todos.value.find(todo => todo.id === id)
  }

  const getTodosByStatus = (status) => {
    return todos.value.filter(todo => todo.status === status)
  }

  const getTodosByPriority = (priority) => {
    return todos.value.filter(todo => todo.priority === priority)
  }

  // Search and filter
  const searchTodos = (query) => {
    if (!query) return todos.value
    
    const lowercaseQuery = query.toLowerCase()
    return todos.value.filter(todo => 
      todo.title.toLowerCase().includes(lowercaseQuery) ||
      todo.description?.toLowerCase().includes(lowercaseQuery)
    )
  }

  // Event handlers for WebSocket updates
  const handleTodoCreated = (todoData) => {
    // Check if todo already exists
    const exists = todos.value.some(t => t.id === todoData.id)
    if (!exists) {
      todos.value.unshift(todoData)
      updateStats()
    }
  }

  const handleTodoUpdated = (todoData) => {
    const index = todos.value.findIndex(t => t.id === todoData.id)
    if (index !== -1) {
      todos.value[index] = todoData
      updateStats()
    }
  }

  const handleTodoDeleted = (todoId) => {
    todos.value = todos.value.filter(t => t.id !== todoId)
    updateStats()
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    // Listen for todo-created event
    window.addEventListener('todo-created', (event) => {
      if (event.detail?.todo) {
        handleTodoCreated(event.detail.todo)
      }
    })

    // Listen for todo-updated event
    window.addEventListener('todo-updated', (event) => {
      if (event.detail?.todo) {
        handleTodoUpdated(event.detail.todo)
      }
    })

    // Listen for todo-deleted event
    window.addEventListener('todo-deleted', (event) => {
      if (event.detail?.todoId) {
        handleTodoDeleted(event.detail.todoId)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('todo-created', handleTodoCreated)
    window.removeEventListener('todo-updated', handleTodoUpdated)
    window.removeEventListener('todo-deleted', handleTodoDeleted)
  }

  return {
    // Reactive data
    todos,
    stats,
    pendingTodos,
    completedTodos,
    inProgressTodos,
    overdueTodos,
    loading,
    error,

    // API functions
    fetchTodos,
    fetchStatistics,
    createTodo,
    updateTodo,
    deleteTodo,
    markAsCompleted,
    markAsInProgress,
    assignTodo,
    fetchActivities,

    // Utility functions
    getTodoById,
    getTodosByStatus,
    getTodosByPriority,
    searchTodos,
    updateStats,

    // Event handlers
    handleTodoCreated,
    handleTodoUpdated,
    handleTodoDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
