/**
 * Todo Management Composable - Event-Driven (No Polling)
 * WiFi Hotspot System
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/modules/common/composables/useToast'

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
    Array.isArray(todos.value) ? todos.value.filter(todo => todo.status === 'pending') : []
  )
  
  const completedTodos = computed(() => 
    Array.isArray(todos.value) ? todos.value.filter(todo => todo.status === 'completed') : []
  )
  
  const inProgressTodos = computed(() => 
    Array.isArray(todos.value) ? todos.value.filter(todo => todo.status === 'in_progress') : []
  )
  
  const overdueTodos = computed(() => 
    Array.isArray(todos.value) ? todos.value.filter(todo => {
      if (todo.status === 'completed' || !todo.due_date) return false
      return new Date(todo.due_date) < new Date()
    }) : []
  )

  // API Functions (trigger events, no polling needed)
  const fetchTodos = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/todos?${params}` : '/todos'
      const response = await axios.get(url)
      
      // Debug logging to understand response structure
      console.log('fetchTodos response:', response)
      console.log('response.data:', response?.data)
      
      // Handle various response structures safely
      let todoData = []
      if (response?.data) {
        if (Array.isArray(response.data)) {
          todoData = response.data
        } else if (response.data.todos && Array.isArray(response.data.todos)) {
          todoData = response.data.todos
        } else if (response.data.data && Array.isArray(response.data.data)) {
          todoData = response.data.data
        } else if (typeof response.data === 'object') {
          // If it's a single todo object wrapped, try to extract
          todoData = [response.data]
        }
      }
      
      todos.value = todoData
      updateStats()
      
      return todoData
    } catch (err) {
      console.error('fetchTodos error:', err)
      // Safely extract error message with multiple fallbacks
      let errorMsg = 'Failed to fetch todos'
      try {
        if (err?.response?.data?.message) {
          errorMsg = err.response.data.message
        } else if (err?.response?.data?.error) {
          errorMsg = err.response.data.error
        } else if (err?.message) {
          errorMsg = err.message
        } else if (typeof err === 'string') {
          errorMsg = err
        }
      } catch (e) {
        errorMsg = 'An unexpected error occurred'
      }
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchTodo = async (todoId) => {
    try {
      const response = await axios.get(`/todos/${todoId}`)
      // Handle both direct response and wrapped response
      return response.data.todo || response.data
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.message || String(err) || 'Failed to fetch todo details'
      toast.error(errorMsg)
      throw err
    }
  }

  const fetchStatistics = async (tenantWide = false) => {
    try {
      const params = tenantWide ? '?tenant_wide=true' : ''
      const response = await axios.get(`/todos/statistics${params}`)
      const statsData = response.data || {}
      stats.value = statsData
      return statsData
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
      
      // Handle both wrapped {todo: ...} and direct response
      const newTodo = response.data?.todo || response.data
      
      // Add to local state (will be updated by event)
      if (newTodo) {
        todos.value.unshift(newTodo)
        updateStats()
      }
      
      toast.success('Todo created successfully')
      return newTodo
      
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to create todo'
      error.value = errorMsg
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
      
      // Handle both wrapped {todo: ...} and direct response
      const updatedTodo = response.data?.todo || response.data
      
      // Update local state (will be updated by event)
      if (updatedTodo) {
        const index = todos.value.findIndex(t => t.id === todoId)
        if (index !== -1) {
          todos.value[index] = updatedTodo
          updateStats()
        }
      }
      
      toast.success('Todo updated successfully')
      return updatedTodo
      
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to update todo'
      error.value = errorMsg
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
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to delete todo'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const markAsCompleted = async (todoId) => {
    try {
      const response = await axios.post(`/todos/${todoId}/complete`)
      
      // Handle both wrapped {todo: ...} and direct response
      const completedTodo = response.data?.todo || response.data
      
      // Update local state
      const index = todos.value.findIndex(t => t.id === todoId)
      if (index !== -1 && completedTodo) {
        todos.value[index] = completedTodo
        updateStats()
      }
      
      toast.success('Todo marked as completed')
      return completedTodo
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to complete todo'
      error.value = errorMsg
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
        const assignedTodo = response.data?.todo || response.data
        if (assignedTodo) {
          todos.value[index] = assignedTodo
        }
      }
      
      toast.success('Todo assigned successfully')
      return response.data?.todo || response.data
    } catch (err) {
      const errorMsg = err?.response?.data?.message || err?.response?.data?.error || err?.message || String(err) || 'Failed to assign todo'
      error.value = errorMsg
      toast.error(error.value)
      throw err
    }
  }

  const fetchActivities = async (todoId) => {
    try {
      const response = await axios.get(`/todos/${todoId}/activities`)
      return response.data || []
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
  const handleTodoCreated = (event) => {
    const todoData = event.detail?.todo
    if (!todoData) return
    // Check if todo already exists
    const exists = todos.value.some(t => t.id === todoData.id)
    if (!exists) {
      todos.value.unshift(todoData)
      updateStats()
    }
  }

  const handleTodoUpdated = (event) => {
    const todoData = event.detail?.todo
    if (!todoData) return
    const index = todos.value.findIndex(t => t.id === todoData.id)
    if (index !== -1) {
      todos.value[index] = todoData
      updateStats()
    }
  }

  const handleTodoDeleted = (event) => {
    const todoId = event.detail?.todoId
    if (!todoId) return
    todos.value = todos.value.filter(t => t.id !== todoId)
    updateStats()
  }

  // Setup WebSocket event listeners - use named handlers
  const setupWebSocketListeners = () => {
    window.addEventListener('todo-created', handleTodoCreated)
    window.addEventListener('todo-updated', handleTodoUpdated)
    window.addEventListener('todo-deleted', handleTodoDeleted)
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
    fetchTodo,

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
