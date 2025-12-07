/**
 * Department Management Composable - Event-Driven
 * WiFi Hotspot System - HR Module
 */

import { ref, computed } from 'vue'
import axios from '@/services/api/axios'
import { useToast } from '@/composables/useToast'

export function useDepartments() {
  const loading = ref(false)
  const error = ref(null)
  const departments = ref([])
  const stats = ref({
    total: 0,
    active: 0,
    pending_approval: 0,
    inactive: 0,
    total_budget: 0,
    avg_employees_per_dept: 0
  })
  
  const { toast } = useToast()

  // Computed filters
  const activeDepartments = computed(() => 
    departments.value.filter(dept => dept.status === 'active')
  )
  
  const pendingDepartments = computed(() => 
    departments.value.filter(dept => dept.status === 'pending_approval')
  )
  
  const inactiveDepartments = computed(() => 
    departments.value.filter(dept => dept.status === 'inactive')
  )

  // API Functions
  const fetchDepartments = async (filters = {}) => {
    loading.value = true
    error.value = null
    
    try {
      const params = new URLSearchParams(filters).toString()
      const url = params ? `/departments?${params}` : '/departments'
      const response = await axios.get(url)
      
      departments.value = response.data.data || response.data
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch departments'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchStatistics = async () => {
    try {
      const response = await axios.get('/departments/statistics')
      stats.value = response.data.data || response.data
      return response.data
    } catch (err) {
      console.error('Failed to fetch statistics:', err)
      return null
    }
  }

  const createDepartment = async (departmentData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post('/departments', departmentData)
      
      departments.value.unshift(response.data.data)
      toast.success('Department created successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create department'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateDepartment = async (id, departmentData) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.put(`/departments/${id}`, departmentData)
      
      const index = departments.value.findIndex(d => d.id === id)
      if (index !== -1) {
        departments.value[index] = response.data.data
      }
      
      toast.success('Department updated successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update department'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteDepartment = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      await axios.delete(`/departments/${id}`)
      
      departments.value = departments.value.filter(d => d.id !== id)
      toast.success('Department deleted successfully')
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete department'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const approveDepartment = async (id) => {
    loading.value = true
    error.value = null
    
    try {
      const response = await axios.post(`/departments/${id}/approve`)
      
      const index = departments.value.findIndex(d => d.id === id)
      if (index !== -1) {
        departments.value[index] = response.data.data
      }
      
      toast.success('Department approved successfully')
      return response.data.data
      
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to approve department'
      toast.error(error.value)
      throw err
    } finally {
      loading.value = false
    }
  }

  const getDepartmentById = (id) => {
    return departments.value.find(d => d.id === id)
  }

  const searchDepartments = (query) => {
    const lowerQuery = query.toLowerCase()
    return departments.value.filter(d => 
      d.name?.toLowerCase().includes(lowerQuery) ||
      d.code?.toLowerCase().includes(lowerQuery) ||
      d.location?.toLowerCase().includes(lowerQuery)
    )
  }

  // Event handlers for WebSocket
  const handleDepartmentCreated = (department) => {
    const exists = departments.value.find(d => d.id === department.id)
    if (!exists) {
      departments.value.unshift(department)
    }
  }

  const handleDepartmentUpdated = (department) => {
    const index = departments.value.findIndex(d => d.id === department.id)
    if (index !== -1) {
      departments.value[index] = { ...departments.value[index], ...department }
    }
  }

  const handleDepartmentDeleted = (departmentId) => {
    departments.value = departments.value.filter(d => d.id !== departmentId)
  }

  // Setup WebSocket event listeners
  const setupWebSocketListeners = () => {
    window.addEventListener('department-created', (event) => {
      if (event.detail?.department) {
        handleDepartmentCreated(event.detail.department)
      }
    })

    window.addEventListener('department-updated', (event) => {
      if (event.detail?.department) {
        handleDepartmentUpdated(event.detail.department)
      }
    })

    window.addEventListener('department-deleted', (event) => {
      if (event.detail?.departmentId) {
        handleDepartmentDeleted(event.detail.departmentId)
      }
    })
  }

  // Cleanup WebSocket listeners
  const cleanupWebSocketListeners = () => {
    window.removeEventListener('department-created', handleDepartmentCreated)
    window.removeEventListener('department-updated', handleDepartmentUpdated)
    window.removeEventListener('department-deleted', handleDepartmentDeleted)
  }

  return {
    // Reactive data
    departments,
    stats,
    activeDepartments,
    pendingDepartments,
    inactiveDepartments,
    loading,
    error,

    // API functions
    fetchDepartments,
    fetchStatistics,
    createDepartment,
    updateDepartment,
    deleteDepartment,
    approveDepartment,

    // Utility functions
    getDepartmentById,
    searchDepartments,

    // Event handlers
    handleDepartmentCreated,
    handleDepartmentUpdated,
    handleDepartmentDeleted,

    // WebSocket setup
    setupWebSocketListeners,
    cleanupWebSocketListeners
  }
}
