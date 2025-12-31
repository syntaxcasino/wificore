import { ref, reactive, watch } from 'vue'
import axios from 'axios'

export function useAccessPoints() {
  const accessPoints = ref([])
  const loading = ref(false)
  const error = ref(null)
  const showFormOverlay = ref(false)
  const isEditing = ref(false)
  const selectedAp = ref(null)
  const formSubmitting = ref(false)
  const formMessage = ref({ text: '', type: '' })
  const availableRouters = ref([])

  const formData = ref({
    id: null,
    router_id: '',
    name: '',
    vendor: 'other',
    model: '',
    ip_address: '',
    mac_address: '',
    serial_number: '',
    location: '',
    management_protocol: 'snmp',
    total_capacity: 0
  })

  // Vendors list
  const vendors = [
    { value: 'mikrotik', label: 'MikroTik' },
    { value: 'ubiquiti', label: 'Ubiquiti' },
    { value: 'cambium', label: 'Cambium' },
    { value: 'tp-link', label: 'TP-Link' },
    { value: 'cisco', label: 'Cisco' },
    { value: 'ruijie', label: 'Ruijie' },
    { value: 'tenda', label: 'Tenda' },
    { value: 'huawei', label: 'Huawei' },
    { value: 'other', label: 'Other' }
  ]

  const fetchAccessPoints = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/access-points')
      accessPoints.value = response.data.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch access points'
      console.error('Error fetching access points:', err)
    } finally {
      loading.value = false
    }
  }

  const fetchRouters = async () => {
    try {
      const response = await axios.get('/routers')
      availableRouters.value = response.data.data || response.data
    } catch (err) {
      console.error('Error fetching routers:', err)
    }
  }

  const openCreateOverlay = () => {
    isEditing.value = false
    selectedAp.value = null
    formData.value = {
      id: null,
      router_id: '',
      name: '',
      vendor: 'other',
      model: '',
      ip_address: '',
      mac_address: '',
      serial_number: '',
      location: '',
      management_protocol: 'snmp',
      total_capacity: 0
    }
    formMessage.value = { text: '', type: '' }
    showFormOverlay.value = true
    if (availableRouters.value.length === 0) {
      fetchRouters()
    }
  }

  const openEditOverlay = (ap) => {
    isEditing.value = true
    selectedAp.value = ap
    formData.value = {
      id: ap.id,
      router_id: ap.router_id,
      name: ap.name,
      vendor: ap.vendor,
      model: ap.model,
      ip_address: ap.ip_address,
      mac_address: ap.mac_address,
      serial_number: ap.serial_number,
      location: ap.location,
      management_protocol: ap.management_protocol,
      total_capacity: ap.total_capacity
    }
    formMessage.value = { text: '', type: '' }
    showFormOverlay.value = true
    if (availableRouters.value.length === 0) {
      fetchRouters()
    }
  }

  const closeFormOverlay = () => {
    showFormOverlay.value = false
    selectedAp.value = null
  }

  const submitForm = async () => {
    formSubmitting.value = true
    formMessage.value = { text: '', type: '' }
    
    try {
      if (isEditing.value) {
        await axios.put(`/access-points/${formData.value.id}`, formData.value)
        formMessage.value = { text: 'Access point updated successfully', type: 'success' }
      } else {
        // Need to post to /routers/{router}/access-points
        if (!formData.value.router_id) {
            throw new Error('Please select a router');
        }
        await axios.post(`/routers/${formData.value.router_id}/access-points`, formData.value)
        formMessage.value = { text: 'Access point added successfully', type: 'success' }
      }
      
      // Close overlay after short delay
      setTimeout(() => {
        closeFormOverlay()
        fetchAccessPoints()
      }, 1500)
      
    } catch (err) {
      formMessage.value = { 
        text: err.response?.data?.message || err.message || 'Operation failed', 
        type: 'error' 
      }
      console.error('Form submission error:', err)
    } finally {
      formSubmitting.value = false
    }
  }

  const deleteAccessPoint = async (ap) => {
    if (!confirm('Are you sure you want to delete this access point?')) return

    try {
      await axios.delete(`/access-points/${ap.id}`)
      fetchAccessPoints()
    } catch (err) {
      console.error('Error deleting access point:', err)
      alert('Failed to delete access point')
    }
  }

  const syncStatus = async (ap) => {
    try {
      await axios.post(`/access-points/${ap.id}/sync`)
      fetchAccessPoints()
    } catch (err) {
      console.error('Error syncing status:', err)
    }
  }

  return {
    accessPoints,
    loading,
    error,
    showFormOverlay,
    isEditing,
    formData,
    availableRouters,
    vendors,
    formSubmitting,
    formMessage,
    fetchAccessPoints,
    openCreateOverlay,
    openEditOverlay,
    closeFormOverlay,
    submitForm,
    deleteAccessPoint,
    syncStatus
  }
}
