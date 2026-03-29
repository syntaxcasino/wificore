<template>
  <div class="payment-widget">
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <h3>Payment Analytics</h3>
          <p class="subtitle">Detailed income breakdown across all periods</p>
        </div>
      </div>
    </div>

    <div class="payment-cards">
      <!-- Daily Income -->
      <div class="payment-card daily">
        <div class="card-header">
          <div class="period-icon">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <span class="period-badge">Today</span>
        </div>
        <div class="card-body">
          <h4>Daily Income</h4>
          <p class="amount">{{ formatCurrency(paymentData.daily.amount) }}</p>
          <div class="date-info">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>{{ paymentData.daily.date }}</span>
          </div>
          <div class="payment-count">
            <span class="count">{{ paymentData.daily.count }}</span>
            <span class="label">Payments</span>
          </div>
        </div>
      </div>

      <!-- Weekly Income -->
      <div class="payment-card weekly">
        <div class="card-header">
          <div class="period-icon">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
          </div>
          <span class="period-badge">7 Days</span>
        </div>
        <div class="card-body">
          <h4>Weekly Income</h4>
          <p class="amount">{{ formatCurrency(paymentData.weekly.amount) }}</p>
          <div class="date-range">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>{{ paymentData.weekly.startDate }} - {{ paymentData.weekly.endDate }}</span>
          </div>
          <div class="daily-breakdown">
            <h5>Daily Breakdown</h5>
            <div class="breakdown-bars">
              <div v-for="day in paymentData.weekly.dailyBreakdown" :key="day.date" class="bar-item">
                <div class="bar-wrapper">
                  <div class="bar" :style="{ height: day.percentage + '%' }">
                    <div class="tooltip">{{ formatCurrency(day.amount) }}</div>
                  </div>
                </div>
                <span class="day-label">{{ day.day }}</span>
              </div>
            </div>
          </div>
          <div class="payment-count">
            <span class="count">{{ paymentData.weekly.count }}</span>
            <span class="label">Payments</span>
          </div>
        </div>
      </div>

      <!-- Monthly Income -->
      <div class="payment-card monthly">
        <div class="card-header">
          <div class="period-icon">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <span class="period-badge">Month</span>
        </div>
        <div class="card-body">
          <h4>Monthly Income</h4>
          <p class="amount">{{ formatCurrency(paymentData.monthly.amount) }}</p>
          <div class="month-info">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>{{ paymentData.monthly.month }} {{ paymentData.monthly.year }}</span>
          </div>
          <div class="weekly-breakdown">
            <h5>Weekly Breakdown</h5>
            <div class="week-list">
              <div v-for="week in paymentData.monthly.weeklyBreakdown" :key="week.week" class="week-item">
                <div class="week-header">
                  <span class="week-label">Week {{ week.week }}</span>
                  <span class="week-amount">{{ formatCurrency(week.amount) }}</span>
                </div>
                <div class="week-dates">{{ week.startDate }} - {{ week.endDate }}</div>
                <div class="week-bar">
                  <div class="week-bar-fill" :style="{ width: week.percentage + '%' }"></div>
                </div>
              </div>
            </div>
          </div>
          <div class="payment-count">
            <span class="count">{{ paymentData.monthly.count }}</span>
            <span class="label">Payments</span>
          </div>
        </div>
      </div>

      <!-- Yearly Income -->
      <div class="payment-card yearly">
        <div class="card-header">
          <div class="period-icon">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
          </div>
          <span class="period-badge">Year</span>
        </div>
        <div class="card-body">
          <h4>Yearly Income</h4>
          <p class="amount">{{ formatCurrency(paymentData.yearly.amount) }}</p>
          <div class="year-info">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>{{ paymentData.yearly.year }}</span>
          </div>
          <div class="monthly-breakdown">
            <h5>Monthly Breakdown</h5>
            <div class="month-grid">
              <div v-for="month in paymentData.yearly.monthlyBreakdown" :key="month.month" class="month-item">
                <div class="month-name">{{ month.monthName }}</div>
                <div class="month-amount">{{ formatCurrency(month.amount) }}</div>
                <div class="month-bar">
                  <div class="month-bar-fill" :style="{ width: month.percentage + '%' }"></div>
                </div>
              </div>
            </div>
          </div>
          <div class="payment-count">
            <span class="count">{{ paymentData.yearly.count }}</span>
            <span class="label">Payments</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  paymentData: {
    type: Object,
    required: true,
    default: () => ({
      daily: { amount: 0, date: '', count: 0 },
      weekly: { amount: 0, startDate: '', endDate: '', count: 0, dailyBreakdown: [] },
      monthly: { amount: 0, month: '', year: '', count: 0, weeklyBreakdown: [] },
      yearly: { amount: 0, year: '', count: 0, monthlyBreakdown: [] }
    })
  }
})

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(value || 0)
}
</script>

<style scoped>
.payment-widget {
  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  border-radius: 16px;
  padding: 28px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  border: 1px solid #e2e8f0;
}

.widget-header {
  margin-bottom: 28px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.icon-wrapper {
  width: 48px;
  height: 48px;
  background: linear-gradient(135deg, #10b981 0%, #059669 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
}

.widget-header h3 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  letter-spacing: -0.5px;
}

.subtitle {
  margin: 4px 0 0 0;
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.payment-cards {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 24px;
}

.payment-card {
  background: white;
  border-radius: 16px;
  padding: 24px;
  border: 2px solid #e2e8f0;
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.payment-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 4px;
  transition: height 0.3s ease;
}

.payment-card.daily::before {
  background: linear-gradient(90deg, #10b981 0%, #059669 100%);
}

.payment-card.weekly::before {
  background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
}

.payment-card.monthly::before {
  background: linear-gradient(90deg, #8b5cf6 0%, #7c3aed 100%);
}

.payment-card.yearly::before {
  background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
}

.payment-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
  border-color: #cbd5e1;
}

.payment-card:hover::before {
  height: 6px;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.period-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.period-icon svg {
  color: #475569;
}

.period-badge {
  padding: 6px 14px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.daily .period-badge {
  background: #d1fae5;
  color: #065f46;
}

.weekly .period-badge {
  background: #dbeafe;
  color: #1e40af;
}

.monthly .period-badge {
  background: #ede9fe;
  color: #5b21b6;
}

.yearly .period-badge {
  background: #fef3c7;
  color: #92400e;
}

.card-body h4 {
  margin: 0 0 12px 0;
  font-size: 14px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.amount {
  margin: 0 0 16px 0;
  font-size: 32px;
  font-weight: 800;
  color: #1e293b;
  line-height: 1;
}

.date-info,
.date-range,
.month-info,
.year-info {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 8px;
  margin-bottom: 16px;
}

.date-info svg,
.date-range svg,
.month-info svg,
.year-info svg {
  color: #64748b;
  flex-shrink: 0;
}

.date-info span,
.date-range span,
.month-info span,
.year-info span {
  font-size: 13px;
  font-weight: 600;
  color: #475569;
}

.payment-count {
  display: flex;
  align-items: baseline;
  gap: 8px;
  margin-top: 16px;
  padding-top: 16px;
  border-top: 2px solid #f1f5f9;
}

.payment-count .count {
  font-size: 24px;
  font-weight: 700;
  color: #1e293b;
}

.payment-count .label {
  font-size: 13px;
  font-weight: 600;
  color: #64748b;
}

/* Daily Breakdown for Weekly */
.daily-breakdown h5,
.weekly-breakdown h5,
.monthly-breakdown h5 {
  margin: 0 0 12px 0;
  font-size: 12px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.breakdown-bars {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
  height: 100px;
  margin-bottom: 16px;
}

.bar-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.bar-wrapper {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: flex-end;
}

.bar {
  width: 100%;
  background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
  border-radius: 4px 4px 0 0;
  min-height: 4px;
  position: relative;
  transition: all 0.3s ease;
}

.bar:hover {
  background: linear-gradient(180deg, #2563eb 0%, #1d4ed8 100%);
  transform: scaleY(1.05);
}

.bar .tooltip {
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%) translateY(-8px);
  background: #1e293b;
  color: white;
  padding: 4px 8px;
  border-radius: 6px;
  font-size: 11px;
  font-weight: 600;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s ease;
}

.bar:hover .tooltip {
  opacity: 1;
}

.day-label {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

/* Weekly Breakdown for Monthly */
.week-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 16px;
}

.week-item {
  padding: 12px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}

.week-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 6px;
}

.week-label {
  font-size: 12px;
  font-weight: 700;
  color: #475569;
}

.week-amount {
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.week-dates {
  font-size: 11px;
  color: #64748b;
  margin-bottom: 8px;
}

.week-bar {
  width: 100%;
  height: 6px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
}

.week-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #8b5cf6 0%, #7c3aed 100%);
  border-radius: 999px;
  transition: width 0.5s ease;
}

/* Monthly Breakdown for Yearly */
.month-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.month-item {
  padding: 12px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  transition: all 0.3s ease;
}

.month-item:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.month-name {
  font-size: 11px;
  font-weight: 700;
  color: #64748b;
  text-transform: uppercase;
  margin-bottom: 6px;
}

.month-amount {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 8px;
}

.month-bar {
  width: 100%;
  height: 4px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
}

.month-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #f59e0b 0%, #d97706 100%);
  border-radius: 999px;
  transition: width 0.5s ease;
}

@media (max-width: 1200px) {
  .payment-cards {
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  }
}

@media (max-width: 768px) {
  .payment-cards {
    grid-template-columns: 1fr;
  }
  
  .month-grid {
    grid-template-columns: 1fr;
  }
}
</style>
