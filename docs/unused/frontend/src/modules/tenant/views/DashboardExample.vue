<template>
  <div class="dashboard-view">
    <h1 class="text-2xl font-bold mb-6">
      {{ isSystemAdmin ? 'System Admin Dashboard' : 'Tenant Dashboard' }}
    </h1>
    
    <!-- Loading State -->
    <div v-if="loading" class="loading">
      <p>Loading dashboard data...</p>
    </div>
    
    <!-- Error State -->
    <div v-else-if="error" class="error">
      <p>{{ error }}</p>
    </div>
    
    <!-- Dashboard Content -->
    <div v-else class="dashboard-content">
      <!-- System Admin Stats -->
      <div v-if="isSystemAdmin" class="stats-grid">
        <div class="stat-card">
          <h3>Total Tenants</h3>
          <p class="stat-value">{{ dashboardData.total_tenants }}</p>
        </div>
        <div class="stat-card">
          <h3>Total Users</h3>
          <p class="stat-value">{{ dashboardData.total_users }}</p>
        </div>
        <div class="stat-card">
          <h3>Platform Revenue</h3>
          <p class="stat-value">{{ formatCurrency(dashboardData.total_revenue) }}</p>
        </div>
        <div class="stat-card">
          <h3>Active Sessions</h3>
          <p class="stat-value">{{ dashboardData.active_sessions }}</p>
        </div>
      </div>
      
      <!-- Tenant Stats -->
      <div v-else class="stats-grid">
        <div class="stat-card">
          <h3>My Users</h3>
          <p class="stat-value">{{ dashboardData.users }}</p>
        </div>
        <div class="stat-card">
          <h3>My Packages</h3>
          <p class="stat-value">{{ dashboardData.packages?.length || 0 }}</p>
        </div>
        <div class="stat-card">
          <h3>My Revenue</h3>
          <p class="stat-value">{{ formatCurrency(dashboardData.revenue) }}</p>
        </div>
        <div class="stat-card">
          <h3>Active Sessions</h3>
          <p class="stat-value">{{ dashboardData.sessions }}</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoleBasedData } from '@/modules/common/composables/auth/useRoleBasedData'

const { isSystemAdmin, fetchDashboardData } = useRoleBasedData()

const loading = ref(true)
const error = ref(null)
const dashboardData = ref({})

const loadDashboard = async () => {
  try {
    loading.value = true
    error.value = null
    
    // This will automatically call the correct API based on role
    dashboardData.value = await fetchDashboardData()
  } catch (err) {
    error.value = err.response?.data?.message || 'Failed to load dashboard data'
    console.error('Dashboard error:', err)
  } finally {
    loading.value = false
  }
}

const formatCurrency = (amount) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES'
  }).format(amount || 0)
}

onMounted(() => {
  loadDashboard()
})
</script>

<style scoped>
.dashboard-view {
  padding: 20px;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-top: 20px;
}

.stat-card {
  background: white;
  padding: 20px;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
}

.stat-card h3 {
  font-size: 14px;
  color: #666;
  margin-bottom: 10px;
}

.stat-value {
  font-size: 32px;
  font-weight: bold;
  color: #16a34a;
}

.loading, .error {
  padding: 40px;
  text-align: center;
}

.error {
  color: #ef4444;
}
</style>
