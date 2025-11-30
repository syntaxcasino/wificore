<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-white shadow">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
        <div class="flex justify-between items-center">
          <div>
            <h1 class="text-3xl font-bold text-gray-900">System Dashboard</h1>
            <p class="mt-1 text-sm text-gray-600">Welcome back, {{ user?.name }}</p>
          </div>
          <button
            @click="logout"
            class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition"
          >
            Logout
          </button>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
      <!-- Stats Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
        <!-- Total Tenants -->
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0 bg-blue-500 rounded-md p-3">
              <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
              </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Total Tenants</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ stats.totalTenants || 0 }}</dd>
              </dl>
            </div>
          </div>
        </div>

        <!-- Active Tenants -->
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0 bg-green-500 rounded-md p-3">
              <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Active Tenants</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ stats.activeTenants || 0 }}</dd>
              </dl>
            </div>
          </div>
        </div>

        <!-- Total Users -->
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0 bg-purple-500 rounded-md p-3">
              <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
              </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">Total Users</dt>
                <dd class="text-2xl font-semibold text-gray-900">{{ stats.totalUsers || 0 }}</dd>
              </dl>
            </div>
          </div>
        </div>

        <!-- System Health -->
        <div class="bg-white rounded-lg shadow p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0 bg-yellow-500 rounded-md p-3">
              <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
            </div>
            <div class="ml-5 w-0 flex-1">
              <dl>
                <dt class="text-sm font-medium text-gray-500 truncate">System Status</dt>
                <dd class="text-2xl font-semibold text-green-600">Healthy</dd>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Activity -->
      <div class="bg-white rounded-lg shadow">
        <div class="px-6 py-4 border-b border-gray-200">
          <h2 class="text-lg font-semibold text-gray-900">System Overview</h2>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <div class="flex items-center justify-between py-3 border-b border-gray-100">
              <div>
                <p class="text-sm font-medium text-gray-900">Authentication Method</p>
                <p class="text-sm text-gray-500">FreeRADIUS AAA</p>
              </div>
              <span class="px-3 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full">Active</span>
            </div>
            <div class="flex items-center justify-between py-3 border-b border-gray-100">
              <div>
                <p class="text-sm font-medium text-gray-900">Database</p>
                <p class="text-sm text-gray-500">PostgreSQL</p>
              </div>
              <span class="px-3 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full">Connected</span>
            </div>
            <div class="flex items-center justify-between py-3 border-b border-gray-100">
              <div>
                <p class="text-sm font-medium text-gray-900">RADIUS Server</p>
                <p class="text-sm text-gray-500">FreeRADIUS</p>
              </div>
              <span class="px-3 py-1 text-xs font-semibold text-green-800 bg-green-100 rounded-full">Running</span>
            </div>
            <div class="flex items-center justify-between py-3">
              <div>
                <p class="text-sm font-medium text-gray-900">Your Role</p>
                <p class="text-sm text-gray-500">System Administrator</p>
              </div>
              <span class="px-3 py-1 text-xs font-semibold text-blue-800 bg-blue-100 rounded-full">Full Access</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="mt-8 grid grid-cols-1 md:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Manage Tenants</h3>
          <p class="text-sm text-gray-600">View and manage all tenant organizations</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">System Settings</h3>
          <p class="text-sm text-gray-600">Configure system-wide settings</p>
        </div>
        <div class="bg-white rounded-lg shadow p-6 hover:shadow-lg transition cursor-pointer">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">View Logs</h3>
          <p class="text-sm text-gray-600">Monitor system activity and logs</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const user = ref(null)
const stats = ref({
  totalTenants: 0,
  activeTenants: 0,
  totalUsers: 1, // At least the sysadmin
})

onMounted(async () => {
  user.value = authStore.user
  
  // TODO: Fetch actual stats from API
  // For now, showing placeholder data
  stats.value = {
    totalTenants: 0,
    activeTenants: 0,
    totalUsers: 1,
  }
})

const logout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>
