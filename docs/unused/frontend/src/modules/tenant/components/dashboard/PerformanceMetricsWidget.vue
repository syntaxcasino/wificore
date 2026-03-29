<template>
  <div class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
    <!-- Header -->
    <div class="px-6 py-4 border-b border-gray-200 bg-gradient-to-r from-purple-50 to-indigo-50">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 bg-gradient-to-br from-purple-600 to-indigo-600 rounded-lg flex items-center justify-center">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <div>
            <h3 class="text-lg font-bold text-gray-900">Performance Metrics</h3>
            <p class="text-xs text-gray-600">Real-time system performance</p>
          </div>
        </div>
        <button 
          @click="refreshMetrics" 
          :disabled="loading"
          class="p-2 hover:bg-white/50 rounded-lg transition-colors"
          :class="{ 'animate-spin': loading }"
        >
          <svg class="w-5 h-5 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Metrics Grid -->
    <div class="p-6">
      <div v-if="loading && !metrics" class="flex items-center justify-center py-12">
        <div class="text-center">
          <div class="w-12 h-12 border-4 border-purple-200 border-t-purple-600 rounded-full animate-spin mx-auto mb-3"></div>
          <p class="text-sm text-gray-600">Loading metrics...</p>
        </div>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <!-- TPS Metric -->
        <div class="p-4 rounded-lg bg-gradient-to-br from-blue-50 to-blue-100/50 border border-blue-200">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-blue-900">TPS</span>
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
            </svg>
          </div>
          <div class="text-2xl font-bold text-blue-900">
            {{ metrics?.tps?.display || '0.00 (Avg: 0.00)' }}
          </div>
          <p class="text-xs text-blue-700 mt-1">Transactions Per Second</p>
        </div>

        <!-- OPS Metric -->
        <div class="p-4 rounded-lg bg-gradient-to-br from-green-50 to-green-100/50 border border-green-200">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-green-900">OPS</span>
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z" />
            </svg>
          </div>
          <div class="text-2xl font-bold text-green-900">
            {{ formatNumber(metrics?.ops?.current) || '0.00' }}
          </div>
          <p class="text-xs text-green-700 mt-1">Operations Per Second</p>
        </div>

        <!-- DB Connections -->
        <div class="p-4 rounded-lg bg-gradient-to-br from-purple-50 to-purple-100/50 border border-purple-200">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-purple-900">DB Connections</span>
            <svg class="w-5 h-5 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
          </div>
          <div class="text-2xl font-bold text-purple-900">
            {{ metrics?.db_connections?.display || '0/200' }}
          </div>
          <div class="mt-2">
            <div class="w-full bg-purple-200 rounded-full h-2">
              <div 
                class="bg-gradient-to-r from-purple-600 to-purple-500 h-2 rounded-full transition-all duration-500"
                :style="{ width: connectionPercentage + '%' }"
              ></div>
            </div>
            <p class="text-xs text-purple-700 mt-1">{{ connectionPercentage }}% utilized</p>
          </div>
        </div>

        <!-- Slow Queries -->
        <div class="p-4 rounded-lg bg-gradient-to-br from-orange-50 to-orange-100/50 border border-orange-200">
          <div class="flex items-center justify-between mb-2">
            <span class="text-sm font-semibold text-orange-900">Slow Queries</span>
            <svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
          <div class="text-2xl font-bold" :class="slowQueriesClass">
            {{ metrics?.slow_queries?.display || '0' }}
          </div>
          <p class="text-xs text-orange-700 mt-1">
            {{ slowQueriesStatus }}
          </p>
        </div>
      </div>

      <!-- Timestamp -->
      <div v-if="metrics?.timestamp" class="mt-4 pt-4 border-t border-gray-200">
        <div class="flex items-center justify-between text-xs text-gray-600">
          <span>Last updated: {{ formatTimestamp(metrics.timestamp) }}</span>
          <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full font-semibold">Live</span>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const metrics = ref(null);
const loading = ref(false);
let refreshInterval = null;

// Computed properties
const connectionPercentage = computed(() => {
  if (!metrics.value?.db_connections) return 0;
  const current = metrics.value.db_connections.current || 0;
  const max = metrics.value.db_connections.max || 200;
  return Math.round((current / max) * 100);
});

const slowQueriesClass = computed(() => {
  const count = metrics.value?.slow_queries?.count || 0;
  if (count === 0) return 'text-green-900';
  if (count < 5) return 'text-orange-900';
  return 'text-red-900';
});

const slowQueriesStatus = computed(() => {
  const count = metrics.value?.slow_queries?.count || 0;
  if (count === 0) return 'Excellent performance';
  if (count < 5) return 'Minor optimization needed';
  return 'Requires attention';
});

// Methods
const fetchMetrics = async () => {
  try {
    loading.value = true;
    const response = await axios.get('/metrics/layout');
    if (response.data.success) {
      metrics.value = response.data.data;
    }
  } catch (error) {
    console.error('Failed to fetch performance metrics:', error);
  } finally {
    loading.value = false;
  }
};

const refreshMetrics = () => {
  fetchMetrics();
};

const formatNumber = (value) => {
  if (!value) return '0.00';
  return parseFloat(value).toLocaleString('en-US', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
};

const formatTimestamp = (timestamp) => {
  if (!timestamp) return '';
  const date = new Date(timestamp);
  return date.toLocaleTimeString('en-US', {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  });
};

// Lifecycle
onMounted(() => {
  fetchMetrics();
  // Refresh every 30 seconds
  refreshInterval = setInterval(fetchMetrics, 30000);
});

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval);
  }
});
</script>
