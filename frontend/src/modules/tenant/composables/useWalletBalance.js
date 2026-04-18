import { ref, computed } from 'vue'
import axios from 'axios'
import { useToast } from '@/modules/common/composables/useToast.js'

export function useWalletBalance() {
  const { error: showError } = useToast()

  const loading = ref(false)
  const refreshing = ref(false)
  const submitting = ref(false)
  const wallets = ref([])
  const walletHistory = ref([])
  const selectedWallet = ref(null)
  const balanceTarget = ref(null)
  const balanceAmount = ref(0)
  const balanceDescription = ref('')
  const balanceAction = ref('credit')
  const showHistoryOverlay = ref(false)
  const showAddBalanceOverlay = ref(false)

  const stats = computed(() => {
    const total = wallets.value.reduce((sum, w) => sum + (w.balance || 0), 0)
    return {
      totalBalance: total,
      activeWallets: wallets.value.filter(w => w.balance > 0).length,
      todayTopups: wallets.value.reduce((sum, w) => sum + (w.today_topups || 0), 0),
      avgBalance: wallets.value.length ? Math.floor(total / wallets.value.length) : 0
    }
  })

  const formatMoney = (amount) => new Intl.NumberFormat('en-KE').format(amount)
  const formatDateTime = (date) => new Date(date).toLocaleDateString()

  const getBalanceColor = (balance) => {
    if (balance === 0) return 'text-red-600'
    if (balance < 1000) return 'text-amber-600'
    return 'text-green-600'
  }

  const getStatusVariant = (balance) => {
    if (balance === 0) return 'danger'
    if (balance < 1000) return 'warning'
    return 'success'
  }

  const getStatusLabel = (balance) => {
    if (balance === 0) return 'Zero'
    if (balance < 1000) return 'Low'
    return 'Active'
  }

  const fetchWallets = async () => {
    const isInitial = wallets.value.length === 0
    if (isInitial) loading.value = true
    try {
      const response = await axios.get('/billing/wallets')
      const data = response.data?.wallets || response.data?.data || []
      wallets.value = data.map(w => ({
        id: w.id,
        username: w.username || w.user?.name || `User ${w.user_id || w.id}`,
        email: w.email || w.user?.email || '',
        balance: Number(w.balance || 0),
        last_topup: Number(w.last_topup_amount || w.last_topup || 0),
        last_topup_date: w.last_topup_date || w.last_topup_at || '',
        total_topups: Number(w.total_topups || w.total_credits || 0),
        today_topups: Number(w.today_topups || 0),
        user_id: w.user_id || w.id
      }))
    } catch (err) {
      console.error('fetchWallets error:', err)
    } finally {
      loading.value = false
    }
  }

  const refreshData = async () => {
    refreshing.value = true
    await fetchWallets()
    refreshing.value = false
  }

  const viewHistory = async (wallet) => {
    selectedWallet.value = wallet
    walletHistory.value = []
    showHistoryOverlay.value = true
    try {
      const response = await axios.get(`/billing/wallets/${wallet.id}/history`)
      walletHistory.value = response.data?.transactions || response.data?.data || []
    } catch (err) {
      console.error('fetchHistory error:', err)
    }
  }

  const openAddBalanceModal = () => {
    balanceTarget.value = null
    balanceAmount.value = 0
    balanceDescription.value = ''
    showAddBalanceOverlay.value = true
  }

  const addBalance = (wallet) => {
    balanceTarget.value = wallet
    balanceAction.value = 'credit'
    balanceAmount.value = 0
    balanceDescription.value = ''
    showAddBalanceOverlay.value = true
  }

  const deductBalance = (wallet) => {
    balanceTarget.value = wallet
    balanceAction.value = 'debit'
    balanceAmount.value = 0
    balanceDescription.value = ''
    showAddBalanceOverlay.value = true
  }

  const submitBalance = async (type) => {
    if (!balanceTarget.value || !balanceAmount.value) {
      showError('Please select a user and enter an amount.')
      return
    }
    submitting.value = true
    try {
      await axios.post(`/billing/wallets/${balanceTarget.value.id}/adjust`, {
        type: type || balanceAction.value,
        amount: balanceAmount.value,
        description: balanceDescription.value
      })
      showAddBalanceOverlay.value = false
      await fetchWallets()
    } catch (err) {
      showError(err.response?.data?.message || 'Failed to adjust balance')
    } finally {
      submitting.value = false
    }
  }

  return {
    loading, refreshing, submitting,
    wallets, walletHistory, selectedWallet,
    balanceTarget, balanceAmount, balanceDescription, balanceAction,
    showHistoryOverlay, showAddBalanceOverlay,
    stats,
    formatMoney, formatDateTime,
    getBalanceColor, getStatusVariant, getStatusLabel,
    fetchWallets, refreshData, viewHistory,
    openAddBalanceModal, addBalance, deductBalance, submitBalance
  }
}
