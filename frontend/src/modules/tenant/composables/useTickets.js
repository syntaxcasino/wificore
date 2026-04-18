import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'
import { useConfirmStore } from '@/stores/confirm'

export function useTickets() {
  const { error: showError } = useToast()
  const confirmStore = useConfirmStore()

  const loading = ref(false)
  const tickets = ref([])
  const selectedTicket = ref(null)
  const submitting = ref(false)
  const showViewOverlay = ref(false)
  const showCreateOverlay = ref(false)

  const defaultForm = () => ({
    subject: '',
    customer_name: '',
    category: '',
    priority: 'medium',
    description: ''
  })

  const form = ref(defaultForm())

  const stats = computed(() => ({
    total: tickets.value.length,
    open: tickets.value.filter(t => t.status === 'open').length,
    inProgress: tickets.value.filter(t => t.status === 'in_progress').length,
    resolved: tickets.value.filter(t => t.status === 'resolved').length
  }))

  const formatDate = (date) => new Date(date).toLocaleDateString()
  const formatDateTime = (date) => new Date(date).toLocaleString()

  const getPriorityVariant = (priority) =>
    ({ low: 'secondary', medium: 'info', high: 'warning', urgent: 'danger' }[priority] || 'default')

  const getPriorityBadgeClass = (priority) =>
    ({
      low: 'bg-slate-100 text-slate-700',
      medium: 'bg-blue-100 text-blue-700',
      high: 'bg-amber-100 text-amber-700',
      urgent: 'bg-red-100 text-red-700'
    }[priority] || 'bg-slate-100 text-slate-700')

  const getTicketActions = (ticket, handlers) => [
    { label: 'View', onClick: () => handlers.view(ticket) },
    ...(ticket.status !== 'closed'
      ? [{ label: 'Close', onClick: () => handlers.close(ticket), class: 'text-green-700 bg-green-50 hover:bg-green-100' }]
      : [])
  ]

  const fetchTickets = async () => {
    loading.value = true
    try {
      const response = await axios.get('/support/tickets')
      const data = response.data?.tickets?.data || response.data?.tickets || response.data?.data || []
      tickets.value = data.map(t => ({
        id: t.id,
        subject: t.subject || t.title || '',
        description: t.description || t.body || '',
        customer_name: t.customer_name || t.user?.name || 'Unknown',
        category: t.category || 'General',
        status: t.status || 'open',
        priority: t.priority || 'medium',
        assigned_to: t.assigned_to || t.assignee?.name || null,
        created_at: t.created_at || new Date().toISOString()
      }))
    } catch (err) {
      console.error('fetchTickets error:', err)
    } finally {
      loading.value = false
    }
  }

  const viewTicket = (ticket) => {
    selectedTicket.value = ticket
    showViewOverlay.value = true
  }

  const closeViewOverlay = () => {
    showViewOverlay.value = false
    selectedTicket.value = null
  }

  const openCreateOverlay = () => {
    form.value = defaultForm()
    showCreateOverlay.value = true
  }

  const closeCreateOverlay = () => {
    showCreateOverlay.value = false
  }

  const submitTicket = async () => {
    if (!form.value.subject || !form.value.description) {
      showError('Subject and description are required.')
      return
    }
    submitting.value = true
    try {
      await axios.post('/support/tickets', form.value)
      closeCreateOverlay()
      await fetchTickets()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to create ticket')
    } finally {
      submitting.value = false
    }
  }

  const closeTicket = async (ticket) => {
    if (!ticket) return
    const confirmed = await confirmStore.open({
      title: 'Close Ticket',
      message: `Close ticket #${ticket.id}?`,
      confirmText: 'Close Ticket', cancelText: 'Cancel', variant: 'warning'
    })
    if (!confirmed) return
    try {
      await axios.patch(`/support/tickets/${ticket.id}`, { status: 'closed' })
      const idx = tickets.value.findIndex(t => t.id === ticket.id)
      if (idx !== -1) tickets.value[idx].status = 'closed'
      if (selectedTicket.value?.id === ticket.id) selectedTicket.value.status = 'closed'
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to close ticket')
    }
  }

  return {
    loading, tickets, selectedTicket, submitting,
    showViewOverlay, showCreateOverlay, form,
    stats,
    formatDate, formatDateTime,
    getPriorityVariant, getPriorityBadgeClass, getTicketActions,
    fetchTickets, viewTicket, closeViewOverlay,
    openCreateOverlay, closeCreateOverlay, submitTicket, closeTicket
  }
}
