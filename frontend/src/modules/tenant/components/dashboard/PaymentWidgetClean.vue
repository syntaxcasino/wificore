<template>
  <div class="widget-container">
    <h3 class="widget-title">Payment Analytics</h3>
    <p class="widget-subtitle">Click any card to view detailed breakdown</p>
    
    <div class="cards-grid">
      <!-- Daily Card -->
      <button
        @click="openDetails('daily')"
        class="action-card group"
      >
        <div class="card-icon bg-green-100 group-hover:bg-green-200">
          <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="card-content">
          <div class="card-header-row">
            <span class="card-label">Daily Income</span>
            <span class="card-badge badge-green">Today</span>
          </div>
          <p class="card-amount">{{ formatCurrency(paymentData.daily.amount) }}</p>
          <p class="card-meta">{{ paymentData.daily.count }} payments</p>
        </div>
      </button>

      <!-- Weekly Card -->
      <button
        @click="openDetails('weekly')"
        class="action-card group"
      >
        <div class="card-icon bg-blue-100 group-hover:bg-blue-200">
          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div class="card-content">
          <div class="card-header-row">
            <span class="card-label">Weekly Income</span>
            <span class="card-badge badge-blue">7 Days</span>
          </div>
          <p class="card-amount">{{ formatCurrency(paymentData.weekly.amount) }}</p>
          <p class="card-meta">{{ paymentData.weekly.count }} payments</p>
        </div>
      </button>

      <!-- Monthly Card -->
      <button
        @click="openDetails('monthly')"
        class="action-card group"
      >
        <div class="card-icon bg-purple-100 group-hover:bg-purple-200">
          <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
          </svg>
        </div>
        <div class="card-content">
          <div class="card-header-row">
            <span class="card-label">Monthly Income</span>
            <span class="card-badge badge-purple">Month</span>
          </div>
          <p class="card-amount">{{ formatCurrency(paymentData.monthly.amount) }}</p>
          <p class="card-meta">{{ paymentData.monthly.count }} payments</p>
        </div>
      </button>
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
      monthly: { amount: 0, month: '', year: '', count: 0, weeklyBreakdown: [] }
    })
  }
})

const showDetails = ref(false)
const activePanel = ref('')

const detailsTitle = computed(() => {
  const titles = {
    daily: 'Daily Income Details',
    weekly: 'Weekly Income Details',
    monthly: 'Monthly Income Details'
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
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
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
  cursor: pointer;
  text-align: left;
  width: 100%;
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
  font-size: 13px;
  font-weight: 600;
  color: #6b7280;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.card-badge {
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
  flex-shrink: 0;
}

.badge-green {
  background: #d1fae5;
  color: #065f46;
}

.badge-blue {
  background: #dbeafe;
  color: #1e40af;
}

.badge-purple {
  background: #ede9fe;
  color: #5b21b6;
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

/* Overlay Panel Styles */
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
  .cards-grid {
    grid-template-columns: 1fr;
  }
  
  .panel-content {
    width: 100%;
  }
}
</style>
