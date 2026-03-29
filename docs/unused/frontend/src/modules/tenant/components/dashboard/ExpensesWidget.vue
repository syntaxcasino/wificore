<template>
  <div class="expenses-widget">
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2zM10 8.5a.5.5 0 11-1 0 .5.5 0 011 0zm5 5a.5.5 0 11-1 0 .5.5 0 011 0z" />
          </svg>
        </div>
        <div>
          <h3>SMS Expenses</h3>
          <p class="subtitle">Track SMS credits and usage</p>
        </div>
      </div>
    </div>

    <div class="expenses-grid">
      <!-- SMS Overview -->
      <div class="expense-card">
        <h4>SMS Balance</h4>
        <div class="balance-display">
          <span class="balance-value">{{ expensesData.sms.remaining.toLocaleString() }}</span>
          <span class="balance-label">Remaining</span>
        </div>
        <div class="sms-breakdown">
          <div class="breakdown-row">
            <span>Purchased</span>
            <span class="value">{{ expensesData.sms.totalPurchased.toLocaleString() }}</span>
          </div>
          <div class="breakdown-row">
            <span>Used</span>
            <span class="value">{{ expensesData.sms.used.toLocaleString() }}</span>
          </div>
        </div>
      </div>

      <!-- Usage Stats -->
      <div class="expense-card">
        <h4>Usage Statistics</h4>
        <div class="usage-stats">
          <div class="stat-row">
            <span class="stat-label">Daily</span>
            <span class="stat-value">{{ expensesData.sms.dailyUsage.toLocaleString() }}</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">Weekly</span>
            <span class="stat-value">{{ expensesData.sms.weeklyUsage.toLocaleString() }}</span>
          </div>
          <div class="stat-row">
            <span class="stat-label">Monthly</span>
            <span class="stat-value">{{ expensesData.sms.monthlyUsage.toLocaleString() }}</span>
          </div>
        </div>
      </div>

      <!-- Cost Analysis -->
      <div class="expense-card">
        <h4>Cost Analysis</h4>
        <div class="cost-display">
          <div class="cost-item">
            <span class="cost-label">Total Spent</span>
            <span class="cost-value">{{ formatCurrency(expensesData.costs.totalSpent) }}</span>
          </div>
          <div class="cost-item">
            <span class="cost-label">This Month</span>
            <span class="cost-value">{{ formatCurrency(expensesData.costs.thisMonth) }}</span>
          </div>
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
.expenses-widget {
  background: white;
  border-radius: 16px;
  padding: 28px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  border: 1px solid #e2e8f0;
}

.widget-header {
  margin-bottom: 24px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.icon-wrapper {
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
}

.widget-header h3 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
}

.subtitle {
  margin: 4px 0 0 0;
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.expenses-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}

.expense-card {
  padding: 20px;
  background: #f8fafc;
  border-radius: 12px;
  border: 2px solid #e2e8f0;
}

.expense-card h4 {
  margin: 0 0 16px 0;
  font-size: 14px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
}

.balance-display {
  text-align: center;
  padding: 24px;
  background: white;
  border-radius: 12px;
  margin-bottom: 16px;
}

.balance-value {
  display: block;
  font-size: 36px;
  font-weight: 800;
  color: #1e293b;
}

.balance-label {
  display: block;
  font-size: 13px;
  color: #64748b;
  margin-top: 8px;
}

.sms-breakdown, .usage-stats, .cost-display {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.breakdown-row, .stat-row {
  display: flex;
  justify-content: space-between;
  padding: 12px;
  background: white;
  border-radius: 8px;
}

.breakdown-row span, .stat-label {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}

.breakdown-row .value, .stat-value {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.cost-item {
  display: flex;
  justify-content: space-between;
  padding: 16px;
  background: white;
  border-radius: 8px;
}

.cost-label {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}

.cost-value {
  font-size: 18px;
  font-weight: 700;
  color: #059669;
}
</style>
