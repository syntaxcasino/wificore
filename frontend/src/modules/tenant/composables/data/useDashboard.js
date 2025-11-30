import { ref, computed } from 'vue'
import axios from 'axios'

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
    retention: {
      rate: 0,
      lastMonthUsers: 0,
      retainedUsers: 0
    },
    accessPoints: [],
    revenueTrend: [],
    revenueAverage: 0,
    revenuePeak: 0,
    revenueGrowth: 0,
    userTrend: [],
    userAverage: 0,
    userPeak: 0,
    userGrowth: 0
  })

  const chartData = ref({
    labels: [],
    users: [],
    revenue: [],
  })

  const recentActivities = ref([])
  const onlineUsers = ref([])
  const loading = ref(true)
  const lastUpdated = ref(null)

  /**
   * Fetch dashboard statistics from backend
   */
  const fetchDashboardStats = async () => {
    try {
      const response = await axios.get('/dashboard/stats')

      if (response.data.success) {
        const data = response.data.data

        // Update stats
        stats.value = {
          totalRouters: data.total_routers || 0,
          activeSessions: data.active_sessions || 0,
          hotspotUsers: data.hotspot_users || 0,
          pppoeUsers: data.pppoe_users || 0,
          totalUsers: data.total_users || 0,
          totalRevenue: data.total_revenue || 0,
          dailyIncome: data.daily_income || 0,
          weeklyIncome: data.weekly_income || 0,
          monthlyIncome: data.monthly_income || 0,
          yearlyIncome: data.yearly_income || 0,
          monthlyRevenue: data.monthly_revenue || 0,
          dataUsage: data.data_usage || 0,
          dataUsageUpload: data.data_usage_upload || 0,
          dataUsageDownload: data.data_usage_download || 0,
          monthlyDataUsage: data.monthly_data_usage || 0,
          todayDataUsage: data.today_data_usage || 0,
          retentionRate: data.retention_rate || 0,
          smsBalance: data.sms_balance || 0,
          onlineRouters: data.online_routers || 0,
          offlineRouters: data.offline_routers || 0,
          provisioningRouters: data.provisioning_routers || 0,
          lastMonthUsers: data.last_month_users || 0,
          retainedUsers: data.retained_users || 0,
        }

        // Update payment data
        if (data.payment_details) {
          paymentData.value = data.payment_details
        }

        // Update expenses data
        if (data.sms_expenses) {
          expensesData.value = data.sms_expenses
        }

        // Update analytics data
        if (data.business_analytics) {
          analyticsData.value = data.business_analytics
        }

        // Update chart data
        if (data.weekly_users_trend && data.weekly_users_trend.length > 0) {
          chartData.value.labels = data.weekly_users_trend.map((item) => item.date)
          const maxCount = Math.max(...data.weekly_users_trend.map((i) => i.count), 1)
          chartData.value.users = data.weekly_users_trend.map((item) => ({
            value: item.count,
            percentage: (item.count / maxCount) * 100,
          }))
        }

        if (data.weekly_revenue_trend && data.weekly_revenue_trend.length > 0) {
          const maxRevenue = Math.max(...data.weekly_revenue_trend.map((i) => i.amount), 1)
          chartData.value.revenue = data.weekly_revenue_trend.map((item) => ({
            value: item.amount,
            percentage: (item.amount / maxRevenue) * 100,
          }))
        }

        // Update recent activities
        if (data.recent_activities) {
          recentActivities.value = data.recent_activities
        }

        // Update online users
        if (data.online_users) {
          onlineUsers.value = data.online_users
        }

        lastUpdated.value = data.last_updated
        loading.value = false
      }
    } catch (error) {
      console.error('Failed to fetch dashboard stats:', error)
      loading.value = false
    }
  }

  /**
   * Refresh dashboard statistics
   */
  const refreshStats = async () => {
    try {
      await axios.post('/dashboard/refresh')
      // Fetch updated stats after a short delay
      setTimeout(fetchDashboardStats, 1000)
    } catch (error) {
      console.error('Failed to refresh dashboard stats:', error)
    }
  }

  /**
   * Update stats from WebSocket event
   */
  const updateStatsFromEvent = (data) => {
    stats.value = {
      totalRouters: data.total_routers || 0,
      activeSessions: data.active_sessions || 0,
      hotspotUsers: data.hotspot_users || 0,
      pppoeUsers: data.pppoe_users || 0,
      totalUsers: data.total_users || 0,
      totalRevenue: data.total_revenue || 0,
      dailyIncome: data.daily_income || 0,
      weeklyIncome: data.weekly_income || 0,
      monthlyIncome: data.monthly_income || 0,
      yearlyIncome: data.yearly_income || 0,
      monthlyRevenue: data.monthly_revenue || 0,
      dataUsage: data.data_usage || 0,
      dataUsageUpload: data.data_usage_upload || 0,
      dataUsageDownload: data.data_usage_download || 0,
      monthlyDataUsage: data.monthly_data_usage || 0,
      todayDataUsage: data.today_data_usage || 0,
      retentionRate: data.retention_rate || 0,
      smsBalance: data.sms_balance || 0,
      onlineRouters: data.online_routers || 0,
      offlineRouters: data.offline_routers || 0,
      provisioningRouters: data.provisioning_routers || 0,
      lastMonthUsers: data.last_month_users || 0,
      retainedUsers: data.retained_users || 0,
    }

    // Update payment data
    if (data.payment_details) {
      paymentData.value = data.payment_details
    }

    // Update expenses data
    if (data.sms_expenses) {
      expensesData.value = data.sms_expenses
    }

    // Update analytics data
    if (data.business_analytics) {
      analyticsData.value = data.business_analytics
    }

    // Update chart data
    if (data.weekly_users_trend && data.weekly_users_trend.length > 0) {
      chartData.value.labels = data.weekly_users_trend.map((item) => item.date)
      const maxCount = Math.max(...data.weekly_users_trend.map((i) => i.count), 1)
      chartData.value.users = data.weekly_users_trend.map((item) => ({
        value: item.count,
        percentage: (item.count / maxCount) * 100,
      }))
    }

    if (data.weekly_revenue_trend && data.weekly_revenue_trend.length > 0) {
      const maxRevenue = Math.max(...data.weekly_revenue_trend.map((i) => i.amount), 1)
      chartData.value.revenue = data.weekly_revenue_trend.map((item) => ({
        value: item.amount,
        percentage: (item.amount / maxRevenue) * 100,
      }))
    }

    // Update recent activities
    if (data.recent_activities) {
      recentActivities.value = data.recent_activities
    }

    // Update online users
    if (data.online_users) {
      onlineUsers.value = data.online_users
    }

    lastUpdated.value = data.last_updated
  }

  /**
   * Format currency value
   */
  const formatCurrency = (value) => {
    return new Intl.NumberFormat('en-KE', {
      style: 'currency',
      currency: 'KES',
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(value || 0)
  }

  /**
   * Format data size
   */
  const formatDataSize = (gb) => {
    if (gb >= 1000) {
      return `${(gb / 1000).toFixed(2)} TB`
    }
    return `${(gb || 0).toFixed(2)} GB`
  }

  /**
   * Format time ago
   */
  const formatTimeAgo = (timestamp) => {
    if (!timestamp) return 'Never'

    const now = new Date()
    const updated = new Date(timestamp)
    const seconds = Math.floor((now - updated) / 1000)

    if (seconds < 10) return 'just now'
    if (seconds < 60) return `${seconds}s ago`

    const minutes = Math.floor(seconds / 60)
    if (minutes < 60) return `${minutes}m ago`

    const hours = Math.floor(minutes / 60)
    if (hours < 24) return `${hours}h ago`

    const days = Math.floor(hours / 24)
    return `${days}d ago`
  }

  /**
   * Calculate router health percentage
   */
  const routerHealthPercentage = computed(() => {
    const total = stats.value.totalRouters
    if (total === 0) return 0
    return Math.round((stats.value.onlineRouters / total) * 100)
  })

  /**
   * Get router health status
   */
  const routerHealthStatus = computed(() => {
    const health = routerHealthPercentage.value
    if (health >= 90) return { label: 'Excellent', color: 'text-green-600', bgColor: 'bg-green-100' }
    if (health >= 70) return { label: 'Good', color: 'text-blue-600', bgColor: 'bg-blue-100' }
    if (health >= 50) return { label: 'Fair', color: 'text-yellow-600', bgColor: 'bg-yellow-100' }
    return { label: 'Poor', color: 'text-red-600', bgColor: 'bg-red-100' }
  })

  /**
   * Calculate revenue growth
   */
  const revenueGrowth = computed(() => {
    if (chartData.value.revenue.length < 2) return null
    const current = chartData.value.revenue[chartData.value.revenue.length - 1]?.value || 0
    const previous = chartData.value.revenue[chartData.value.revenue.length - 2]?.value || 0
    if (previous === 0) return null
    const growth = ((current - previous) / previous) * 100
    return {
      value: Math.abs(growth).toFixed(1),
      direction: growth >= 0 ? 'up' : 'down',
      isPositive: growth >= 0,
    }
  })

  /**
   * Calculate user growth
   */
  const userGrowth = computed(() => {
    if (chartData.value.users.length < 2) return null
    const current = chartData.value.users[chartData.value.users.length - 1]?.value || 0
    const previous = chartData.value.users[chartData.value.users.length - 2]?.value || 0
    if (previous === 0) return null
    const growth = ((current - previous) / previous) * 100
    return {
      value: Math.abs(growth).toFixed(1),
      direction: growth >= 0 ? 'up' : 'down',
      isPositive: growth >= 0,
    }
  })

  return {
    stats,
    paymentData,
    expensesData,
    analyticsData,
    chartData,
    recentActivities,
    onlineUsers,
    loading,
    lastUpdated,
    fetchDashboardStats,
    refreshStats,
    updateStatsFromEvent,
    formatCurrency,
    formatDataSize,
    formatTimeAgo,
    routerHealthPercentage,
    routerHealthStatus,
    revenueGrowth,
    userGrowth,
  }
}

export default useDashboard
