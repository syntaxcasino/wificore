import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'

export function useBranding() {
  const { error: showError } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const error = ref(null)
  const templates = ref([])
  const selectedTemplate = ref(null)
  const formSubmitting = ref(false)
  const formError = ref(null)
  const showCreateOverlay = ref(false)
  const showViewOverlay = ref(false)
  const showEditOverlay = ref(false)

  const activeTemplate = computed(() => templates.value.find(t => t.is_active))

  const defaultCreateForm = () => ({
    name: '', description: '',
    primary_color: '#3B82F6', secondary_color: '#10B981',
    logo: null, logo_preview: null,
    company_name: '', contact_phone: '', contact_email: '', website: '',
    is_active: false
  })

  const form = ref(defaultCreateForm())
  const editForm = ref({
    id: null, name: '', description: '',
    primary_color: '#3B82F6', secondary_color: '#10B981',
    logo: null, logo_preview: null, logo_url: null,
    company_name: '', contact_phone: '', contact_email: '', website: ''
  })

  const formatDate = (date) => {
    if (!date) return '-'
    return new Date(date).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' })
  }

  const fetchTemplates = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/branding/templates')
      templates.value = response.data?.templates || []
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load branding templates'
    } finally {
      loading.value = false
    }
  }

  const handleLogoUpload = (event, target = 'create') => {
    const file = event.target.files[0]
    if (!file) return
    if (target === 'create') {
      form.value.logo = file
      form.value.logo_preview = URL.createObjectURL(file)
    } else {
      editForm.value.logo = file
      editForm.value.logo_preview = URL.createObjectURL(file)
    }
  }

  const openCreateOverlay = () => {
    form.value = defaultCreateForm()
    formError.value = null
    showCreateOverlay.value = true
  }

  const openViewOverlay = (template) => {
    selectedTemplate.value = template
    showViewOverlay.value = true
  }

  const openEditOverlay = (template) => {
    showViewOverlay.value = false
    editForm.value = {
      id: template.id, name: template.name,
      description: template.description || '',
      primary_color: template.primary_color || '#3B82F6',
      secondary_color: template.secondary_color || '#10B981',
      logo: null, logo_preview: null, logo_url: template.logo_url,
      company_name: template.company_name || '',
      contact_phone: template.contact_phone || '',
      contact_email: template.contact_email || '',
      website: template.website || ''
    }
    formError.value = null
    showEditOverlay.value = true
  }

  const handleCreate = async () => {
    formSubmitting.value = true
    formError.value = null
    try {
      const formData = new FormData()
      Object.entries(form.value).forEach(([key, val]) => {
        if (val !== null && val !== undefined) formData.append(key, val)
      })
      await axios.post('/branding/templates', formData, { headers: { 'Content-Type': 'multipart/form-data' } })
      showCreateOverlay.value = false
      await fetchTemplates()
    } catch (err) {
      formError.value = err.response?.data?.message || 'Failed to create template'
    } finally {
      formSubmitting.value = false
    }
  }

  const handleUpdate = async () => {
    formSubmitting.value = true
    formError.value = null
    try {
      const formData = new FormData()
      Object.entries(editForm.value).forEach(([key, val]) => {
        if (key !== 'id' && key !== 'logo_url' && val !== null && val !== undefined)
          formData.append(key, val)
      })
      await axios.post(`/branding/templates/${editForm.value.id}?_method=PATCH`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' }
      })
      showEditOverlay.value = false
      await fetchTemplates()
    } catch (err) {
      formError.value = err.response?.data?.message || 'Failed to update template'
    } finally {
      formSubmitting.value = false
    }
  }

  const activateTemplate = async (template) => {
    try {
      await axios.patch(`/branding/templates/${template.id}/activate`)
      await fetchTemplates()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to activate template')
    }
  }

  const handleDelete = async (template) => {
    const confirmed = await confirmStore.open({
      title: 'Delete Template',
      message: `Delete template "${template.name}"? This cannot be undone.`,
      confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger'
    })
    if (!confirmed) return
    try {
      await axios.delete(`/branding/templates/${template.id}`)
      await fetchTemplates()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to delete template')
    }
  }

  return {
    loading, error, templates, selectedTemplate, formSubmitting, formError,
    showCreateOverlay, showViewOverlay, showEditOverlay,
    form, editForm, activeTemplate,
    formatDate, handleLogoUpload,
    fetchTemplates, openCreateOverlay, openViewOverlay, openEditOverlay,
    handleCreate, handleUpdate, activateTemplate, handleDelete
  }
}
