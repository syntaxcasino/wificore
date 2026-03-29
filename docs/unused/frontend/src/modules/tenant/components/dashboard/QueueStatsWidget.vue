<template>
  <div class="queue-stats-widget" v-if="queueStats">
    <div class="widget-header">
      <div class="header-left">
        <div class="icon-wrapper">
          <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
          </svg>
        </div>
        <div>
          <h3>Queue Statistics</h3>
          <p class="subtitle">Job processing metrics and health</p>
        </div>
      </div>
      <div class="health-badge" :class="`badge-${queueStats?.health_status || 'unknown'}`">
        {{ queueStats?.health_status || 'loading' }}
      </div>
    </div>

    <div class="stats-grid">
      <!-- Summary Cards -->
      <div class="stat-card total">
        <div class="stat-icon">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
          </svg>
        </div>
        <div class="stat-content">
          <span class="stat-label">Total Jobs</span>
          <span class="stat-value">{{ formatNumber(queueStats.summary?.total_jobs || 0) }}</span>
        </div>
      </div>

      <div class="stat-card processed">
        <div class="stat-icon success">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="stat-content">
          <span class="stat-label">Processed</span>
          <span class="stat-value success">{{ formatNumber(queueStats.summary?.processed || 0) }}</span>
          <span class="stat-percentage">{{ queueStats.summary?.success_rate || 0 }}%</span>
        </div>
      </div>

      <div class="stat-card pending">
        <div class="stat-icon warning">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="stat-content">
          <span class="stat-label">Pending</span>
          <span class="stat-value warning">{{ formatNumber(queueStats.summary?.pending || 0) }}</span>
        </div>
      </div>

      <div class="stat-card failed">
        <div class="stat-icon error">
          <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div class="stat-content">
          <span class="stat-label">Failed</span>
          <span class="stat-value error">{{ formatNumber(queueStats.summary?.failed || 0) }}</span>
          <span class="stat-percentage">{{ queueStats.summary?.failure_rate || 0 }}%</span>
        </div>
      </div>
    </div>

    <!-- Queue Breakdown -->
    <div class="queue-breakdown">
      <div class="breakdown-section">
        <h4>Pending by Queue</h4>
        <div class="queue-list" v-if="hasPendingJobs">
          <div v-for="(count, queue) in queueStats.pending_by_queue" :key="`pending-${queue}`" class="queue-item">
            <span class="queue-name">{{ queue }}</span>
            <span class="queue-count pending-badge">{{ count }}</span>
          </div>
        </div>
        <div v-else class="empty-state">
          <span class="empty-icon">✅</span>
          <span>No pending jobs</span>
        </div>
      </div>

      <div class="breakdown-section">
        <h4>Failed by Queue</h4>
        <div class="queue-list" v-if="hasFailedJobs">
          <div v-for="(count, queue) in queueStats.failed_by_queue" :key="`failed-${queue}`" class="queue-item">
            <span class="queue-name">{{ queue }}</span>
            <span class="queue-count failed-badge">{{ count }}</span>
          </div>
        </div>
        <div v-else class="empty-state">
          <span class="empty-icon">✅</span>
          <span>No failed jobs</span>
        </div>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="recent-activity">
      <h4>Recent Activity</h4>
      <div class="activity-grid">
        <div class="activity-card">
          <div class="activity-header">
            <span class="activity-period">Last 24 Hours</span>
          </div>
          <div class="activity-stats">
            <div class="activity-stat">
              <span class="activity-label">Processed:</span>
              <span class="activity-value success">{{ formatNumber(queueStats.recent_activity?.last_24_hours?.processed || 0) }}</span>
            </div>
            <div class="activity-stat">
              <span class="activity-label">Failed:</span>
              <span class="activity-value error">{{ formatNumber(queueStats.recent_activity?.last_24_hours?.failed || 0) }}</span>
            </div>
          </div>
        </div>

        <div class="activity-card">
          <div class="activity-header">
            <span class="activity-period">Last 7 Days</span>
          </div>
          <div class="activity-stats">
            <div class="activity-stat">
              <span class="activity-label">Processed:</span>
              <span class="activity-value success">{{ formatNumber(queueStats.recent_activity?.last_7_days?.processed || 0) }}</span>
            </div>
            <div class="activity-stat">
              <span class="activity-label">Failed:</span>
              <span class="activity-value error">{{ formatNumber(queueStats.recent_activity?.last_7_days?.failed || 0) }}</span>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Worker Status -->
    <div class="worker-status">
      <div class="worker-header">
        <h4>Queue Worker</h4>
        <div class="worker-badge" :class="queueStats?.worker_status?.running ? 'running' : 'stopped'">
          <span class="status-dot"></span>
          <span>{{ queueStats?.worker_status?.status || 'unknown' }}</span>
        </div>
      </div>
      <div v-if="!queueStats?.worker_status?.running" class="worker-warning">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
        <span>Worker not running. Start with: <code>php artisan queue:work</code></span>
      </div>
    </div>

    <!-- Footer -->
    <div class="widget-footer">
      <div class="footer-info">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <span>Tracking since: {{ formatDate(queueStats.tracking_since) }}</span>
      </div>
      <button @click="refreshStats" class="refresh-btn" :disabled="loading">
        <svg class="w-4 h-4" :class="{ 'spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
        <span>Refresh</span>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const queueStats = ref({
  summary: {
    total_jobs: 0,
    processed: 0,
    pending: 0,
    failed: 0,
    success_rate: 0,
    failure_rate: 0,
  },
  pending_by_queue: {},
  failed_by_queue: {},
  recent_activity: {
    last_24_hours: { processed: 0, failed: 0 },
    last_7_days: { processed: 0, failed: 0 },
  },
  worker_status: {
    running: false,
    status: 'unknown',
  },
  health_status: 'healthy',
  tracking_since: new Date().toISOString(),
});

const loading = ref(false);
let refreshInterval = null;

const hasPendingJobs = computed(() => {
  return Object.keys(queueStats.value.pending_by_queue || {}).length > 0;
});

const hasFailedJobs = computed(() => {
  return Object.keys(queueStats.value.failed_by_queue || {}).length > 0;
});

const fetchQueueStats = async () => {
  try {
    loading.value = true;
    const response = await axios.get('/queue/stats');
    if (response && response.data && response.data.status === 'success') {
      queueStats.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to fetch queue stats:', error);
    // Set default values on error
    queueStats.value = {
      summary: {
        total_jobs: 0,
        processed: 0,
        pending: 0,
        failed: 0,
        success_rate: 0,
        failure_rate: 0,
      },
      pending_by_queue: {},
      failed_by_queue: {},
      recent_activity: {
        last_24_hours: { processed: 0, failed: 0 },
        last_7_days: { processed: 0, failed: 0 },
      },
      worker_status: {
        running: false,
        status: 'unknown',
      },
      health_status: 'unknown',
      tracking_since: new Date().toISOString(),
    };
  } finally {
    loading.value = false;
  }
};

const refreshStats = () => {
  fetchQueueStats();
};

const formatNumber = (num) => {
  return new Intl.NumberFormat().format(num);
};

const formatDate = (dateString) => {
  if (!dateString) return 'N/A';
  const date = new Date(dateString);
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
};

onMounted(() => {
  fetchQueueStats();
  // Refresh every 30 seconds
  refreshInterval = setInterval(fetchQueueStats, 30000);
});

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
  }
});
</script>

<style scoped>
.queue-stats-widget {
  background: white;
  border-radius: 12px;
  padding: 24px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}

.widget-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 24px;
}

.header-left {
  display: flex;
  gap: 16px;
  align-items: center;
}

.icon-wrapper {
  width: 48px;
  height: 48px;
  border-radius: 12px;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.widget-header h3 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #1e293b;
}

.subtitle {
  margin: 4px 0 0 0;
  font-size: 13px;
  color: #64748b;
}

.health-badge {
  padding: 6px 16px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.badge-healthy {
  background: #dcfce7;
  color: #166534;
}

.badge-warning {
  background: #fef3c7;
  color: #92400e;
}

.badge-critical {
  background: #fee2e2;
  color: #991b1b;
}

.stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.stat-card {
  background: #f8fafc;
  border-radius: 12px;
  padding: 20px;
  display: flex;
  gap: 16px;
  align-items: center;
  border: 2px solid transparent;
  transition: all 0.3s ease;
}

.stat-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}

.stat-card.total {
  border-color: #e2e8f0;
}

.stat-card.processed {
  border-color: #d1fae5;
}

.stat-card.pending {
  border-color: #fef3c7;
}

.stat-card.failed {
  border-color: #fee2e2;
}

.stat-icon {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  background: #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.stat-icon.success {
  background: #d1fae5;
  color: #059669;
}

.stat-icon.warning {
  background: #fef3c7;
  color: #d97706;
}

.stat-icon.error {
  background: #fee2e2;
  color: #dc2626;
}

.stat-content {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.stat-value {
  font-size: 28px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1;
}

.stat-value.success {
  color: #059669;
}

.stat-value.warning {
  color: #d97706;
}

.stat-value.error {
  color: #dc2626;
}

.stat-percentage {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}

.queue-breakdown {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}

.breakdown-section h4 {
  margin: 0 0 12px 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.queue-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.queue-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}

.queue-name {
  font-size: 13px;
  font-weight: 600;
  color: #475569;
}

.queue-count {
  padding: 4px 12px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 700;
}

.pending-badge {
  background: #fef3c7;
  color: #92400e;
}

.failed-badge {
  background: #fee2e2;
  color: #991b1b;
}

.empty-state {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 16px;
  background: #f8fafc;
  border-radius: 8px;
  color: #64748b;
  font-size: 13px;
}

.empty-icon {
  font-size: 20px;
}

.recent-activity {
  margin-bottom: 24px;
}

.recent-activity h4 {
  margin: 0 0 12px 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.activity-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
}

.activity-card {
  background: #f8fafc;
  border-radius: 10px;
  padding: 16px;
  border: 1px solid #e2e8f0;
}

.activity-header {
  margin-bottom: 12px;
}

.activity-period {
  font-size: 13px;
  font-weight: 700;
  color: #475569;
}

.activity-stats {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.activity-stat {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.activity-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}

.activity-value {
  font-size: 16px;
  font-weight: 700;
}

.worker-status {
  background: #f8fafc;
  border-radius: 10px;
  padding: 16px;
  margin-bottom: 20px;
  border: 1px solid #e2e8f0;
}

.worker-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.worker-header h4 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.worker-badge {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 12px;
  border-radius: 16px;
  font-size: 12px;
  font-weight: 600;
  text-transform: uppercase;
}

.worker-badge.running {
  background: #d1fae5;
  color: #166534;
}

.worker-badge.stopped {
  background: #fee2e2;
  color: #991b1b;
}

.status-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: currentColor;
  animation: pulse 2s infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}

.worker-warning {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px;
  background: #fef3c7;
  border-radius: 8px;
  color: #92400e;
  font-size: 13px;
}

.worker-warning code {
  background: #fde68a;
  padding: 2px 6px;
  border-radius: 4px;
  font-family: monospace;
  font-size: 12px;
}

.widget-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 16px;
  border-top: 2px solid #e2e8f0;
}

.footer-info {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
  color: #64748b;
}

.refresh-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s;
}

.refresh-btn:hover:not(:disabled) {
  background: #e2e8f0;
  border-color: #cbd5e1;
}

.refresh-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.spin {
  animation: spin 1s linear infinite;
}

@keyframes spin {
  from { transform: rotate(0deg); }
  to { transform: rotate(360deg); }
}
</style>
