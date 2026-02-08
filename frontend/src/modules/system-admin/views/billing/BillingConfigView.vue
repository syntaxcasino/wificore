<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Billing Configuration</h1>
      <p class="text-sm text-gray-500 mt-1">Manage SaaS billing rates, plans, and default payment settings</p>
    </div>

    <div v-if="loading" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-gray-500">
      <div class="animate-spin w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full mx-auto mb-3"></div>
      Loading configuration...
    </div>
    <div v-else-if="error" class="bg-white rounded-xl border border-gray-200 p-8 text-center text-red-500">
      {{ error }}
      <button @click="fetchConfig" class="block mx-auto mt-2 text-blue-600 hover:underline text-sm">Retry</button>
    </div>
    <template v-else>
      <!-- Default Paybill -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Default Paybill</h2>
        <form @submit.prevent="updatePaybill" class="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Paybill Number</label>
            <input v-model="paybillForm.paybill" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Paybill Name</label>
            <input v-model="paybillForm.paybill_name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Consumer Key</label>
            <input v-model="paybillForm.consumer_key" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Consumer Secret</label>
            <input v-model="paybillForm.consumer_secret" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Passkey</label>
            <input v-model="paybillForm.passkey" type="password" placeholder="Leave blank to keep current" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Environment</label>
            <select v-model="paybillForm.environment" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-blue-500">
              <option value="sandbox">Sandbox</option>
              <option value="production">Production</option>
            </select>
          </div>
          <div class="md:col-span-2 flex justify-end">
            <button type="submit" :disabled="saving" class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700 disabled:opacity-50">
              {{ saving ? 'Saving...' : 'Update Paybill' }}
            </button>
          </div>
          <div v-if="saveMessage" class="md:col-span-2 text-sm" :class="saveError ? 'text-red-600' : 'text-green-600'">{{ saveMessage }}</div>
        </form>
      </div>

      <!-- Current Rates -->
      <div class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Current Billing Rates</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div class="p-4 bg-gray-50 rounded-lg">
            <div class="text-sm text-gray-500">PPPoE Rate</div>
            <div class="text-xl font-bold text-gray-900 mt-1">{{ config.pppoe_rate || 'N/A' }}</div>
            <div class="text-xs text-gray-400">per user/month</div>
          </div>
          <div class="p-4 bg-gray-50 rounded-lg">
            <div class="text-sm text-gray-500">Hotspot Revenue %</div>
            <div class="text-xl font-bold text-gray-900 mt-1">{{ config.hotspot_revenue_pct || 'N/A' }}%</div>
            <div class="text-xs text-gray-400">of hotspot revenue</div>
          </div>
          <div class="p-4 bg-gray-50 rounded-lg">
            <div class="text-sm text-gray-500">Minimum Subscription</div>
            <div class="text-xl font-bold text-gray-900 mt-1">{{ config.minimum_subscription || 'N/A' }}</div>
            <div class="text-xs text-gray-400">KES/month</div>
          </div>
        </div>
      </div>

      <!-- Plans -->
      <div v-if="config.plans" class="bg-white rounded-xl border border-gray-200 p-6">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Subscription Plans</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <div v-for="(plan, key) in config.plans" :key="key" class="p-4 border border-gray-200 rounded-lg">
            <div class="text-sm font-semibold text-gray-900 capitalize">{{ key }}</div>
            <div class="text-xs text-gray-500 mt-1">{{ JSON.stringify(plan) }}</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const config = ref({})
const loading = ref(true)
const error = ref(null)
const saving = ref(false)
const saveMessage = ref('')
const saveError = ref(false)

const paybillForm = reactive({
  paybill: '',
  paybill_name: '',
  consumer_key: '',
  consumer_secret: '',
  passkey: '',
  environment: 'sandbox'
})

const fetchConfig = async () => {
  try {
    loading.value = true
    error.value = null
    const res = await axios.get('/system/landlord/configuration')
    config.value = res.data.configuration || {}
    paybillForm.paybill = config.value.default_paybill || ''
    paybillForm.paybill_name = config.value.default_paybill_name || ''
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load configuration'
  } finally {
    loading.value = false
  }
}

const updatePaybill = async () => {
  try {
    saving.value = true
    saveMessage.value = ''
    saveError.value = false
    const payload = { ...paybillForm }
    if (!payload.consumer_key) delete payload.consumer_key
    if (!payload.consumer_secret) delete payload.consumer_secret
    if (!payload.passkey) delete payload.passkey
    await axios.put('/system/landlord/paybill', payload)
    saveMessage.value = 'Paybill updated successfully'
    paybillForm.consumer_key = ''
    paybillForm.consumer_secret = ''
    paybillForm.passkey = ''
  } catch (err) {
    saveError.value = true
    saveMessage.value = err.response?.data?.message || 'Failed to update paybill'
  } finally {
    saving.value = false
  }
}

onMounted(() => fetchConfig())
</script>
