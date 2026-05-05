import { ref, computed } from 'vue'
import axios from '@/modules/common/services/api/axios'

export function useCommunicationChannels() {
  const channels = ref([])
  const providers = ref({ types: [], providers: {} })
  const loading = ref(false)
  const error = ref(null)

  const smsChannels = computed(() => channels.value.filter(c => c.type === 'sms'))
  const whatsappChannels = computed(() => channels.value.filter(c => c.type === 'whatsapp'))
  const emailChannels = computed(() => channels.value.filter(c => c.type === 'email'))
  const activeChannels = computed(() => channels.value.filter(c => c.is_active))
  const totalChannels = computed(() => channels.value.length)

  const fetchChannels = async () => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.get('/communication-channels')
      const payload = response.data?.data ?? response.data
      channels.value = Array.isArray(payload) ? payload : []
      return channels.value
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to fetch communication channels'
      throw err
    } finally {
      loading.value = false
    }
  }

  const fetchProviders = async () => {
    try {
      const response = await axios.get('/communication-channels/providers')
      providers.value = response.data?.data ?? { types: [], providers: {} }
      return providers.value
    } catch (err) {
      console.error('Failed to fetch providers:', err)
      return { types: [], providers: {} }
    }
  }

  const createChannel = async (channelData) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.post('/communication-channels', channelData)
      const newChannel = response.data?.data

      // Optimistically add to the local list immediately for better UX
      if (newChannel) {
        channels.value.unshift(newChannel)
      }

      return newChannel
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to create channel'
      throw err
    } finally {
      loading.value = false
    }
  }

  const updateChannel = async (channelId, channelData) => {
    loading.value = true
    error.value = null
    try {
      const response = await axios.put(`/communication-channels/${channelId}`, channelData)
      const updatedChannel = response.data?.data

      // Optimistically update the local list immediately for better UX
      if (updatedChannel) {
        const index = channels.value.findIndex(c => c.id === channelId)
        if (index !== -1) {
          channels.value.splice(index, 1, { ...channels.value[index], ...updatedChannel })
        }
      }

      return updatedChannel
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to update channel'
      throw err
    } finally {
      loading.value = false
    }
  }

  const deleteChannel = async (channelId) => {
    loading.value = true
    error.value = null
    try {
      await axios.delete(`/communication-channels/${channelId}`)
      // Optimistically remove from the local list immediately for better UX
      channels.value = channels.value.filter(c => c.id !== channelId)
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to delete channel'
      throw err
    } finally {
      loading.value = false
    }
  }

  const sendTestMessage = async (channelId, recipient) => {
    error.value = null
    try {
      const response = await axios.post(`/communication-channels/${channelId}/test`, { recipient })
      // Result delivered via WebSocket TestMessageSent event (async job)
      return response.data
    } catch (err) {
      error.value = err.response?.data?.message || 'Failed to send test message'
      throw err
    }
  }

  return {
    channels,
    providers,
    loading,
    error,
    smsChannels,
    whatsappChannels,
    emailChannels,
    activeChannels,
    totalChannels,
    fetchChannels,
    fetchProviders,
    createChannel,
    updateChannel,
    deleteChannel,
    sendTestMessage,
  }
}
