<template>
  <div class="system-health-widget">
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
          </svg>
        </div>
        <div>
          <h3>System Health Monitor</h3>
          <p class="subtitle">Real-time infrastructure status</p>
        </div>
      </div>
      <button @click="refreshHealth" :disabled="loading" class="refresh-btn">
        <svg v-if="loading" class="animate-spin w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </button>
    </div>

    <div v-if="loading && !healthData" class="loading">
      <div class="spinner"></div>
      <p>Loading health status...</p>
    </div>

    <div v-else-if="error" class="error-state">
      <svg class="w-12 h-12 text-red-500 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="error-title">Failed to load health status</p>
      <p class="error-message">{{ error }}</p>
      <button @click="refreshHealth" class="retry-btn">
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        Retry
      </button>
    </div>

    <div v-else-if="healthData" class="health-content">
      <!-- Overall Status -->
      <div class="overall-status" :class="`status-${healthData?.status || 'unknown'}`">
        <div class="status-icon-large">
          {{ statusIcon }}
        </div>
        <div class="status-info">
          <h4>{{ statusText }}</h4>
          <p class="status-description">{{ healthData?.summary?.healthy || 0 }} of {{ healthData?.summary?.total_checks || 0 }} system checks passed</p>
          <div class="status-bar">
            <div class="status-bar-fill" :style="{ width: `${((healthData?.summary?.healthy || 0) / (healthData?.summary?.total_checks || 1)) * 100}%` }"></div>
          </div>
        </div>
      </div>

      <!-- Health Metrics Grid -->
      <div class="health-metrics">
        <!-- Database -->
        <div class="metric-card" :class="`status-${healthData?.checks?.database?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.database?.status || 'unknown'}`">
              {{ healthData?.checks?.database?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>Database Connection</h5>
            <p class="metric-value">{{ healthData.checks.database.current_connections || 0 }}<span class="unit">/ {{ healthData.checks.database.max_connections || 100 }}</span></p>
            <p class="metric-detail">Active Connections</p>
            <div class="metric-extra">
              <div class="extra-item">
                <span class="extra-label">Response:</span>
                <span class="extra-value">{{ healthData.checks.database.response_time }}ms</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Host:</span>
                <span class="extra-value">{{ healthData.checks.database.host || 'localhost' }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Size:</span>
                <span class="extra-value">{{ healthData.checks.database.database_size || 'N/A' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Redis -->
        <div class="metric-card" :class="`status-${healthData?.checks?.redis?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.redis?.status || 'unknown'}`">
              {{ healthData?.checks?.redis?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>Redis Cache</h5>
            <p class="metric-value" v-if="healthData.checks.redis.keyspace">
              {{ healthData.checks.redis.keyspace.total_keys || 0 }}<span class="unit">keys</span>
            </p>
            <p class="metric-value" v-else>N/A</p>
            <p class="metric-detail">Total Keys Stored</p>
            <div class="metric-extra" v-if="healthData.checks.redis.memory">
              <div class="extra-item">
                <span class="extra-label">Memory:</span>
                <span class="extra-value">{{ healthData.checks.redis.memory.used }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Hit Rate:</span>
                <span class="extra-value">{{ healthData.checks.redis.performance?.hit_rate || 0 }}%</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Ops/sec:</span>
                <span class="extra-value">{{ healthData.checks.redis.performance?.ops_per_sec || 0 }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Clients:</span>
                <span class="extra-value">{{ healthData.checks.redis.connections?.connected_clients || 0 }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Uptime:</span>
                <span class="extra-value">{{ healthData.checks.redis.uptime || 'N/A' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Disk Space -->
        <div class="metric-card" :class="`status-${healthData?.checks?.disk_space?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.disk_space?.status || 'unknown'}`">
              {{ healthData?.checks?.disk_space?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>Disk Space</h5>
            <p class="metric-value">{{ healthData.checks.disk_space.used_percent }}<span class="unit">%</span></p>
            <p class="metric-detail">{{ healthData.checks.disk_space.free }} Available</p>
            <div class="metric-extra">
              <div class="extra-item">
                <span class="extra-label">System:</span>
                <span class="extra-value">{{ healthData.checks.disk_space.system || 'N/A' }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Used:</span>
                <span class="extra-value">{{ healthData.checks.disk_space.used }} / {{ healthData.checks.disk_space.total }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Host:</span>
                <span class="extra-value">{{ healthData.checks.disk_space.hostname || 'N/A' }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Memory -->
        <div class="metric-card" :class="`status-${healthData?.checks?.memory?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.memory?.status || 'unknown'}`">
              {{ healthData?.checks?.memory?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>Memory Usage (PHP)</h5>
            <p class="metric-value">{{ healthData.checks.memory.used_percent || 0 }}<span class="unit">%</span></p>
            <p class="metric-detail">{{ healthData.checks.memory.current }} / {{ healthData.checks.memory.limit }}</p>
            <div class="metric-extra" v-if="healthData.checks.memory.system_info && healthData.checks.memory.system_info.available">
              <div class="extra-item">
                <span class="extra-label">System:</span>
                <span class="extra-value">{{ healthData.checks.memory.system_info.os }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Total:</span>
                <span class="extra-value">{{ healthData.checks.memory.system_info.total }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Used:</span>
                <span class="extra-value">{{ healthData.checks.memory.system_info.used }} ({{ healthData.checks.memory.system_info.used_percent }}%)</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Cached:</span>
                <span class="extra-value">{{ healthData.checks.memory.system_info.cached }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Queues -->
        <div class="metric-card" :class="`status-${healthData?.checks?.queues?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.queues?.status || 'unknown'}`">
              {{ healthData?.checks?.queues?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>Job Queues</h5>
            <p class="metric-value">{{ formatNumber(healthData.checks.queues.processed_jobs || 0) }}<span class="unit"></span></p>
            <p class="metric-detail">Total Processed Jobs</p>
            <div class="metric-extra">
              <div class="extra-item">
                <span class="extra-label">Pending:</span>
                <span class="extra-value">{{ healthData.checks.queues.pending_jobs || 0 }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Failed:</span>
                <span class="extra-value" :class="{ 'failed-count': healthData.checks.queues.failed_jobs > 0 }">{{ healthData.checks.queues.failed_jobs || 0 }}</span>
              </div>
              <div class="extra-item">
                <span class="extra-label">Workers:</span>
                <span class="extra-value" :class="{ 'success-count': healthData.checks.queues.workers_running }">
                  {{ healthData.checks.queues.workers_running ? `${healthData.checks.queues.worker_count} running` : 'Not running' }}
                </span>
              </div>
              <div class="extra-item" v-if="healthData.checks.queues.failed_by_queue && Object.keys(healthData.checks.queues.failed_by_queue).length > 0">
                <span class="extra-label">Failed by queue:</span>
                <div class="queue-breakdown">
                  <span class="queue-item" v-for="(count, queue) in healthData.checks.queues.failed_by_queue" :key="queue">
                    {{ queue }}: {{ count }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Logs -->
        <div class="metric-card" :class="`status-${healthData?.checks?.logs?.status || 'unknown'}`">
          <div class="metric-header">
            <div class="metric-icon">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </div>
            <div class="status-badge" :class="`badge-${healthData?.checks?.logs?.status || 'unknown'}`">
              {{ healthData?.checks?.logs?.status || 'unknown' }}
            </div>
          </div>
          <div class="metric-info">
            <h5>System Logs</h5>
            <p class="metric-value-text">{{ healthData.checks.logs.size }}</p>
            <p class="metric-detail">File Size</p>
            <div class="metric-extra" v-if="healthData.checks.logs.recent_errors">
              <div class="extra-item" v-if="healthData.checks.logs.recent_errors.critical > 0">
                <span class="extra-label">Critical:</span>
                <span class="extra-value failed-count">{{ healthData.checks.logs.recent_errors.critical }}</span>
              </div>
              <div class="extra-item" v-if="healthData.checks.logs.recent_errors.error > 0">
                <span class="extra-label">Errors:</span>
                <span class="extra-value failed-count">{{ healthData.checks.logs.recent_errors.error }}</span>
              </div>
              <div class="extra-item" v-if="healthData.checks.logs.recent_errors.warning > 0">
                <span class="extra-label">Warnings:</span>
                <span class="extra-value" style="color: #f59e0b;">{{ healthData.checks.logs.recent_errors.warning }}</span>
              </div>
              <div class="extra-item" v-if="healthData.checks.logs.last_modified">
                <span class="extra-label">Updated:</span>
                <span class="extra-value">{{ healthData.checks.logs.minutes_since_update }}m ago</span>
              </div>
              <div class="extra-item" v-if="healthData.checks.logs.is_active !== undefined">
                <span class="extra-label">Status:</span>
                <span class="extra-value" :style="{ color: healthData.checks.logs.is_active ? '#10b981' : '#94a3b8' }">
                  {{ healthData.checks.logs.is_active ? 'Active' : 'Idle' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Last Updated -->
      <div class="widget-footer">
        <div class="footer-item">
          <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <span>Updated {{ lastUpdated }}</span>
        </div>
        <div class="footer-item">
          <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
          <span>{{ healthData.duration }}s response</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const healthData = ref(null)
const loading = ref(false)
const error = ref(null)
const lastUpdated = ref('')
let refreshInterval = null

const statusIcon = computed(() => {
  if (!healthData.value) return '⏳'
  switch (healthData.value.status) {
    case 'healthy': return '✅'
    case 'warning': return '⚠️'
    case 'unhealthy': return '❌'
    default: return '❓'
  }
})

const statusText = computed(() => {
  if (!healthData.value) return 'Checking...'
  switch (healthData.value.status) {
    case 'healthy': return 'All Systems Operational'
    case 'warning': return 'Some Issues Detected'
    case 'unhealthy': return 'Critical Issues'
    default: return 'Unknown Status'
  }
})

const formatNumber = (num) => {
  return new Intl.NumberFormat().format(num)
}

const refreshHealth = async () => {
  loading.value = true
  error.value = null
  
  try {
    const response = await axios.get('/health')
    healthData.value = response.data
    lastUpdated.value = new Date().toLocaleTimeString()
  } catch (err) {
    console.error('Failed to fetch health status:', err)
    error.value = err.response?.data?.message || 'Failed to fetch health status'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  refreshHealth()
  // Auto-refresh every 30 seconds
  refreshInterval = setInterval(refreshHealth, 30000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>

<style scoped>
.system-health-widget {
  background: linear-gradient(135deg, #ffffff 0%, #f8fafc 100%);
  border-radius: 16px;
  padding: 28px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.08);
  border: 1px solid #e2e8f0;
}

.widget-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
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
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.icon-wrapper svg {
  color: white;
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

.refresh-btn {
  background: white;
  border: 2px solid #e2e8f0;
  border-radius: 10px;
  padding: 10px;
  cursor: pointer;
  transition: all 0.3s ease;
  display: flex;
  align-items: center;
  justify-content: center;
}

.refresh-btn:hover:not(:disabled) {
  background: #f1f5f9;
  border-color: #cbd5e1;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.refresh-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.refresh-btn svg {
  color: #475569;
}

.loading {
  text-align: center;
  padding: 60px 20px;
}

.spinner {
  width: 48px;
  height: 48px;
  margin: 0 auto 20px;
  border: 4px solid #e2e8f0;
  border-top-color: #3b82f6;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.loading p {
  color: #64748b;
  font-size: 15px;
  font-weight: 500;
}

.error-state {
  text-align: center;
  padding: 60px 20px;
}

.error-title {
  font-size: 18px;
  font-weight: 600;
  color: #1e293b;
  margin: 0 0 8px 0;
}

.error-message {
  color: #ef4444;
  margin: 12px 0 24px 0;
  font-size: 14px;
}

.retry-btn {
  background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
  color: white;
  border: none;
  border-radius: 10px;
  padding: 12px 24px;
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  transition: all 0.3s ease;
  box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
}

.retry-btn:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(59, 130, 246, 0.4);
}

.overall-status {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 24px;
  border-radius: 12px;
  margin-bottom: 28px;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.overall-status.status-healthy {
  background: linear-gradient(135deg, #ecfdf5 0%, #d1fae5 100%);
  border: 2px solid #10b981;
}

.overall-status.status-warning {
  background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
  border: 2px solid #f59e0b;
}

.overall-status.status-unhealthy {
  background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
  border: 2px solid #ef4444;
}

.status-icon-large {
  font-size: 48px;
  line-height: 1;
}

.status-info {
  flex: 1;
}

.status-info h4 {
  margin: 0 0 8px 0;
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
}

.status-description {
  margin: 0 0 12px 0;
  font-size: 14px;
  color: #475569;
  font-weight: 500;
}

.status-bar {
  width: 100%;
  height: 8px;
  background: rgba(0, 0, 0, 0.1);
  border-radius: 999px;
  overflow: hidden;
}

.status-bar-fill {
  height: 100%;
  background: linear-gradient(90deg, #10b981 0%, #059669 100%);
  border-radius: 999px;
  transition: width 0.5s ease;
}

.health-metrics {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}

.metric-card {
  padding: 20px;
  border-radius: 12px;
  border: 2px solid #e2e8f0;
  background: white;
  transition: all 0.3s ease;
  position: relative;
  overflow: hidden;
}

.metric-card::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 4px;
  height: 100%;
  transition: width 0.3s ease;
}

.metric-card:hover {
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  transform: translateY(-4px);
  border-color: #cbd5e1;
}

.metric-card.status-healthy::before {
  background: linear-gradient(180deg, #10b981 0%, #059669 100%);
}

.metric-card.status-warning::before,
.metric-card.status-degraded::before {
  background: linear-gradient(180deg, #f59e0b 0%, #d97706 100%);
}

.metric-card.status-unhealthy::before {
  background: linear-gradient(180deg, #ef4444 0%, #dc2626 100%);
}

.metric-card:hover::before {
  width: 6px;
}

.metric-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}

.metric-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.3s ease;
}

.metric-card:hover .metric-icon {
  transform: scale(1.1);
  background: linear-gradient(135deg, #e2e8f0 0%, #cbd5e1 100%);
}

.metric-icon svg {
  color: #475569;
}

.status-badge {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.badge-healthy {
  background: #d1fae5;
  color: #065f46;
}

.badge-warning,
.badge-degraded {
  background: #fde68a;
  color: #92400e;
}

.badge-unhealthy {
  background: #fecaca;
  color: #991b1b;
}

.metric-info {
  flex: 1;
}

.metric-info h5 {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 600;
  color: #64748b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.metric-value {
  margin: 0 0 4px 0;
  font-size: 28px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1;
}

.metric-value .unit {
  font-size: 16px;
  font-weight: 600;
  color: #64748b;
  margin-left: 2px;
}

.metric-value-text {
  margin: 0 0 4px 0;
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.metric-detail {
  margin: 0;
  font-size: 12px;
  color: #94a3b8;
  font-weight: 500;
}

.metric-extra {
  margin-top: 12px;
  padding-top: 12px;
  border-top: 1px solid #e2e8f0;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.extra-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 11px;
}

.extra-label {
  color: #64748b;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.3px;
}

.extra-value {
  color: #1e293b;
  font-weight: 600;
  text-align: right;
}

.extra-value.failed-count {
  color: #dc2626;
  font-weight: 700;
}

.extra-value.success-count {
  color: #059669;
  font-weight: 700;
}

.queue-breakdown {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 4px;
}

.queue-item {
  font-size: 11px;
  padding: 2px 8px;
  background: #fee2e2;
  color: #991b1b;
  border-radius: 4px;
  font-weight: 600;
}

.widget-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 20px;
  border-top: 2px solid #e2e8f0;
}

.footer-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.footer-item svg {
  flex-shrink: 0;
}

@media (max-width: 768px) {
  .health-metrics {
    grid-template-columns: 1fr;
  }
  
  .widget-footer {
    flex-direction: column;
    gap: 8px;
    align-items: flex-start;
  }
}
</style>
