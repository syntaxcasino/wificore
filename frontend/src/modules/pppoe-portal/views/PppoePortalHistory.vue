<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-16">
          <div class="flex items-center gap-3">
            <button
              @click="$router.push('/portal/dashboard')"
              class="p-2 text-gray-500 hover:text-gray-700 transition-colors"
            >
              <i class="fas fa-arrow-left"></i>
            </button>
            <div class="w-10 h-10 bg-indigo-600 rounded-lg flex items-center justify-center">
              <i class="fas fa-wifi text-white text-lg"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-900">Session History</h1>
          </div>
          
          <button
            @click="handleLogout"
            class="p-2 text-gray-500 hover:text-red-600 transition-colors"
            title="Logout"
          >
            <i class="fas fa-sign-out-alt text-lg"></i>
          </button>
        </div>
      </div>
    </header>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Filters -->
      <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
        <div class="flex flex-wrap items-center gap-4">
          <div class="flex items-center gap-2">
            <i class="fas fa-calendar-alt text-gray-400"></i>
            <span class="text-sm font-medium text-gray-700">Period:</span>
            <select
              v-model="selectedDays"
              @change="loadHistory"
              class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
              <option :value="7">Last 7 days</option>
              <option :value="30">Last 30 days</option>
              <option :value="60">Last 60 days</option>
              <option :value="90">Last 90 days</option>
            </select>
          </div>
          
          <div v-if="historyData" class="ml-auto flex items-center gap-4 text-sm text-gray-600">
            <span><strong>{{ historyData.total_sessions }}</strong> sessions</span>
            <span><strong>{{ totalDuration }}</strong> total time</span>
            <span><strong>{{ totalData }}</strong> total data</span>
          </div>
        </div>
      </div>

      <!-- Loading State -->
      <div v-if="isLoading" class="flex items-center justify-center h-64">
        <div class="text-center">
          <i class="fas fa-spinner fa-spin text-3xl text-indigo-600 mb-4"></i>
          <p class="text-gray-600">Loading session history...</p>
        </div>
      </div>

      <!-- Sessions Table -->
      <div v-else-if="historyData?.sessions?.length" class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Date & Time</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Duration</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Download</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Upload</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Total</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">IP Address</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
              <tr
                v-for="session in historyData.sessions"
                :key="session.id"
                class="hover:bg-gray-50 transition-colors"
              >
                <td class="px-6 py-4">
                  <div class="font-medium text-gray-900">
                    {{ formatDate(session.start_time) }}
                  </div>
                  <div class="text-sm text-gray-500">
                    {{ formatTime(session.start_time) }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="font-medium text-gray-900">{{ session.duration_formatted }}</span>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <i class="fas fa-arrow-down text-green-500 text-xs"></i>
                    <span class="text-gray-900">{{ session.download_formatted }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-2">
                    <i class="fas fa-arrow-up text-blue-500 text-xs"></i>
                    <span class="text-gray-900">{{ session.upload_formatted }}</span>
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="font-semibold text-gray-900">{{ session.total_formatted }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-600 font-mono">
                  {{ session.ip_address }}
                </td>
                <td class="px-6 py-4">
                  <span
                    :class="[
                      'px-2 py-1 text-xs font-medium rounded-full',
                      session.status === 'active' 
                        ? 'bg-green-100 text-green-700' 
                        : 'bg-gray-100 text-gray-600'
                    ]"
                  >
                    {{ session.status === 'active' ? 'Active' : 'Completed' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Empty State -->
      <div v-else class="bg-white rounded-xl shadow-sm p-12 text-center">
        <i class="fas fa-history text-6xl text-gray-200 mb-4"></i>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No sessions found</h3>
        <p class="text-gray-500">No internet sessions were recorded during this period.</p>
      </div>
    </main>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { usePppoePortal } from '../composables/usePppoePortal.js';

const { isLoading, logout, fetchSessionHistory } = usePppoePortal();

const historyData = ref(null);
const selectedDays = ref(30);

const totalDuration = computed(() => {
  if (!historyData.value?.sessions?.length) return '0s';
  const total = historyData.value.sessions.reduce((sum, s) => sum + (s.duration_seconds || 0), 0);
  return formatDuration(total);
});

const totalData = computed(() => {
  if (!historyData.value?.sessions?.length) return '0 B';
  const total = historyData.value.sessions.reduce((sum, s) => sum + (s.download_bytes || 0) + (s.upload_bytes || 0), 0);
  return formatBytes(total);
});

async function loadHistory() {
  try {
    historyData.value = await fetchSessionHistory(selectedDays.value);
  } catch (err) {
    console.error('Failed to load session history:', err);
  }
}

function handleLogout() {
  logout();
}

function formatDate(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleDateString('en-KE', {
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  });
}

function formatTime(dateStr) {
  if (!dateStr) return '--';
  return new Date(dateStr).toLocaleTimeString('en-KE', {
    hour: '2-digit',
    minute: '2-digit',
  });
}

function formatDuration(seconds) {
  if (!seconds) return '0s';
  const hours = Math.floor(seconds / 3600);
  const mins = Math.floor((seconds % 3600) / 60);
  const secs = seconds % 60;
  const parts = [];
  if (hours) parts.push(`${hours}h`);
  if (mins) parts.push(`${mins}m`);
  if (secs || !parts.length) parts.push(`${secs}s`);
  return parts.join(' ');
}

function formatBytes(bytes) {
  if (!bytes) return '0 B';
  const units = ['B', 'KB', 'MB', 'GB', 'TB'];
  let unitIndex = 0;
  while (bytes >= 1024 && unitIndex < units.length - 1) {
    bytes /= 1024;
    unitIndex++;
  }
  return `${bytes.toFixed(2)} ${units[unitIndex]}`;
}

onMounted(() => {
  loadHistory();
});
</script>
