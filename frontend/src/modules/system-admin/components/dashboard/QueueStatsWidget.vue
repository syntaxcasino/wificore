<template>
  <div class="bg-white rounded-2xl shadow-lg border border-gray-100 p-6">
    <div class="flex items-center justify-between mb-6">
      <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
        <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
        </svg>
        Queue Statistics
      </h2>
      <button @click="refreshStats" :disabled="loading" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
        <svg class="w-5 h-5 text-gray-600" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="loading && !queueStats" class="flex items-center justify-center py-12">
      <div class="w-12 h-12 border-4 border-purple-200 border-t-purple-600 rounded-full animate-spin"></div>
    </div>

    <!-- Queue Stats Grid -->
    <div v-else class="space-y-4">
      <!-- Pending Jobs -->
      <div class="p-4 bg-gradient-to-r from-blue-50 to-blue-100 rounded-xl border border-blue-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-blue-700 mb-1">Pending Jobs</p>
            <p class="text-3xl font-bold text-blue-900">{{ queueStats.pending || 0 }}</p>
          </div>
          <div class="w-14 h-14 bg-blue-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <div class="mt-3 flex items-center text-xs text-blue-600">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
          </svg>
          <span>Jobs waiting to be processed</span>
        </div>
      </div>

      <!-- Processing Jobs -->
      <div class="p-4 bg-gradient-to-r from-green-50 to-emerald-100 rounded-xl border border-green-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-green-700 mb-1">Processing</p>
            <p class="text-3xl font-bold text-green-900">{{ queueStats.processing || 0 }}</p>
          </div>
          <div class="w-14 h-14 bg-green-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-8 h-8 text-white animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </div>
        </div>
        <div class="mt-3 flex items-center text-xs text-green-600">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <span>Currently being executed</span>
        </div>
      </div>

      <!-- Failed Jobs -->
      <div class="p-4 bg-gradient-to-r from-red-50 to-red-100 rounded-xl border border-red-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-red-700 mb-1">Failed Jobs</p>
            <p class="text-3xl font-bold text-red-900">{{ queueStats.failed || 0 }}</p>
          </div>
          <div class="w-14 h-14 bg-red-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <div class="mt-3 flex items-center justify-between">
          <span class="text-xs text-red-600 flex items-center">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            Requires attention
          </span>
          <button 
            v-if="queueStats.failed > 0"
            @click="retryFailedJobs"
            class="text-xs bg-red-600 text-white px-3 py-1 rounded-lg hover:bg-red-700 transition-colors"
          >
            Retry All
          </button>
        </div>
      </div>

      <!-- Completed Jobs (Last Hour) -->
      <div class="p-4 bg-gradient-to-r from-purple-50 to-purple-100 rounded-xl border border-purple-200">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-purple-700 mb-1">Completed (Last Hour)</p>
            <p class="text-3xl font-bold text-purple-900">{{ queueStats.completed || 0 }}</p>
          </div>
          <div class="w-14 h-14 bg-purple-500 rounded-xl flex items-center justify-center shadow-lg">
            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
        <div class="mt-3 flex items-center text-xs text-purple-600">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
          </svg>
          <span>Successfully processed jobs</span>
        </div>
      </div>

      <!-- Queue Workers -->
      <div class="mt-6 p-4 bg-gray-50 rounded-xl border border-gray-200 relative group">
        <div class="flex items-center justify-between mb-3">
          <h3 class="text-sm font-semibold text-gray-700">Active Workers</h3>
          <span class="px-2 py-1 text-xs font-semibold rounded-full" :class="queueStats.workers > 0 ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
            {{ queueStats.workers || 0 }} Running
          </span>
        </div>
        
        <!-- Tooltip on hover - Full Worker List -->
        <div class="absolute hidden group-hover:block top-0 right-0 mt-12 mr-2 bg-gray-900 text-white text-xs rounded-lg p-4 shadow-2xl z-50 w-80 max-h-96 overflow-y-auto">
          <div class="sticky top-0 bg-gray-900 pb-2 mb-2 border-b border-gray-700">
            <p class="font-bold text-sm text-white">Active Queue Workers</p>
            <p class="text-gray-400 text-xs mt-1">Total: {{ queueStats.workers || 0 }} workers across {{ workerQueueCount }} queues</p>
          </div>
          
          <div v-if="hasWorkersByQueue" class="space-y-1">
            <div 
              v-for="(count, queue) in workersByQueueObject" 
              :key="queue"
              class="flex items-center justify-between py-1.5 px-2 bg-gray-800 rounded hover:bg-gray-700 transition-colors"
            >
              <span class="text-gray-200 font-medium">{{ formatQueueName(queue) }}</span>
              <span class="px-2 py-0.5 bg-blue-600 text-white font-bold rounded text-xs">{{ count }}</span>
            </div>
          </div>
          
          <div v-else class="text-gray-400 text-center py-2">
            No worker breakdown available
          </div>
          
          <div class="mt-3 pt-2 border-t border-gray-700 text-gray-400 text-xs">
            <p>✓ Auto-refreshes every 10 seconds</p>
            <p class="mt-1">✓ Data updated every minute</p>
          </div>
        </div>
        
        <!-- Show all workers by queue if available -->
        <div v-if="hasWorkersByQueue" class="space-y-2">
          <!-- <div 
            v-for="(count, queue) in workersByQueueObject" 
            :key="queue"
            class="flex items-center justify-between py-2 px-3 bg-white rounded-lg border border-gray-200 hover:border-blue-300 hover:shadow-sm transition-all cursor-pointer group/item"
            :title="`${formatQueueName(queue)}: ${count} worker(s) running`"
          >
            <span class="text-xs font-medium text-gray-700">{{ formatQueueName(queue) }}</span>
            <span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-bold rounded group-hover/item:bg-blue-200">{{ count }}</span>
          </div> -->
        </div>
        
        <!-- Show message when no workers detected -->
        <div v-else class="text-center py-4">
          <div v-if="queueStats.workers > 0" class="flex items-center justify-center gap-2 text-green-600">
            <svg class="w-5 h-5 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <p class="text-sm font-medium">{{ queueStats.workers }} worker(s) active</p>
            <p class="text-xs text-gray-500 mt-1">(Queue breakdown unavailable)</p>
          </div>
          <div v-else class="text-gray-500">
            <svg class="w-8 h-8 text-gray-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <p class="text-sm">No active queue workers detected</p>
            <p class="text-xs text-gray-400 mt-1">Workers may be starting up or stopped</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import axios from 'axios'

const api = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api',
  withCredentials: true,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  }
})

// Add auth token to requests
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('authToken')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

const loading = ref(false)
const queueStats = ref({
  pending: 0,
  processing: 0,
  failed: 0,
  completed: 0,
  workers: 0,
  workersByQueue: {}
})

const workersByQueueObject = computed(() => {
  const workers = queueStats.value.workersByQueue
  // Handle if backend returns array instead of object
  if (Array.isArray(workers)) {
    return {}
  }
  return workers || {}
})

const hasWorkersByQueue = computed(() => {
  const obj = workersByQueueObject.value
  return obj && typeof obj === 'object' && Object.keys(obj).length > 0
})

const workerQueueCount = computed(() => {
  return Object.keys(workersByQueueObject.value).length
})

let refreshInterval = null

const fetchQueueStats = async (showLoading = false) => {
  try {
    // Only show loading on initial load to prevent visual jank
    if (showLoading) {
      loading.value = true
    }
    
    const response = await api.get('/system/queue/stats')
    if (response.data) {
      // Use requestAnimationFrame for smooth DOM updates
      requestAnimationFrame(() => {
        queueStats.value = response.data
      })
    }
  } catch (error) {
    console.error('Failed to fetch queue stats:', error)
    // Keep existing data or show zeros on error
    if (!queueStats.value.pending && !queueStats.value.workers) {
      queueStats.value = {
        pending: 0,
        processing: 0,
        failed: 0,
        completed: 0,
        workers: 0,
        workersByQueue: {}
      }
    }
  } finally {
    if (showLoading) {
      loading.value = false
    }
  }
}

const refreshStats = () => {
  fetchQueueStats()
}

const retryFailedJobs = async () => {
  try {
    await api.post('/system/queue/retry-failed')
    await fetchQueueStats()
  } catch (error) {
    console.error('Failed to retry jobs:', error)
  }
}

const formatQueueName = (queue) => {
  // Convert kebab-case to Title Case
  return queue
    .split('-')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

onMounted(() => {
  fetchQueueStats(true) // Show loading on initial load
  // Refresh every 10 seconds in background (no loading spinner)
  refreshInterval = setInterval(() => fetchQueueStats(false), 10000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})
</script>
