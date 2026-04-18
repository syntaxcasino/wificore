import { ref, computed } from 'vue'
import axios from 'axios'
import { useConfirmStore } from '@/stores/confirm'

export function usePackageGroups() {
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const error = ref(null)
  const groups = ref([])
  const editingGroup = ref(null)
  const saving = ref(false)
  const showModal = ref(false)

  const defaultFormData = () => ({
    name: '',
    description: '',
    color: 'blue',
    display_order: 0,
    is_active: true,
    is_featured: false
  })

  const formData = ref(defaultFormData())

  const colorOptions = [
    { value: 'blue', label: 'Blue', bg: 'bg-blue-100', text: 'text-blue-900' },
    { value: 'purple', label: 'Purple', bg: 'bg-purple-100', text: 'text-purple-900' },
    { value: 'green', label: 'Green', bg: 'bg-green-100', text: 'text-green-900' },
    { value: 'amber', label: 'Amber', bg: 'bg-amber-100', text: 'text-amber-900' },
    { value: 'red', label: 'Red', bg: 'bg-red-100', text: 'text-red-900' },
    { value: 'cyan', label: 'Cyan', bg: 'bg-cyan-100', text: 'text-cyan-900' },
    { value: 'pink', label: 'Pink', bg: 'bg-pink-100', text: 'text-pink-900' },
    { value: 'indigo', label: 'Indigo', bg: 'bg-indigo-100', text: 'text-indigo-900' }
  ]

  const totalGroups = computed(() => groups.value.length)
  const activeGroups = computed(() => groups.value.filter(g => g.status === 'active'))
  const featuredGroups = computed(() => groups.value.filter(g => g.is_featured))

  const getGroupGradient = (color) => ({
    blue: 'from-blue-500 to-indigo-600',
    purple: 'from-purple-500 to-indigo-600',
    green: 'from-green-500 to-emerald-600',
    amber: 'from-amber-500 to-yellow-600',
    red: 'from-red-500 to-rose-600',
    cyan: 'from-cyan-500 to-blue-600',
    pink: 'from-pink-500 to-rose-600',
    indigo: 'from-indigo-500 to-purple-600'
  }[color] || 'from-blue-500 to-indigo-600')

  const getIconColor = (color) => ({
    blue: 'text-blue-600', purple: 'text-purple-600', green: 'text-green-600',
    amber: 'text-amber-600', red: 'text-red-600', cyan: 'text-cyan-600',
    pink: 'text-pink-600', indigo: 'text-indigo-600'
  }[color] || 'text-blue-600')

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)

  const fetchGroups = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/packages/groups')
      groups.value = response.data?.groups || response.data?.data || []
    } catch (err) {
      error.value = 'Failed to load groups. Please try again.'
      console.error('Error fetching groups:', err)
    } finally {
      loading.value = false
    }
  }

  const openCreateModal = () => {
    editingGroup.value = null
    formData.value = defaultFormData()
    showModal.value = true
  }

  const editGroup = (group) => {
    editingGroup.value = group
    formData.value = { ...group }
    showModal.value = true
  }

  const handleSubmit = async () => {
    saving.value = true
    try {
      if (editingGroup.value) {
        await axios.patch(`/packages/groups/${editingGroup.value.id}`, formData.value)
        const idx = groups.value.findIndex(g => g.id === editingGroup.value.id)
        if (idx !== -1) groups.value[idx] = { ...groups.value[idx], ...formData.value }
      } else {
        const response = await axios.post('/packages/groups', formData.value)
        groups.value.push(response.data?.group || { id: Date.now(), ...formData.value, packages_count: 0, packages: [] })
      }
      showModal.value = false
    } catch (err) {
      console.error('Error saving group:', err)
    } finally {
      saving.value = false
    }
  }

  const toggleStatus = async (group) => {
    const action = group.status === 'active' ? 'deactivate' : 'activate'
    const confirmed = await confirmStore.open({
      title: 'Confirm Action',
      message: `Are you sure you want to ${action} ${group.name}?`,
      confirmText: 'OK', cancelText: 'Cancel',
      variant: group.status === 'active' ? 'warning' : 'success'
    })
    if (!confirmed) return
    try {
      await axios.patch(`/packages/groups/${group.id}`, { status: group.status === 'active' ? 'inactive' : 'active' })
      group.status = group.status === 'active' ? 'inactive' : 'active'
    } catch (err) {
      console.error(`Failed to ${action} group:`, err)
    }
  }

  const deleteGroup = async (group) => {
    const confirmed = await confirmStore.open({
      title: 'Delete Group',
      message: `Delete "${group.name}"? This cannot be undone.`,
      confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger'
    })
    if (!confirmed) return
    try {
      await axios.delete(`/packages/groups/${group.id}`)
      groups.value = groups.value.filter(g => g.id !== group.id)
    } catch (err) {
      console.error('Failed to delete group:', err)
    }
  }

  return {
    loading, error, groups, editingGroup, saving, showModal, formData, colorOptions,
    totalGroups, activeGroups, featuredGroups,
    getGroupGradient, getIconColor, formatMoney,
    fetchGroups, openCreateModal, editGroup, handleSubmit, toggleStatus, deleteGroup
  }
}
