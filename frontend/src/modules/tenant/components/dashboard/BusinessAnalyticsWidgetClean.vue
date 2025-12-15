<template>
  <div class="widget-container">
    <h3 class="widget-title">Business Analytics</h3>
    <p class="widget-subtitle">User retention, trends, and revenue insights</p>
    
    <div class="cards-grid">
      <!-- User Retention Card -->
      <div class="action-card">
        <div class="card-icon bg-green-100">
          <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <div class="card-header-row">
            <span class="card-label">User Retention</span>
            <span class="card-badge badge-green">{{ analyticsData.retention.rate }}%</span>
          </div>
          <p class="card-amount">{{ analyticsData.retention.retainedUsers }}</p>
          <p class="card-meta">of {{ analyticsData.retention.lastMonthUsers }} users retained</p>
        </div>
      </div>

      <!-- Revenue Average Card -->
      <div class="action-card">
        <div class="card-icon bg-purple-100">
          <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Avg Revenue</span>
          <p class="card-amount">{{ formatCurrency(analyticsData.revenueAverage) }}</p>
          <p class="card-meta">Daily average</p>
        </div>
      </div>

      <!-- Revenue Peak Card -->
      <div class="action-card">
        <div class="card-icon bg-amber-100">
          <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Peak Revenue</span>
          <p class="card-amount">{{ formatCurrency(analyticsData.revenuePeak) }}</p>
          <p class="card-meta">Highest day</p>
        </div>
      </div>

      <!-- Revenue Growth Card -->
      <div class="action-card">
        <div class="card-icon" :class="analyticsData.revenueGrowth >= 0 ? 'bg-green-100' : 'bg-red-100'">
          <svg class="w-6 h-6" :class="analyticsData.revenueGrowth >= 0 ? 'text-green-600' : 'text-red-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Revenue Growth</span>
          <p class="card-amount" :class="analyticsData.revenueGrowth >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ analyticsData.revenueGrowth >= 0 ? '+' : '' }}{{ analyticsData.revenueGrowth }}%
          </p>
          <p class="card-meta">vs last period</p>
        </div>
      </div>

      <!-- User Average Card -->
      <div class="action-card">
        <div class="card-icon bg-blue-100">
          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Avg Active Users</span>
          <p class="card-amount">{{ analyticsData.userAverage }}</p>
          <p class="card-meta">Daily average</p>
        </div>
      </div>

      <!-- User Peak Card -->
      <div class="action-card">
        <div class="card-icon bg-indigo-100">
          <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Peak Users</span>
          <p class="card-amount">{{ analyticsData.userPeak }}</p>
          <p class="card-meta">Highest day</p>
        </div>
      </div>

      <!-- User Growth Card -->
      <div class="action-card">
        <div class="card-icon" :class="analyticsData.userGrowth >= 0 ? 'bg-green-100' : 'bg-red-100'">
          <svg class="w-6 h-6" :class="analyticsData.userGrowth >= 0 ? 'text-green-600' : 'text-red-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">User Growth</span>
          <p class="card-amount" :class="analyticsData.userGrowth >= 0 ? 'text-green-600' : 'text-red-600'">
            {{ analyticsData.userGrowth >= 0 ? '+' : '' }}{{ analyticsData.userGrowth }}%
          </p>
          <p class="card-meta">vs last period</p>
        </div>
      </div>

      <!-- Access Points Summary Card -->
      <div class="action-card">
        <div class="card-icon bg-cyan-100">
          <svg class="w-6 h-6 text-cyan-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-15.857 21.213 0" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Access Points</span>
          <p class="card-amount">{{ analyticsData.accessPoints.length }}</p>
          <p class="card-meta">Active locations</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  analyticsData: {
    type: Object,
    required: true
  }
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
  }).format(value || 0)
}
</script>

<style scoped>
.widget-container {
  background: white;
  border-radius: 12px;
  border: 1px solid #e5e7eb;
  padding: 24px;
}

.widget-title {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  margin: 0 0 4px 0;
}

.widget-subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0 0 20px 0;
}

.cards-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 16px;
}

.action-card {
  display: flex;
  align-items: flex-start;
  gap: 16px;
  padding: 20px;
  background: white;
  border: 2px solid #e5e7eb;
  border-radius: 12px;
  transition: all 0.2s;
}

.action-card:hover {
  border-color: #9ca3af;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.card-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: all 0.2s;
}

.card-content {
  flex: 1;
  min-width: 0;
}

.card-header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  gap: 8px;
}

.card-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
}

.card-badge {
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  flex-shrink: 0;
}

.badge-green {
  background: #d1fae5;
  color: #065f46;
}

.card-amount {
  font-size: 24px;
  font-weight: 800;
  color: #111827;
  margin: 0 0 4px 0;
}

.card-meta {
  font-size: 13px;
  color: #6b7280;
  margin: 0;
}

@media (max-width: 768px) {
  .cards-grid {
    grid-template-columns: 1fr;
  }
}
</style>
