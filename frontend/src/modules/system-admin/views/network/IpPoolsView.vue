<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Tenant IP Pools</h1>
        <p class="text-sm text-gray-500 mt-1">Manage IP address pool allocations across tenants</p>
      </div>
      <button
        @click="showCreateModal = true"
        class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 transition-colors"
      >
        <Plus class="w-4 h-4" />
        Add Pool
      </button>
    </div>

    <!-- Stats -->
    <div v-if="poolStats" class="grid grid-cols-1 md:grid-cols-4 gap-4">
      <div v-for="(value, key) in poolStats" :key="key" class="bg-white rounded-xl border border-gray-200 p-4">
        <div class="text-sm text-gray-500 capitalize">{{ key.replace(/_/g, ' ') }}</div>
        <div class="text-2xl font-bold text-gray-900 mt-1">{{ value }}</div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-gray-500">
        <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
        Loading IP pools...
      </div>
      <div v-else-if="error" class="p-8 text-center text-red-500">
        {{ error }}
        <button @click="fetchPools" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
      </div>
      <table v-else class="w-full">
        <thead class="bg-gray-50 border-b border-gray-200">
          <tr>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Pool Name</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Network</th>
            <th class="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase">Tenant</th>
            <th class="text-center px-4 py-3 text-xs font-medium text-gray-500 uppercase">Used / Total</th>
            <th class="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase">Actions</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="pool in pools" :key="pool.id" class="hover:bg-gray-50 transition-colors">
            <td class="px-4 py-3 text-sm font-medium text-gray-900">{{ pool.name }}</td>
            <td class="px-4 py-3 text-sm text-gray-600 font-mono">{{ pool.network || pool.subnet }}</td>
            <td class="px-4 py-3 text-sm text-gray-600">{{ pool.tenant?.name || '-' }}</td>
            <td class="px-4 py-3 text-sm text-center text-gray-600">{{ pool.used_count ?? 0 }} / {{ pool.total_count ?? pool.size ?? 0 }}</td>
            <td class="px-4 py-3 text-right">
              <button
                @click="deletePool(pool)"
                class="p-1.5 text-red-500 hover:bg-red-50 rounded-md transition-colors"
                title="Delete"
              >
                <Trash2 class="w-4 h-4" />
              </button>
            </td>
          </tr>
          <tr v-if="pools.length === 0">
            <td colspan="5" class="px-4 py-8 text-center text-gray-400 text-sm">No IP pools configured</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Create Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" @click.self="showCreateModal = false">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h2 class="text-lg font-bold text-gray-900 mb-4">Add IP Pool</h2>
        <form @submit.prevent="createPool" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Pool Name *</label>
            <input v-model="form.name" type="text" required class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Network (CIDR) *</label>
            <input v-model="form.network" type="text" required placeholder="e.g. 10.0.0.0/24" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm font-mono focus:ring-2 focus:ring-blue-500" />
          </div>
          <div v-if="formError" class="text-sm text-red-600">{{ formError }}</div>
          <div class="flex justify-end gap-3 pt-2">
            <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-800">Cancel</button>
            <button type="submit" :disabled="creating" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {{ creating ? 'Creating...' : 'Create Pool' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { Plus, Trash2 } from 'lucide-vue-next'

const pools = ref([])
const poolStats = ref(null)
const loading = ref(true)
const error = ref(null)
const showCreateModal = ref(false)
const creating = ref(false)
const formError = ref(null)
const form = reactive({ name: '', network: '' })

const fetchPools = async () => {
  try {
    loading.value = true
    error.value = null
    const [poolsRes, statsRes] = await Promise.all([
      axios.get('/system/tenant/ip-pools'),
      axios.get('/system/tenant/ip-pools/stats').catch(() => ({ data: {} }))
    ])
    pools.value = poolsRes.data.data || poolsRes.data.pools || []
    poolStats.value = statsRes.data.data || statsRes.data.stats || null
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load IP pools'
  } finally {
    loading.value = false
  }
}

const createPool = async () => {
  try {
    creating.value = true
    formError.value = null
    await axios.post('/system/tenant/ip-pools', form)
    showCreateModal.value = false
    Object.assign(form, { name: '', network: '' })
    await fetchPools()
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create pool'
  } finally {
    creating.value = false
  }
}

const deletePool = async (pool) => {
  if (!confirm(`Delete IP pool "${pool.name}"?`)) return
  try {
    await axios.delete(`/system/tenant/ip-pools/${pool.id}`)
    await fetchPools()
  } catch (err) {
    alert(err.response?.data?.message || 'Failed to delete pool')
  }
}

onMounted(() => fetchPools())
</script>
