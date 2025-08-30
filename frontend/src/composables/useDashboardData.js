import { ref, computed } from 'vue'

export function useDashboardData() {
  // Mock data - in a real app, this would come from an API
  const dashboardData = ref({
    stats: {
      amountThisMonth: { value: 'Ksh 50.00', description: 'Total earned this month' },
      sideBalance: { value: 'Ksh 44.40', description: 'Your area balance' },
      equity: { value: 'Filters', date: '16 Aug 2025' },
      totalClients: { value: '171', description: 'Number of clients' },
    },
    payments: {
      labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug'],
      data: [1000, 2000, 2500, 3000, 3500, 3000, 4000, 0], // Last month is August with 0
    },
    activeUsers: {
      labels: ['Fri', 'Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
      data: [3, 6, 10, 12, 15, 18, 20, 0], // Last day is Friday with 0
    },
    userStats: {
      averageUsers: 10,
      peakUsers: 10,
    },
    retentionRate: {
      description: 'How many customers are returning and how many are churning?',
    },
  })

  const formattedStats = computed(() => ({
    amountThisMonth: {
      title: 'Amount this month',
      ...dashboardData.value.stats.amountThisMonth,
    },
    sideBalance: {
      title: 'Side balance',
      ...dashboardData.value.stats.sideBalance,
    },
    equity: {
      title: `Equity: ${dashboardData.value.stats.equity.date}`,
      value: dashboardData.value.stats.equity.value,
    },
    totalClients: {
      title: 'Total clients',
      ...dashboardData.value.stats.totalClients,
    },
  }))

  return {
    dashboardData,
    formattedStats,
  }
}

// Export as default for backward compatibility
export default useDashboardData
