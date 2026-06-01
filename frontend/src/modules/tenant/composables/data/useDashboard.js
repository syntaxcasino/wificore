import { ref, computed } from 'vue'
import axios from 'axios'

// Memoized formatters — created once, reused on every call
const _currencyFmt = new Intl.NumberFormat('en-KE', {
  style: 'currency',
  currency: 'KES',
  minimumFractionDigits: 0,
  maximumFractionDigits: 0,
})

const DASHBOARD_CACHE_KEY = 'tenant-dashboard-snapshot:v1'
const DASHBOARD_CACHE_TTL_MS = 5 * 60 * 1000

/**
 * Dashboard composable for managing dashboard statistics and updates
 */
export function useDashboard() {
  const stats = ref({
    totalRouters: 0,
    activeSessions: 0,
    hotspotUsers: 0,
    pppoeUsers: 0,
    totalUsers: 0,
    totalRevenue: 0,
    dailyIncome: 0,
    weeklyIncome: 0,
    monthlyIncome: 0,
    yearlyIncome: 0,
    monthlyRevenue: 0,
    dataUsage: 0,
    dataUsageUpload: 0,
    dataUsageDownload: 0,
    monthlyDataUsage: 0,
    todayDataUsage: 0,
    retentionRate: 0,
    smsBalance: 0,
    onlineRouters: 0,
    offlineRouters: 0,
    provisioningRouters: 0,
    lastMonthUsers: 0,
    retainedUsers: 0,
  })

  const paymentData = ref({
    daily: { amount: 0, date: '', count: 0 },
    weekly: { amount: 0, startDate: '', endDate: '', count: 0, dailyBreakdown: [] },
    monthly: { amount: 0, month: '', year: '', count: 0, weeklyBreakdown: [] },
    yearly: { amount: 0, year: '', count: 0, monthlyBreakdown: [] }
  })

  const expensesData = ref({
    sms: {
      totalPurchased: 0,
      used: 0,
      remaining: 0,
      dailyUsage: 0,
      weeklyUsage: 0,
      monthlyUsage: 0,
      usageTrend: [],
      recentPurchases: []
    },
    costs: {
      totalSpent: 0,
      thisMonth: 0,
      lastMonth: 0,
      averageCostPerSMS: 0
    }
  })

  const analyticsData = ref({
    retention: { rate: 0, lastMonthUsers: 0, retainedUsers: 0 },
    accessPoints: [],
    revenueTrend: [],
    revenueAverage: 0,
    revenuePeak: 0,
    revenueGrowth: 0,
    userTrend: [],
    userAverage: 0,
    userPeak: 0,
    userGrowth: 0,
    businessKpis: {
      mrr: 0,
      arr: 0,
      arpu: 0,
      churn_rate: 0,
      failed_payment_rate: 0,
      daily_revenue: 0,
      monthly_completed_count: 0,
      revenue_by_area: [],
      active_subscribers: 0,
    },
  })

  const revenueAssurance = ref({
    score: 100,
    status: 'healthy',
    summary: '',
    findings: [],
    signals: {},
    generatedAt: null,
    kpis: {
      mrr: 0,
      arr: 0,
      arpu: 0,
      churn_rate: 0,
      failed_payment_rate: 0,
      daily_revenue: 0,
      monthly_completed_count: 0,
      revenue_by_area: [],
      active_subscribers: 0,
    },
  })

  const healthScore = ref({
    score: 100,
    grade: 'healthy',
    summary: '',
    factors: [],
    signals: {},
    history: [],
    calculatedAt: null,
    cached: false,
    refreshing: false,
  })

  const chartData = ref({ labels: [], users: [], revenue: [] })
  const recentActivities = ref([])
  const onlineUsers = ref([])
  const loading = ref(false)
  const hasCachedSnapshot = ref(false)
  const lastUpdated = ref(null)

  /**
   * Apply a full backend data payload to local reactive state.
   * Used by both fetchDashboardStats and updateStatsFromEvent.
   */
  const _applyData = (data) => {
    stats.value = {
      totalRouters:        data.total_routers || 0,
      activeSessions:      data.active_sessions || 0,
      hotspotUsers:        data.hotspot_users || 0,
      pppoeUsers:          data.pppoe_users || 0,
      totalUsers:          data.total_users || 0,
      totalRevenue:        data.total_revenue || 0,
      dailyIncome:         data.daily_income || 0,
      weeklyIncome:        data.weekly_income || 0,
      monthlyIncome:       data.monthly_income || 0,
      yearlyIncome:        data.yearly_income || 0,
      monthlyRevenue:      data.monthly_revenue || 0,
      dataUsage:           data.data_usage || 0,
      dataUsageUpload:     data.data_usage_upload || 0,
      dataUsageDownload:   data.data_usage_download || 0,
      monthlyDataUsage:    data.monthly_data_usage || 0,
      todayDataUsage:      data.today_data_usage || 0,
      retentionRate:       data.retention_rate || 0,
      smsBalance:          data.sms_balance || 0,
      onlineRouters:       data.online_routers || 0,
      offlineRouters:      data.offline_routers || 0,
      provisioningRouters: data.provisioning_routers || 0,
      lastMonthUsers:      data.last_month_users || 0,
      retainedUsers:       data.retained_users || 0,
    }

    if (data.payment_details)   paymentData.value   = data.payment_details
    if (data.sms_expenses)      expensesData.value  = data.sms_expenses
    if (data.business_analytics) {
      analyticsData.value = {
        ...analyticsData.value,
        ...data.business_analytics,
        businessKpis: data.business_analytics.businessKpis && typeof data.business_analytics.businessKpis === 'object'
          ? data.business_analytics.businessKpis
          : analyticsData.value.businessKpis,
      }
    }
    if (data.revenue_assurance) {
      revenueAssurance.value = {
        score: Number(data.revenue_assurance.score ?? 100),
        status: data.revenue_assurance.status || 'healthy',
        summary: data.revenue_assurance.summary || '',
        findings: Array.isArray(data.revenue_assurance.findings) ? data.revenue_assurance.findings : [],
        signals: data.revenue_assurance.signals && typeof data.revenue_assurance.signals === 'object' ? data.revenue_assurance.signals : {},
        generatedAt: data.revenue_assurance.generated_at || null,
        kpis: data.revenue_assurance.kpis && typeof data.revenue_assurance.kpis === 'object' ? data.revenue_assurance.kpis : revenueAssurance.value.kpis,
      }
    }

    if (data.business_kpis && typeof data.business_kpis === 'object') {
      revenueAssurance.value = {
        ...revenueAssurance.value,
        kpis: {
          ...revenueAssurance.value.kpis,
          ...data.business_kpis,
        },
      }
      analyticsData.value = {
        ...analyticsData.value,
        businessKpis: {
          ...analyticsData.value.businessKpis,
          ...data.business_kpis,
        },
      }
    }

    if (data.weekly_users_trend?.length) {
      chartData.value.labels = data.weekly_users_trend.map((i) => i.date)
      const maxCount = Math.max(...data.weekly_users_trend.map((i) => i.count), 1)
      chartData.value.users = data.weekly_users_trend.map((i) => ({
        value: i.count,
        percentage: (i.count / maxCount) * 100,
      }))
    }

    if (data.weekly_revenue_trend?.length) {
      const maxRevenue = Math.max(...data.weekly_revenue_trend.map((i) => i.amount), 1)
      chartData.value.revenue = data.weekly_revenue_trend.map((i) => ({
        value: i.amount,
        percentage: (i.amount / maxRevenue) * 100,
      }))
    }

    if (data.recent_activities) recentActivities.value = data.recent_activities
    if (data.online_users)      onlineUsers.value      = data.online_users

    lastUpdated.value = data.last_updated
    persistDashboardSnapshot(data)
  }

  const persistDashboardSnapshot = (data) => {
    if (typeof window === 'undefined') return

    try {
      localStorage.setItem(DASHBOARD_CACHE_KEY, JSON.stringify({
        savedAt: Date.now(),
        data,
      }))
      hasCachedSnapshot.value = true
    } catch (error) {
      // Best-effort cache only.
    }
  }

  const hydrateDashboardSnapshot = () => {
    if (typeof window === 'undefined') return false

    try {
      const raw = localStorage.getItem(DASHBOARD_CACHE_KEY)
      if (!raw) return false

      const parsed = JSON.parse(raw)
      const savedAt = Number(parsed?.savedAt || 0)
      if (!parsed?.data || (savedAt && Date.now() - savedAt > DASHBOARD_CACHE_TTL_MS)) {
        return false
      }

      _applyData(parsed.data)
      hasCachedSnapshot.value = true
      loading.value = false
      return true
    } catch (error) {
      return false
    }
  }

  hydrateDashboardSnapshot()

  /**
   * Fetch dashboard statistics from backend.
   * Only shows loading skeleton on first load (when stats are still zeroed out).
   */
  const fetchDashboardStats = async () => {
    const isInitial = !hasCachedSnapshot.value && stats.value.totalRevenue === 0 && stats.value.totalUsers === 0
    try {
      if (isInitial) loading.value = true
      const response = await axios.get('/tenant/dashboard/stats')
      if (response.data?.success) {
        _applyData(response.data?.data || {})
      }
    } catch (error) {
      console.error('Failed to fetch dashboard stats:', error)
    } finally {
      loading.value = false
    }
  }

  const applyHealthScore = (data) => {
    healthScore.value = {
      score: Number(data?.score ?? 100),
      grade: data?.grade || 'healthy',
      summary: data?.summary || '',
      factors: Array.isArray(data?.factors) ? data.factors : [],
      signals: data?.signals && typeof data.signals === 'object' ? data.signals : {},
      history: Array.isArray(data?.history) ? data.history : [],
      calculatedAt: data?.calculated_at || null,
      sourceEvent: data?.source_event || null,
      sourceReference: data?.source_reference || null,
      cached: !!data?.cached,
      refreshing: !!data?.refreshing,
    }
  }

  const fetchHealthScore = async () => {
    try {
      const response = await axios.get('/tenant/dashboard/health-score')
      if (response.data?.success) {
        applyHealthScore(response.data?.data || {})
      }
    } catch (error) {
      console.error('Failed to fetch health score:', error)
    }
  }

  /**
   * Force-refresh dashboard statistics (triggers cache bust + background job).
   */
  const refreshStats = async () => {
    try {
      await axios.post('/tenant/dashboard/refresh')
      setTimeout(() => {
        fetchDashboardStats()
        fetchHealthScore()
      }, 800)
    } catch (error) {
      console.error('Failed to refresh dashboard stats:', error)
    }
  }

  /**
   * Update stats from SSE/WebSocket event — applies data directly, no API round-trip.
   */
  const updateStatsFromEvent = (data) => {
    _applyData(data)
  }

  /**
   * Format currency value — uses memoized Intl.NumberFormat instance.
   */
  const formatCurrency = (value) => _currencyFmt.format(value || 0)

  /**
   * Format data size
   */
  const formatDataSize = (gb) => {
    if (gb >= 1000) return `${(gb / 1000).toFixed(2)} TB`
    return `${(gb || 0).toFixed(2)} GB`
  }

  /**
   * Format time ago
   */
  const formatTimeAgo = (timestamp) => {
    if (!timestamp) return 'Never'
    const seconds = Math.floor((Date.now() - new Date(timestamp)) / 1000)
    if (seconds < 10) return 'just now'
    if (seconds < 60) return `${seconds}s ago`
    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes}m ago`
    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h ago`
    return `${Math.floor(hours / 24)}d ago`
  }

  const routerHealthPercentage = computed(() => {
    const total = stats.value.totalRouters
    if (total === 0) return 0
    return Math.round((stats.value.onlineRouters / total) * 100)
  })

  const routerHealthStatus = computed(() => {
    const health = routerHealthPercentage.value
    if (health >= 90) return { label: 'Excellent', color: 'text-green-600 dark:text-green-400', bgColor: 'bg-green-100 dark:bg-green-900/40' }
    if (health >= 70) return { label: 'Good',      color: 'text-blue-600 dark:text-blue-400',   bgColor: 'bg-blue-100 dark:bg-blue-900/40' }
    if (health >= 50) return { label: 'Fair',      color: 'text-yellow-600 dark:text-yellow-400', bgColor: 'bg-yellow-100 dark:bg-yellow-900/40' }
    return               { label: 'Poor',      color: 'text-red-600 dark:text-red-400',     bgColor: 'bg-red-100 dark:bg-red-900/40' }
  })

  const revenueGrowth = computed(() => {
    const rev = chartData.value.revenue
    if (rev.length < 2) return null
    const current  = rev[rev.length - 1]?.value || 0
    const previous = rev[rev.length - 2]?.value || 0
    if (previous === 0) return null
    const growth = ((current - previous) / previous) * 100
    return { value: Math.abs(growth).toFixed(1), direction: growth >= 0 ? 'up' : 'down', isPositive: growth >= 0 }
  })

  const userGrowth = computed(() => {
    const usr = chartData.value.users
    if (usr.length < 2) return null
    const current  = usr[usr.length - 1]?.value || 0
    const previous = usr[usr.length - 2]?.value || 0
    if (previous === 0) return null
    const growth = ((current - previous) / previous) * 100
    return { value: Math.abs(growth).toFixed(1), direction: growth >= 0 ? 'up' : 'down', isPositive: growth >= 0 }
  })

  return {
    stats,
    paymentData,
    expensesData,
    analyticsData,
    revenueAssurance,
    chartData,
    recentActivities,
    onlineUsers,
    loading,
    hasCachedSnapshot,
    lastUpdated,
    fetchDashboardStats,
    fetchHealthScore,
    refreshStats,
    updateStatsFromEvent,
    formatCurrency,
    formatDataSize,
    formatTimeAgo,
    routerHealthPercentage,
    routerHealthStatus,
    healthScore,
    revenueGrowth,
    userGrowth,
  }
}

export default useDashboard
