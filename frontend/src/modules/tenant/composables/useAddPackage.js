import { ref } from 'vue'
import axios from 'axios'
import { useNotificationStore } from '@/stores/notifications'

const defaultForm = () => ({
  name: '', description: '',
  type: 'hotspot',
  price: null, validity: '1 hour',
  download_speed: null, upload_speed: null,
  data_limit_value: null, data_limit_unit: 'GB',
  unlimited_data: false,
  burst_download: null, burst_upload: null,
  is_active: true, is_featured: false, display_order: 0
})

export function useAddPackage() {
  const notify = useNotificationStore()

  const saving = ref(false)
  const errorMessage = ref('')
  const formData = ref(defaultForm())

  const getDataLimitDisplay = () => {
    if (formData.value.unlimited_data) return 'Unlimited'
    if (formData.value.data_limit_value) return `${formData.value.data_limit_value} ${formData.value.data_limit_unit}`
    return 'Not set'
  }

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)

  const buildPayload = () => {
    const f = formData.value
    const dataLimit = f.unlimited_data ? 'Unlimited'
      : (f.data_limit_value ? `${f.data_limit_value} ${f.data_limit_unit}` : null)
    const speed = (f.download_speed || f.upload_speed)
      ? `${f.download_speed || 0}/${f.upload_speed || 0} Mbps` : null
    return {
      name: f.name, description: f.description, type: f.type,
      price: f.price, duration: f.validity, validity: f.validity,
      download_speed: f.download_speed ? `${f.download_speed}M` : '0M',
      upload_speed: f.upload_speed ? `${f.upload_speed}M` : '0M',
      speed, data_limit: dataLimit, devices: 1,
      status: f.is_active ? 'active' : 'inactive',
      is_active: f.is_active,
    }
  }

  const resetForm = () => {
    formData.value = defaultForm()
    errorMessage.value = ''
  }

  const submitPackage = async () => {
    saving.value = true
    errorMessage.value = ''
    try {
      await axios.post('/packages', buildPayload())
      notify.success('Package Created', 'Package created successfully')
      return { success: true }
    } catch (err) {
      const errors = err.response?.data?.errors
      let msg
      if (errors && typeof errors === 'object') {
        const fields = Object.keys(errors).map(k => k.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase()))
        msg = `Please fill in: ${fields.join(', ')}`
      } else {
        msg = err.response?.data?.error || err.response?.data?.message || 'Failed to create package'
      }
      errorMessage.value = msg
      notify.error('Package Creation Failed', msg)
      return { success: false }
    } finally {
      saving.value = false
    }
  }

  return {
    saving, errorMessage, formData,
    getDataLimitDisplay, formatMoney, buildPayload, resetForm, submitPackage
  }
}
