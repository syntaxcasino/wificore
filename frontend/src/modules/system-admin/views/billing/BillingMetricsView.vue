<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Billing Metrics</h1>
      <p class="text-sm text-gray-500 mt-1">Aggregate billing and subscription metrics across all tenants</p>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading metrics...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchAll" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Aggregate Metrics -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
        <div v-for="(value, key) in metrics" :key="key" class="bg-white rounded-xl border border-gray-200 p-4">
          <div class="text-sm text-gray-500 capitalize">{{ formatLabel(key) }}</div>
          <div class="text-2xl font-bold text-gray-900 mt-1">{{ formatValue(value) }}</div>
        </div>
      </div>

      <!-- Tenant Counts -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Tenant Counts</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div v-for="(value, key) in tenantCounts" :key="key" class="p-4 bg-gray-50 rounded-lg">
            <div class="text-sm text-gray-500 capitalize">{{ formatLabel(key) }}</div>
            <div class="text-xl font-bold text-gray-900 mt-1">{{ value }}</div>
          </div>
        </div>
      </div>

      <!-- Expiring Soon -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Expiring Soon</h2>
        <div v-if="expiring.length === 0" class="text-sm text-gray-400">No tenants expiring soon</div>
        <div v-else class="space-y-2">
          <div v-for="tenant in expiring" :key="tenant.id" class="flex items-center justify-between p-3 bg-yellow-50 border border-yellow-200 rounded-lg">
            <div>
              <div class="text-sm font-medium text-gray-900">{{ tenant.name }}</div>
              <div class="text-xs text-gray-500">{{ tenant.slug }}</div>
            </div>
            <div class="text-sm text-yellow-700 font-medium">
              Expires: {{ tenant.subscription_ends_at ? new Date(tenant.subscription_ends_at).toLocaleDateString() : 'N/A' }}
            </div>
          </div>
        </div>
      </div>

      <!-- Suspended Tenants -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Suspended Tenants</h2>
        <div v-if="suspended.length === 0" class="text-sm text-gray-400">No suspended tenants</div>
        <div v-else class="space-y-2">
          <div v-for="tenant in suspended" :key="tenant.id" class="flex items-center justify-between p-3 bg-red-50 border border-red-200 rounded-lg">
            <div>
              <div class="text-sm font-medium text-gray-900">{{ tenant.name }}</div>
              <div class="text-xs text-gray-500">Suspended: {{ tenant.suspended_at ? new Date(tenant.suspended_at).toLocaleDateString() : '' }}</div>
            </div>
            <button
              @click="reactivate(tenant)"
              class="px-3 py-1 text-xs bg-green-600 text-white rounded-md hover:bg-green-700"
            >
              Reactivate
            </button>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const metrics = ref({})
const tenantCounts = ref({})
const expiring = ref([])
const suspended = ref([])
const loading = ref(true)
const error = ref(null)

const formatLabel = (key) => key.replace(/_/g, ' ')
const formatValue = (val) => {
  if (typeof val === 'number') return val.toLocaleString()
  return val
}

const fetchAll = async () => {
  try {
    loading.value = true
    error.value = null
    const [metricsRes, countsRes, expiringRes, suspendedRes] = await Promise.all([
      axios.get('/system/landlord/metrics'),
      axios.get('/system/landlord/tenant-counts'),
      axios.get('/system/landlord/expiring'),
      axios.get('/system/landlord/suspended'),
    ])
    metrics.value = metricsRes.data.data || metricsRes.data.metrics || {}
    tenantCounts.value = countsRes.data.data || countsRes.data.counts || {}
    expiring.value = expiringRes.data.data || expiringRes.data.tenants || []
    suspended.value = suspendedRes.data.data || suspendedRes.data.tenants || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load billing metrics'
  } finally {
    loading.value = false
  }
}

const reactivate = async (tenant) => {
  try {
    await axios.post(`/system/landlord/tenants/${tenant.id}/reactivate`)
    await fetchAll()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to reactivate tenant')
  }
}

onMounted(() => fetchAll())
</script>
