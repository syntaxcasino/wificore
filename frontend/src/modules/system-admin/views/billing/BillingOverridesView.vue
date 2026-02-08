<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Billing Overrides</h1>
      <p class="text-sm text-gray-500 mt-1">Tenants with custom billing rates overriding the default configuration</p>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading overrides...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchOverrides" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <table class="w-full">
          <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
              <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Override Details</th>
              <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="item in overrides" :key="item.id" class="hover:bg-gray-50 transition-colors">
              <td class="px-4 py-3">
                <div class="font-medium text-gray-900 text-sm">{{ item.name || item.tenant?.name || 'Unknown' }}</div>
                <div class="text-xs text-gray-500">{{ item.slug || item.tenant?.slug || '' }}</div>
              </td>
              <td class="px-4 py-3 text-sm text-gray-600">
                <pre class="text-xs bg-gray-50 p-2 rounded">{{ JSON.stringify(item.billing_override || item.override || item, null, 2) }}</pre>
              </td>
              <td class="px-4 py-3 text-right">
                <button
                  @click="removeOverride(item)"
                  class="px-3 py-1 text-xs text-red-600 border border-red-300 rounded-md hover:bg-red-50"
                >
                  Remove Override
                </button>
              </td>
            </tr>
            <tr v-if="overrides.length === 0">
              <td colspan="3" class="px-4 py-8 text-center text-gray-400 text-sm">No billing overrides configured</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const overrides = ref([])
const loading = ref(true)
const error = ref(null)

const fetchOverrides = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/landlord/overrides')
    overrides.value = res.data.data || res.data.tenants || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load overrides'
  } finally {
    loading.value = false
  }
}

const removeOverride = async (item) => {
  const tenantId = item.id || item.tenant_id
  if (!confirm('Remove billing override for this tenant? They will revert to default rates.')) return
  try {
    await axios.delete(`/system/landlord/tenants/${tenantId}/override`)
    await fetchOverrides()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to remove override')
  }
}

onMounted(() => fetchOverrides())
</script>
