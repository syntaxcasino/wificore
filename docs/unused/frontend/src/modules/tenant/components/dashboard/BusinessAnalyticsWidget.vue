<template>
  <div class="analytics-widget">
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div>
          <h3>Business Analytics</h3>
          <p class="subtitle">User retention, trends, and revenue insights</p>
        </div>
      </div>
    </div>

    <div class="analytics-grid">
      <!-- User Retention -->
      <div class="analytics-card retention">
        <div class="card-header">
          <h4>User Retention Rate</h4>
          <span class="retention-badge">{{ analyticsData.retention.rate }}%</span>
        </div>
        <div class="card-body">
          <div class="retention-visual">
            <svg class="retention-ring" viewBox="0 0 100 100">
              <circle class="ring-bg" cx="50" cy="50" r="40" />
              <circle 
                class="ring-progress" 
                cx="50" 
                cy="50" 
                r="40"
                :stroke-dasharray="`${analyticsData.retention.rate * 2.51} 251`"
              />
            </svg>
            <div class="retention-center">
              <span class="rate">{{ analyticsData.retention.rate }}%</span>
            </div>
          </div>
          <div class="retention-stats">
            <div class="stat-item">
              <span class="label">Last Month Users</span>
              <span class="value">{{ analyticsData.retention.lastMonthUsers }}</span>
            </div>
            <div class="stat-item">
              <span class="label">Retained Users</span>
              <span class="value highlight">{{ analyticsData.retention.retainedUsers }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Active Users by Access Point -->
      <div class="analytics-card access-points">
        <div class="card-header">
          <h4>Active Users per Access Point</h4>
        </div>
        <div class="card-body">
          <div class="access-point-list">
            <div v-for="ap in analyticsData.accessPoints" :key="ap.id" class="ap-item">
              <div class="ap-info">
                <div class="ap-icon">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904 3.905 10.236 3.905 14.142 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
                  </svg>
                </div>
                <div class="ap-details">
                  <span class="ap-name">{{ ap.name }}</span>
                  <span class="ap-location">{{ ap.location }}</span>
                </div>
              </div>
              <div class="ap-metrics">
                <span class="user-count">{{ ap.activeUsers }}</span>
                <div class="usage-bar">
                  <div class="usage-fill" :style="{ width: ap.percentage + '%' }"></div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Revenue Trends -->
      <div class="analytics-card revenue-trends">
        <div class="card-header">
          <h4>Revenue Trends</h4>
          <select v-model="selectedPeriod" class="period-selector">
            <option value="7days">Last 7 Days</option>
            <option value="30days">Last 30 Days</option>
            <option value="90days">Last 90 Days</option>
          </select>
        </div>
        <div class="card-body">
          <div class="trend-chart">
            <div v-for="(point, index) in analyticsData.revenueTrend" :key="index" class="chart-bar">
              <div class="bar-wrapper">
                <div class="bar" :style="{ height: point.percentage + '%' }">
                  <div class="tooltip">{{ formatCurrency(point.amount) }}</div>
                </div>
              </div>
              <span class="bar-label">{{ point.label }}</span>
            </div>
          </div>
          <div class="trend-summary">
            <div class="summary-item">
              <span class="summary-label">Average</span>
              <span class="summary-value">{{ formatCurrency(analyticsData.revenueAverage) }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">Peak</span>
              <span class="summary-value">{{ formatCurrency(analyticsData.revenuePeak) }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">Growth</span>
              <span class="summary-value" :class="analyticsData.revenueGrowth >= 0 ? 'positive' : 'negative'">
                {{ analyticsData.revenueGrowth >= 0 ? '+' : '' }}{{ analyticsData.revenueGrowth }}%
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Active User Trends -->
      <div class="analytics-card user-trends">
        <div class="card-header">
          <h4>Active User Trends</h4>
        </div>
        <div class="card-body">
          <div class="trend-chart">
            <div v-for="(point, index) in analyticsData.userTrend" :key="index" class="chart-bar">
              <div class="bar-wrapper">
                <div class="bar user-bar" :style="{ height: point.percentage + '%' }">
                  <div class="tooltip">{{ point.count }} users</div>
                </div>
              </div>
              <span class="bar-label">{{ point.label }}</span>
            </div>
          </div>
          <div class="trend-summary">
            <div class="summary-item">
              <span class="summary-label">Average</span>
              <span class="summary-value">{{ analyticsData.userAverage }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">Peak</span>
              <span class="summary-value">{{ analyticsData.userPeak }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">Growth</span>
              <span class="summary-value" :class="analyticsData.userGrowth >= 0 ? 'positive' : 'negative'">
                {{ analyticsData.userGrowth >= 0 ? '+' : '' }}{{ analyticsData.userGrowth }}%
              </span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'

const props = defineProps({
  analyticsData: {
    type: Object,
    required: true
  }
})

const selectedPeriod = ref('7days')

const formatCurrency = (value) => {
  return new Intl.NumberFormat('en-KE', {
    style: 'currency',
    currency: 'KES',
    minimumFractionDigits: 0,
  }).format(value || 0)
}
</script>

<style scoped>
.analytics-widget {
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
  background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
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

.analytics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  gap: 24px;
}

.analytics-card {
  background: white;
  border-radius: 16px;
  padding: 24px;
  border: 2px solid #e2e8f0;
  transition: all 0.3s ease;
}

.analytics-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 12px 32px rgba(0, 0, 0, 0.12);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 16px;
  border-bottom: 2px solid #f1f5f9;
}

.card-header h4 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.retention-badge {
  padding: 6px 14px;
  background: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
  color: #065f46;
  border-radius: 999px;
  font-size: 16px;
  font-weight: 800;
}

.retention-visual {
  position: relative;
  width: 160px;
  height: 160px;
  margin: 0 auto 24px;
}

.retention-ring {
  width: 100%;
  height: 100%;
  transform: rotate(-90deg);
}

.ring-bg {
  fill: none;
  stroke: #e2e8f0;
  stroke-width: 10;
}

.ring-progress {
  fill: none;
  stroke: #10b981;
  stroke-width: 10;
  stroke-linecap: round;
  transition: stroke-dasharray 0.5s ease;
}

.retention-center {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  text-align: center;
}

.retention-center .rate {
  font-size: 36px;
  font-weight: 800;
  color: #1e293b;
}

.retention-stats {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.stat-item {
  display: flex;
  justify-content: space-between;
  padding: 12px;
  background: #f8fafc;
  border-radius: 8px;
}

.stat-item .label {
  font-size: 13px;
  color: #64748b;
  font-weight: 600;
}

.stat-item .value {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.stat-item .value.highlight {
  color: #059669;
}

.access-point-list {
  display: flex;
  flex-direction: column;
  gap: 16px;
  max-height: 400px;
  overflow-y: auto;
}

.ap-item {
  padding: 16px;
  background: #f8fafc;
  border-radius: 12px;
  border: 1px solid #e2e8f0;
}

.ap-info {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
}

.ap-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.ap-icon svg {
  color: #1e40af;
}

.ap-details {
  flex: 1;
  display: flex;
  flex-direction: column;
}

.ap-name {
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.ap-location {
  font-size: 12px;
  color: #64748b;
}

.ap-metrics {
  display: flex;
  align-items: center;
  gap: 12px;
}

.user-count {
  font-size: 20px;
  font-weight: 800;
  color: #1e293b;
  min-width: 40px;
}

.usage-bar {
  flex: 1;
  height: 8px;
  background: #e2e8f0;
  border-radius: 999px;
  overflow: hidden;
}

.usage-fill {
  height: 100%;
  background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
  border-radius: 999px;
  transition: width 0.5s ease;
}

.period-selector {
  padding: 6px 12px;
  border: 2px solid #e2e8f0;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  background: white;
  cursor: pointer;
}

.trend-chart {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 8px;
  height: 150px;
  margin-bottom: 20px;
}

.chart-bar {
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
  background: linear-gradient(180deg, #8b5cf6 0%, #7c3aed 100%);
  border-radius: 4px 4px 0 0;
  min-height: 4px;
  position: relative;
  transition: all 0.3s ease;
}

.bar.user-bar {
  background: linear-gradient(180deg, #3b82f6 0%, #2563eb 100%);
}

.bar:hover {
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

.bar-label {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
}

.trend-summary {
  display: flex;
  justify-content: space-around;
  padding: 16px;
  background: #f8fafc;
  border-radius: 12px;
}

.summary-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.summary-label {
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
}

.summary-value {
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.summary-value.positive {
  color: #059669;
}

.summary-value.negative {
  color: #dc2626;
}

@media (max-width: 768px) {
  .analytics-grid {
    grid-template-columns: 1fr;
  }
}
</style>
