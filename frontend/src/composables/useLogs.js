// composables/useLogs.js
import { ref } from 'vue'
import axios from 'axios'

export default function useLogs() {
  const logs = ref([])
  const currentPage = ref(1)
  const lastPage = ref(1)
  const prevPageUrl = ref(null)
  const nextPageUrl = ref(null)
  const error = ref(null)
  const isLoading = ref(false)

  const fetchLogs = async (url = '/api/logs') => {
    try {
      isLoading.value = true
      error.value = null
      const response = await axios.get(url)
      const { data, current_page, last_page, prev_page_url, next_page_url } = response.data

      logs.value = data || []
      currentPage.value = current_page || 1
      lastPage.value = last_page || 1
      prevPageUrl.value = prev_page_url
      nextPageUrl.value = next_page_url
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to load logs.'
      logs.value = []
      currentPage.value = 1
      lastPage.value = 1
      prevPageUrl.value = null
      nextPageUrl.value = null
    } finally {
      isLoading.value = false
    }
  }

  const formatMessage = (message) => {
    try {
      const parsed = JSON.parse(message)
      return JSON.stringify(parsed, null, 2)
    } catch {
      return message
    }
  }

  const formatTimestamp = (timestamp) => {
    return new Date(timestamp).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    })
  }

  const getActionClasses = (action) => {
    const actionMap = {
      create: 'bg-green-100 text-green-800',
      update: 'bg-yellow-100 text-yellow-800',
      delete: 'bg-red-100 text-red-800',
      error: 'bg-red-100 text-red-800',
      login: 'bg-blue-100 text-blue-800',
      logout: 'bg-blue-100 text-blue-800',
      default: 'bg-gray-100 text-gray-800',
    }

    const lowerAction = action.toLowerCase()
    return actionMap[lowerAction] || actionMap['default']
  }

  return {
    logs,
    currentPage,
    lastPage,
    prevPageUrl,
    nextPageUrl,
    error,
    isLoading,
    fetchLogs,
    formatMessage,
    formatTimestamp,
    getActionClasses,
  }
}
