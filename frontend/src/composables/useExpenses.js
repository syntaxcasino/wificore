/**
 * Expense Management Composable - Event-Driven
 * WiFi Hotspot System - Finance Module
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useToast } from '@/composables/useToast'

export function useExpenses() {
  const loading = ref(false)
  const error = ref(null)
  const expenses = ref([])
  const stats = ref({
    "total_expenses": 0,
    "total_amount": 0,
    "by_status": {},
    "by_category": [],
    "by_payment_method": []
})
  
  const { toast } = useToast()

  // Computed filters
  
  const pendingExpenses = computed(() => 
    expenses.value.filter(item => item.status === 'pending')
  )
  
  const approvedExpenses = computed(() => 
    expenses.value.filter(item => item.status === 'approved')
  )
  
  const rejectedExpenses = computed(() => 
    expenses.value.filter(item => item.status === 'rejected')
  )
  
  const paidExpenses = computed(() => 
    expenses.value.filter(item => item.status === 'paid')
  )

  // API Functions
  const fetchExpenses = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/expenses?${params}` : '/expenses'
      const response = await axios.get(url)
      
      expenses.value = response.data.data || response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch expenses'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/expenses/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createExpense = async (data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/expenses', data)
      
      expenses.value.unshift(response.data.data)
      toast.success('Expense created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateExpense = async (id, data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/expenses/${id}`, data)
      
      const index = expenses.value.findIndex(item => item.id === id)
      if (index !== -1) {
        expenses.value[index] = response.data.data
      }
      
      toast.success('Expense updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteExpense = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/expenses/${id}`)
      
      expenses.value = expenses.value.filter(item => item.id !== id)
      toast.success('Expense deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const approveExpense = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/expenses/${id}/approve`, data)
      
      const index = expenses.value.findIndex(item => item.id === id)
      if (index !== -1) {
        expenses.value[index] = response.data.data
      }
      
      toast.success('Expense approve successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to approve expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const rejectExpense = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/expenses/${id}/reject`, data)
      
      const index = expenses.value.findIndex(item => item.id === id)
      if (index !== -1) {
        expenses.value[index] = response.data.data
      }
      
      toast.success('Expense reject successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to reject expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const markAsPaidExpense = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/expenses/${id}/mark-as-paid`, data)
      
      const index = expenses.value.findIndex(item => item.id === id)
      if (index !== -1) {
        expenses.value[index] = response.data.data
      }
      
      toast.success('Expense markAsPaid successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to markAsPaid expense'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const getExpenseById = (id) => {
    return expenses.value.find(item => item.id === id)
  }

  const searchExpenses = (query) => {
    const lowerQuery = query.toLowerCase()
    return expenses.value.filter(item => 
      item.expense_number?.toLowerCase().includes(lowerQuery) ||
      item.description?.toLowerCase().includes(lowerQuery) ||
      item.vendor_name?.toLowerCase().includes(lowerQuery)
    )
  }

  // Event handlers for WebSocket
  const handleExpenseCreated = (expense) => {
    const exists = expenses.value.find(item => item.id === expense.id)
    if (!exists) {
      expenses.value.unshift(expense)
    }
  }

  const handleExpenseUpdated = (expense) => {
    const index = expenses.value.findIndex(item => item.id === expense.id)
    if (index !== -1) {
      expenses.value[index] = { ...expenses.value[index], ...expense }
    }
  }

  const handleExpenseDeleted = (expenseId) => {
    expenses.value = expenses.value.filter(item => item.id !== expenseId)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('expense-created', (event) => {
      if (event.detail?.expense) {
        handleExpenseCreated(event.detail.expense)
      }
    })

    window.addEventListener('expense-updated', (event) => {
      if (event.detail?.expense) {
        handleExpenseUpdated(event.detail.expense)
      }
    })

    window.addEventListener('expense-deleted', (event) => {
      if (event.detail?.expenseId) {
        handleExpenseDeleted(event.detail.expenseId)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('expense-created', handleExpenseCreated)
    window.removeEventListener('expense-updated', handleExpenseUpdated)
    window.removeEventListener('expense-deleted', handleExpenseDeleted)
  }

  return {
    // Reactive data
    expenses,
    stats,
    pendingExpenses,
    approvedExpenses,
    rejectedExpenses,
    paidExpenses,
    loading,
    error,

    // API functions
    fetchExpenses,
    fetchStatistics,
    createExpense,
    updateExpense,
    deleteExpense,
    approveExpense,
    rejectExpense,
    markAsPaidExpense,

    // Utility functions
    getExpenseById,
    searchExpenses,

    // Event handlers
    handleExpenseCreated,
    handleExpenseUpdated,
    handleExpenseDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
