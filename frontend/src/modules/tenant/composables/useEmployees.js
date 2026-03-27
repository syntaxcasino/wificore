/**
 * Employee Management Composable - Event-Driven
 * WiFi Hotspot System - HR Module
 */

import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'
import { useToast } from '@/modules/common/composables/useToast'

export function useEmployees() {
  const loading = ref(false)
  const error = ref(null)
  const employees = ref([])
  const stats = ref({
    "total": 0,
    "active": 0,
    "on_leave": 0,
    "suspended": 0,
    "terminated": 0,
    "by_type": {},
    "by_department": []
})
  
  const { toast } = useToast()

  // Computed filters
  
  const activeEmployees = computed(() => 
    Array.isArray(employees.value) ? employees.value.filter(item => item.status === 'active') : []
  )
  
  const on_leaveEmployees = computed(() => 
    Array.isArray(employees.value) ? employees.value.filter(item => item.status === 'on_leave') : []
  )
  
  const suspendedEmployees = computed(() => 
    Array.isArray(employees.value) ? employees.value.filter(item => item.status === 'suspended') : []
  )
  
  const terminatedEmployees = computed(() => 
    Array.isArray(employees.value) ? employees.value.filter(item => item.status === 'terminated') : []
  )

  // API Functions
  const fetchEmployees = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/employees?${params}` : '/employees'
      const response = await axios.get(url)
      
      const data = response.data.data || response.data
      employees.value = Array.isArray(data) ? data : []
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch employees'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/employees/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createEmployee = async (data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/employees', data)
      
      employees.value.unshift(response.data.data)
      toast.success('Employee created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create employee'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateEmployee = async (id, data) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/employees/${id}`, data)
      
      const index = employees.value.findIndex(item => item.id === id)
      if (index !== -1) {
        employees.value[index] = response.data.data
      }
      
      toast.success('Employee updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update employee'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteEmployee = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/employees/${id}`)
      
      employees.value = employees.value.filter(item => item.id !== id)
      toast.success('Employee deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete employee'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const terminateEmployee = async (id, data = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/employees/${id}/terminate`, data)
      
      const index = employees.value.findIndex(item => item.id === id)
      if (index !== -1) {
        employees.value[index] = response.data.data
      }
      
      toast.success('Employee terminate successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to terminate employee'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const getEmployeeById = (id) => {
    return employees.value.find(item => item.id === id)
  }

  const searchEmployees = (query) => {
    const lowerQuery = query.toLowerCase()
    return employees.value.filter(item => 
      item.first_name?.toLowerCase().includes(lowerQuery) ||
      item.last_name?.toLowerCase().includes(lowerQuery) ||
      item.employee_number?.toLowerCase().includes(lowerQuery) ||
      item.email?.toLowerCase().includes(lowerQuery)
    )
  }

  // Event handlers for WebSocket - accept event and extract data internally
  const handleEmployeeCreated = (event) => {
    const employee = event.detail?.employee
    if (!employee) return
    const exists = employees.value.find(item => item.id === employee.id)
    if (!exists) {
      employees.value.unshift(employee)
    }
  }

  const handleEmployeeUpdated = (event) => {
    const employee = event.detail?.employee
    if (!employee) return
    const index = employees.value.findIndex(item => item.id === employee.id)
    if (index !== -1) {
      employees.value[index] = { ...employees.value[index], ...employee }
    }
  }

  const handleEmployeeDeleted = (event) => {
    const employeeId = event.detail?.employeeId
    if (!employeeId) return
    employees.value = employees.value.filter(item => item.id !== employeeId)
  }

  // Setup WebSocket event listeners - use named handlers
  const setupWebSocketListeners = () => {
    window.addEventListener('employee-created', handleEmployeeCreated)
    window.addEventListener('employee-updated', handleEmployeeUpdated)
    window.addEventListener('employee-deleted', handleEmployeeDeleted)
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('employee-created', handleEmployeeCreated)
    window.removeEventListener('employee-updated', handleEmployeeUpdated)
    window.removeEventListener('employee-deleted', handleEmployeeDeleted)
  }

  return {
    // Reactive data
    employees,
    stats,
    activeEmployees,
    on_leaveEmployees,
    suspendedEmployees,
    terminatedEmployees,
    loading,
    error,

    // API functions
    fetchEmployees,
    fetchStatistics,
    createEmployee,
    updateEmployee,
    deleteEmployee,
    terminateEmployee,

    // Utility functions
    getEmployeeById,
    searchEmployees,

    // Event handlers
    handleEmployeeCreated,
    handleEmployeeUpdated,
    handleEmployeeDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
