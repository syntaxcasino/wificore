<template>
  <div class="widget-container">
    <h3 class="widget-title">SMS Expenses</h3>
    <p class="widget-subtitle">Track SMS credits and usage</p>
    
    <div class="cards-grid">
      <!-- SMS Balance Card -->
      <div class="action-card">
        <div class="card-icon bg-red-100">
          <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">SMS Balance</span>
          <p class="card-amount">{{ expensesData.sms.remaining.toLocaleString() }}</p>
          <p class="card-meta">Remaining credits</p>
        </div>
      </div>

      <!-- Purchased Card -->
      <div class="action-card">
        <div class="card-icon bg-blue-100">
          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Purchased</span>
          <p class="card-amount">{{ expensesData.sms.totalPurchased.toLocaleString() }}</p>
          <p class="card-meta">Total SMS bought</p>
        </div>
      </div>

      <!-- Used Card -->
      <div class="action-card">
        <div class="card-icon bg-orange-100">
          <svg class="w-6 h-6 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Used</span>
          <p class="card-amount">{{ expensesData.sms.used.toLocaleString() }}</p>
          <p class="card-meta">SMS sent</p>
        </div>
      </div>

      <!-- Daily Usage Card -->
      <div class="action-card">
        <div class="card-icon bg-green-100">
          <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Daily Usage</span>
          <p class="card-amount">{{ expensesData.sms.dailyUsage.toLocaleString() }}</p>
          <p class="card-meta">Today's SMS</p>
        </div>
      </div>

      <!-- Weekly Usage Card -->
      <div class="action-card">
        <div class="card-icon bg-purple-100">
          <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Weekly Usage</span>
          <p class="card-amount">{{ expensesData.sms.weeklyUsage.toLocaleString() }}</p>
          <p class="card-meta">Last 7 days</p>
        </div>
      </div>

      <!-- Monthly Usage Card -->
      <div class="action-card">
        <div class="card-icon bg-indigo-100">
          <svg class="w-6 h-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Monthly Usage</span>
          <p class="card-amount">{{ expensesData.sms.monthlyUsage.toLocaleString() }}</p>
          <p class="card-meta">This month</p>
        </div>
      </div>

      <!-- Total Spent Card -->
      <div class="action-card">
        <div class="card-icon bg-amber-100">
          <svg class="w-6 h-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">Total Spent</span>
          <p class="card-amount">{{ formatCurrency(expensesData.costs.totalSpent) }}</p>
          <p class="card-meta">All time</p>
        </div>
      </div>

      <!-- This Month Cost Card -->
      <div class="action-card">
        <div class="card-icon bg-teal-100">
          <svg class="w-6 h-6 text-teal-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z" />
          </svg>
        </div>
        <div class="card-content">
          <span class="card-label">This Month</span>
          <p class="card-amount">{{ formatCurrency(expensesData.costs.thisMonth) }}</p>
          <p class="card-meta">Current month cost</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
const props = defineProps({
  expensesData: {
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

.card-label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  margin-bottom: 8px;
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
