<template>
  <div class="payment-widget">
    <!-- Widget Header -->
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <h3>Payment Analytics</h3>
          <p class="subtitle">Click any card to view detailed breakdown</p>
        </div>
      </div>
    </div>

    <div class="summary-grid">
      <!-- Daily Summary -->
      <div 
        class="summary-card daily cursor-pointer group"
        @click="openDetails('daily')"
      >
        <div class="card-header">
          <div class="icon-wrapper bg-gradient-to-br from-green-500 to-emerald-600">
            <svg class="text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <span class="badge bg-green-100 text-green-700">Today</span>
        </div>
        <div class="card-body">
          <p class="label">Daily Income</p>
          <h4 class="amount">{{ formatCurrency(paymentData.daily.amount) }}</h4>
          <div class="meta">
            <span class="count">{{ paymentData.daily.count }} payments</span>
            <svg class="w-4 h-4 text-gray-400 group-hover:text-green-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Weekly Summary -->
      <div 
        class="summary-card weekly cursor-pointer group"
        @click="openDetails('weekly')"
      >
        <div class="card-header">
          <div class="icon-wrapper bg-gradient-to-br from-blue-500 to-blue-600">
            <svg class="text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
          </div>
          <span class="badge bg-blue-100 text-blue-700">7 Days</span>
        </div>
        <div class="card-body">
          <p class="label">Weekly Income</p>
          <h4 class="amount">{{ formatCurrency(paymentData.weekly.amount) }}</h4>
          <div class="meta">
            <span class="count">{{ paymentData.weekly.count }} payments</span>
            <svg class="w-4 h-4 text-gray-400 group-hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Monthly Summary -->
      <div 
        class="summary-card monthly cursor-pointer group"
        @click="openDetails('monthly')"
      >
        <div class="card-header">
          <div class="icon-wrapper bg-gradient-to-br from-purple-500 to-purple-600">
            <svg class="text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
          </div>
          <span class="badge bg-purple-100 text-purple-700">Month</span>
        </div>
        <div class="card-body">
          <p class="label">Monthly Income</p>
          <h4 class="amount">{{ formatCurrency(paymentData.monthly.amount) }}</h4>
          <div class="meta">
            <span class="count">{{ paymentData.monthly.count }} payments</span>
            <svg class="w-4 h-4 text-gray-400 group-hover:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Sliding Overlay Panel -->
    <Transition name="slide">
      <div v-if="showDetails" class="overlay-panel" @click.self="closeDetails">
        <div class="panel-content">
          <!-- Panel Header -->
          <div class="panel-header">
            <div>
              <h3 class="text-xl font-bold text-gray-900">{{ detailsTitle }}</h3>
              <p class="text-sm text-gray-600 mt-1">{{ detailsSubtitle }}</p>
            </div>
            <button @click="closeDetails" class="close-btn">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <!-- Panel Body -->
          <div class="panel-body">
            <!-- Daily Details -->
            <div v-if="activePanel === 'daily'" class="details-content">
              <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">{{ formatCurrency(paymentData.daily.amount) }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value">{{ paymentData.daily.count }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Date</div>
                <div class="stat-value text-lg">{{ paymentData.daily.date }}</div>
              </div>
              
              <div class="section-title">Payment Methods</div>
              <div class="method-list">
                <div class="method-item">
                  <div class="method-info">
                    <span class="method-name">M-Pesa</span>
                    <span class="method-count">45 transactions</span>
                  </div>
                  <span class="method-amount">{{ formatCurrency(paymentData.daily.amount * 0.6) }}</span>
                </div>
                <div class="method-item">
                  <div class="method-info">
                    <span class="method-name">Cash</span>
                    <span class="method-count">12 transactions</span>
                  </div>
                  <span class="method-amount">{{ formatCurrency(paymentData.daily.amount * 0.3) }}</span>
                </div>
                <div class="method-item">
                  <div class="method-info">
                    <span class="method-name">Bank Transfer</span>
                    <span class="method-count">5 transactions</span>
                  </div>
                  <span class="method-amount">{{ formatCurrency(paymentData.daily.amount * 0.1) }}</span>
                </div>
              </div>
            </div>

            <!-- Weekly Details -->
            <div v-if="activePanel === 'weekly'" class="details-content">
              <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">{{ formatCurrency(paymentData.weekly.amount) }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value">{{ paymentData.weekly.count }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Average per Day</div>
                <div class="stat-value">{{ formatCurrency(paymentData.weekly.amount / 7) }}</div>
              </div>
              
              <div class="section-title">Daily Breakdown</div>
              <div class="breakdown-chart">
                <div v-for="day in paymentData.weekly.dailyBreakdown" :key="day.date" class="chart-bar">
                  <div class="bar-container">
                    <div class="bar-fill" :style="{ height: day.percentage + '%' }">
                      <div class="bar-tooltip">{{ formatCurrency(day.amount) }}</div>
                    </div>
                  </div>
                  <div class="bar-label">{{ day.day }}</div>
                  <div class="bar-value">{{ formatCurrency(day.amount) }}</div>
                </div>
              </div>
            </div>

            <!-- Monthly Details -->
            <div v-if="activePanel === 'monthly'" class="details-content">
              <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">{{ formatCurrency(paymentData.monthly.amount) }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value">{{ paymentData.monthly.count }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Period</div>
                <div class="stat-value text-lg">{{ paymentData.monthly.month }} {{ paymentData.monthly.year }}</div>
              </div>
              
              <div class="section-title">Weekly Breakdown</div>
              <div class="week-breakdown">
                <div v-for="week in paymentData.monthly.weeklyBreakdown" :key="week.week" class="week-card">
                  <div class="week-header">
                    <span class="week-label">Week {{ week.week }}</span>
                    <span class="week-amount">{{ formatCurrency(week.amount) }}</span>
                  </div>
                  <div class="week-dates">{{ week.startDate }} - {{ week.endDate }}</div>
                  <div class="week-progress">
                    <div class="progress-bar" :style="{ width: week.percentage + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Yearly Details -->
            <div v-if="activePanel === 'yearly'" class="details-content">
              <div class="stat-card">
                <div class="stat-label">Total Amount</div>
                <div class="stat-value">{{ formatCurrency(paymentData.yearly.amount) }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Total Payments</div>
                <div class="stat-value">{{ paymentData.yearly.count }}</div>
              </div>
              <div class="stat-card">
                <div class="stat-label">Year</div>
                <div class="stat-value text-lg">{{ paymentData.yearly.year }}</div>
              </div>
              
              <div class="section-title">Monthly Breakdown</div>
              <div class="month-grid">
                <div v-for="month in paymentData.yearly.monthlyBreakdown" :key="month.month" class="month-card">
                  <div class="month-name">{{ month.monthName }}</div>
                  <div class="month-amount">{{ formatCurrency(month.amount) }}</div>
                  <div class="month-progress">
                    <div class="progress-bar" :style="{ width: month.percentage + '%' }"></div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

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

const showDetails = ref(false)
const activePanel = ref('')

const detailsTitle = computed(() => {
  const titles = {
    daily: 'Daily Income Details',
    weekly: 'Weekly Income Details',
    monthly: 'Monthly Income Details',
    yearly: 'Yearly Income Details'
  }
  return titles[activePanel.value] || ''
})

const detailsSubtitle = computed(() => {
  const subtitles = {
    daily: `Complete breakdown for ${props.paymentData.daily.date}`,
    weekly: `Analysis for ${props.paymentData.weekly.startDate} - ${props.paymentData.weekly.endDate}`,
    monthly: `Detailed view for ${props.paymentData.monthly.month} ${props.paymentData.monthly.year}`
  }
  return subtitles[activePanel.value] || ''
})

const openDetails = (panel) => {
  activePanel.value = panel
  showDetails.value = true
  document.body.style.overflow = 'hidden'
}

const closeDetails = () => {
  showDetails.value = false
  document.body.style.overflow = ''
}

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
  position: relative;
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

.widget-header .icon-wrapper {
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
}

.subtitle {
  margin: 4px 0 0 0;
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
}

.summary-card {
  padding: 20px;
  background: #f8fafc;
  border-radius: 12px;
  border: 2px solid #e2e8f0;
  transition: all 0.3s ease;
  position: relative;
}

.summary-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
  border-color: #cbd5e1;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.card-header .icon-wrapper {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.card-header .icon-wrapper svg {
  width: 18px;
  height: 18px;
}

.badge {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.card-body .label {
  font-size: 14px;
  font-weight: 700;
  color: #64748b;
  margin-bottom: 12px;
  text-transform: uppercase;
}

.card-body .amount {
  font-size: 32px;
  font-weight: 800;
  color: #1e293b;
  margin-bottom: 16px;
  display: block;
}

.card-body .meta {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px;
  background: white;
  border-radius: 8px;
}

.card-body .count {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}

/* Overlay Panel */
.overlay-panel {
  position: fixed;
  top: 0;
  right: 0;
  bottom: 0;
  left: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 1000;
  display: flex;
  justify-content: flex-end;
}

.panel-content {
  width: 50%;
  max-width: 800px;
  background: white;
  height: 100%;
  overflow-y: auto;
  box-shadow: -4px 0 24px rgba(0, 0, 0, 0.2);
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: start;
  padding: 24px;
  border-bottom: 1px solid #e5e7eb;
  position: sticky;
  top: 0;
  background: white;
  z-index: 10;
}

.close-btn {
  padding: 8px;
  border-radius: 8px;
  transition: all 0.2s;
  color: #6b7280;
}

.close-btn:hover {
  background: #f3f4f6;
  color: #111827;
}

.panel-body {
  padding: 24px;
}

.details-content {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.stat-card {
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 12px;
  padding: 20px;
}

.stat-label {
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  margin-bottom: 8px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.stat-value {
  font-size: 32px;
  font-weight: 800;
  color: #111827;
}

.section-title {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
  margin-top: 8px;
  margin-bottom: 16px;
}

.method-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.method-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  transition: all 0.2s;
}

.method-item:hover {
  border-color: #d1d5db;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.method-info {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.method-name {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
}

.method-count {
  font-size: 12px;
  color: #6b7280;
}

.method-amount {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}

.breakdown-chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 12px;
  height: 240px;
  padding: 20px;
  background: #f9fafb;
  border-radius: 12px;
}

.chart-bar {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.bar-container {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: flex-end;
  justify-content: center;
}

.bar-fill {
  width: 80%;
  background: linear-gradient(180deg, #3b82f6, #2563eb);
  border-radius: 6px 6px 0 0;
  min-height: 8px;
  position: relative;
  transition: all 0.3s ease;
}

.bar-fill:hover {
  background: linear-gradient(180deg, #2563eb, #1d4ed8);
  transform: scaleY(1.05);
}

.bar-tooltip {
  position: absolute;
  bottom: 100%;
  left: 50%;
  transform: translateX(-50%) translateY(-8px);
  background: #111827;
  color: white;
  padding: 6px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  transition: opacity 0.3s;
}

.bar-fill:hover .bar-tooltip {
  opacity: 1;
}

.bar-label {
  font-size: 12px;
  font-weight: 600;
  color: #6b7280;
}

.bar-value {
  font-size: 11px;
  color: #9ca3af;
}

.week-breakdown {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.week-card {
  padding: 16px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
}

.week-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.week-label {
  font-size: 14px;
  font-weight: 700;
  color: #111827;
}

.week-amount {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}

.week-dates {
  font-size: 12px;
  color: #6b7280;
  margin-bottom: 12px;
}

.week-progress {
  width: 100%;
  height: 8px;
  background: #f3f4f6;
  border-radius: 999px;
  overflow: hidden;
}

.progress-bar {
  height: 100%;
  background: linear-gradient(90deg, #8b5cf6, #7c3aed);
  border-radius: 999px;
  transition: width 0.5s ease;
}

.month-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
  gap: 16px;
}

.month-card {
  padding: 16px;
  background: white;
  border: 1px solid #e5e7eb;
  border-radius: 10px;
  transition: all 0.2s;
}

.month-card:hover {
  border-color: #d1d5db;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.month-name {
  font-size: 12px;
  font-weight: 700;
  color: #6b7280;
  text-transform: uppercase;
  margin-bottom: 8px;
}

.month-amount {
  font-size: 18px;
  font-weight: 700;
  color: #111827;
  margin-bottom: 12px;
}

.month-progress {
  width: 100%;
  height: 6px;
  background: #f3f4f6;
  border-radius: 999px;
  overflow: hidden;
}

/* Slide Animation */
.slide-enter-active,
.slide-leave-active {
  transition: all 0.3s ease;
}

.slide-enter-from {
  opacity: 0;
}

.slide-leave-to {
  opacity: 0;
}

.slide-enter-active .panel-content,
.slide-leave-active .panel-content {
  transition: transform 0.3s ease;
}

.slide-enter-from .panel-content {
  transform: translateX(100%);
}

.slide-leave-to .panel-content {
  transform: translateX(100%);
}

@media (max-width: 1024px) {
  .panel-content {
    width: 70%;
  }
}

@media (max-width: 768px) {
  .summary-grid {
    grid-template-columns: 1fr;
  }
  
  .panel-content {
    width: 100%;
  }
  
  .month-grid {
    grid-template-columns: 1fr;
  }
}
</style>
