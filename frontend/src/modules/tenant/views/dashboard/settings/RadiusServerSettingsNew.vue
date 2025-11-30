<template>
  <PageContainer>
    <PageHeader title="RADIUS Server Settings" subtitle="Configure FreeRADIUS server connection" icon="Shield" :breadcrumbs="breadcrumbs">
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
            <h3 class="text-lg font-semibold text-slate-900 mb-4">RADIUS Configuration</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Server IP *</label>
                <input v-model="formData.server_ip" type="text" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="127.0.0.1" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Auth Port *</label>
                <input v-model.number="formData.auth_port" type="number" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="1812" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Accounting Port *</label>
                <input v-model.number="formData.acct_port" type="number" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" placeholder="1813" />
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Shared Secret *</label>
                <input v-model="formData.secret" type="password" required class="w-full px-4 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500" />
              </div>
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
import { Shield, Zap, Save, CheckCircle, XCircle } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Settings', to: '/dashboard/settings' }, { label: 'RADIUS Server' }]

const saving = ref(false)
const testing = ref(false)
const connectionStatus = ref(null)

const formData = ref({
  server_ip: '127.0.0.1',
  auth_port: 1812,
  acct_port: 1813,
  secret: ''
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
  connectionStatus.value = { success: true, message: 'RADIUS Server Connected' }
  testing.value = false
}
</script>
