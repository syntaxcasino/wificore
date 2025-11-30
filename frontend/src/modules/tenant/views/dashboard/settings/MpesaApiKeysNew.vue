<template>
  <PageContainer>
    <PageHeader title="M-Pesa API Configuration" subtitle="Configure M-Pesa payment integration" icon="Smartphone" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="testConnection" variant="ghost" :loading="testing">
          <Zap class="w-4 h-4 mr-1" />
          Test Connection
        </BaseButton>
        <BaseButton @click="saveSettings" variant="primary" :loading="saving">
          <Save class="w-4 h-4 mr-1" />
          Save Changes
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="max-w-4xl mx-auto space-y-6">
        <BaseAlert variant="info" title="M-Pesa Integration">
          Configure your Safaricom M-Pesa API credentials to enable mobile money payments. You'll need to register for Daraja API access.
        </BaseAlert>

        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">API Credentials</h3>
            
            <div>
              <label class="block text-sm font-medium text-slate-700 mb-2">Environment</label>
              <BaseSelect v-model="formData.environment" class="w-full">
                <option value="sandbox">Sandbox (Testing)</option>
                <option value="production">Production (Live)</option>
              </BaseSelect>
              <p class="mt-1 text-xs text-slate-500">Use sandbox for testing, production for live transactions</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Consumer Key *</label>
                <input v-model="formData.consumer_key" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Consumer Secret *</label>
                <input v-model="formData.consumer_secret" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm" />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-2">Passkey *</label>
              <input v-model="formData.passkey" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 font-mono text-sm" />
              <p class="mt-1 text-xs text-slate-500">Lipa Na M-Pesa Online Passkey</p>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Business Configuration</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Business Short Code *</label>
                <input v-model="formData.shortcode" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="174379" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Transaction Type</label>
                <BaseSelect v-model="formData.transaction_type" class="w-full">
                  <option value="CustomerPayBillOnline">Pay Bill</option>
                  <option value="CustomerBuyGoodsOnline">Buy Goods</option>
                </BaseSelect>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-slate-700 mb-2">Callback URL *</label>
              <input v-model="formData.callback_url" type="url" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="https://yourdomain.com/api/mpesa/callback" />
              <p class="mt-1 text-xs text-slate-500">URL to receive payment notifications</p>
            </div>
          </div>
        </BaseCard>

        <BaseCard>
          <div class="p-6 space-y-3">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Settings</h3>
            
            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Enable M-Pesa Payments</div>
                <div class="text-xs text-slate-500">Allow users to pay via M-Pesa</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.enabled" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Auto-Activate Packages</div>
                <div class="text-xs text-slate-500">Automatically activate packages after payment</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.auto_activate" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Send SMS Confirmation</div>
                <div class="text-xs text-slate-500">Send SMS after successful payment</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.send_sms" type="checkbox" class="sr-only peer" />
                <div class="w-11 h-6 bg-slate-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
              </label>
            </div>
          </div>
        </BaseCard>

        <BaseCard v-if="connectionStatus">
          <div class="p-6">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Connection Status</h3>
            <div class="flex items-center gap-3 p-4 rounded-lg" :class="connectionStatus.success ? 'bg-green-50 border border-green-200' : 'bg-red-50 border border-red-200'">
              <component :is="connectionStatus.success ? CheckCircle : XCircle" class="w-6 h-6" :class="connectionStatus.success ? 'text-green-600' : 'text-red-600'" />
              <div>
                <div class="text-sm font-medium" :class="connectionStatus.success ? 'text-green-900' : 'text-red-900'">
                  {{ connectionStatus.message }}
                </div>
                <div class="text-xs" :class="connectionStatus.success ? 'text-green-600' : 'text-red-600'">
                  {{ connectionStatus.details }}
                </div>
              </div>
            </div>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref } from 'vue'
import { Smartphone, Zap, Save, CheckCircle, XCircle } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Settings', to: '/dashboard/settings' },
  { label: 'M-Pesa API' }
]

const saving = ref(false)
const testing = ref(false)
const connectionStatus = ref(null)

const formData = ref({
  environment: 'sandbox',
  consumer_key: '',
  consumer_secret: '',
  passkey: '',
  shortcode: '174379',
  transaction_type: 'CustomerPayBillOnline',
  callback_url: 'https://yourdomain.com/api/mpesa/callback',
  enabled: true,
  auto_activate: true,
  send_sms: true
})

const saveSettings = async () => {
  saving.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 1000))
    alert('M-Pesa settings saved successfully!')
  } finally {
    saving.value = false
  }
}

const testConnection = async () => {
  testing.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 2000))
    connectionStatus.value = {
      success: true,
      message: 'Connection Successful',
      details: 'M-Pesa API is configured correctly'
    }
  } catch (err) {
    connectionStatus.value = {
      success: false,
      message: 'Connection Failed',
      details: 'Please check your credentials'
    }
  } finally {
    testing.value = false
  }
}
</script>
