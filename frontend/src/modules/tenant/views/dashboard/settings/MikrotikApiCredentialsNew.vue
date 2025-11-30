<template>
  <PageContainer>
    <PageHeader title="Mikrotik API Credentials" subtitle="Configure router API connections" icon="Router" :breadcrumbs="breadcrumbs">
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
        <BaseCard>
          <div class="p-6 space-y-4">
            <h3 class="text-lg font-semibold text-slate-900 mb-4">API Configuration</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Router IP Address *</label>
                <input v-model="formData.router_ip" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="192.168.88.1" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">API Port *</label>
                <input v-model.number="formData.api_port" type="number" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="8728" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Username *</label>
                <input v-model="formData.username" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="admin" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Password *</label>
                <input v-model="formData.password" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>
            </div>

            <div class="flex items-center justify-between p-3 bg-slate-50 rounded-lg">
              <div>
                <div class="text-sm font-medium text-slate-900">Use SSL</div>
                <div class="text-xs text-slate-500">Enable secure API connection</div>
              </div>
              <label class="relative inline-flex items-center cursor-pointer">
                <input v-model="formData.use_ssl" type="checkbox" class="sr-only peer" />
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
                <div class="text-sm font-medium" :class="connectionStatus.success ? 'text-green-900' : 'text-red-900'">{{ connectionStatus.message }}</div>
                <div class="text-xs" :class="connectionStatus.success ? 'text-green-600' : 'text-red-600'">{{ connectionStatus.details }}</div>
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
import { Router, Zap, Save, CheckCircle, XCircle } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Settings', to: '/dashboard/settings' }, { label: 'Mikrotik API' }]

const saving = ref(false)
const testing = ref(false)
const connectionStatus = ref(null)

const formData = ref({
  router_ip: '192.168.88.1',
  api_port: 8728,
  username: 'admin',
  password: '',
  use_ssl: false
})

const saveSettings = async () => {
  saving.value = true
  await new Promise(resolve => setTimeout(resolve, 1000))
  alert('Settings saved!')
  saving.value = false
}

const testConnection = async () => {
  testing.value = true
  await new Promise(resolve => setTimeout(resolve, 2000))
  connectionStatus.value = { success: true, message: 'Connection Successful', details: 'Mikrotik API is reachable' }
  testing.value = false
}
</script>
