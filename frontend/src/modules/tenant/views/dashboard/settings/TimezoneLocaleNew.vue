<template>
  <PageContainer>
    <PageHeader title="Timezone & Locale" subtitle="Configure regional settings" icon="Globe" :breadcrumbs="breadcrumbs">
      <template #actions>
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
            <h3 class="text-lg font-semibold text-slate-900 mb-4">Regional Settings</h3>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Timezone *</label>
                <BaseSelect v-model="formData.timezone" class="w-full">
                  <option value="Africa/Nairobi">Africa/Nairobi (EAT)</option>
                  <option value="UTC">UTC</option>
                  <option value="America/New_York">America/New_York (EST)</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Language *</label>
                <BaseSelect v-model="formData.language" class="w-full">
                  <option value="en">English</option>
                  <option value="sw">Swahili</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Date Format *</label>
                <BaseSelect v-model="formData.date_format" class="w-full">
                  <option value="DD/MM/YYYY">DD/MM/YYYY</option>
                  <option value="MM/DD/YYYY">MM/DD/YYYY</option>
                  <option value="YYYY-MM-DD">YYYY-MM-DD</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Time Format *</label>
                <BaseSelect v-model="formData.time_format" class="w-full">
                  <option value="12">12 Hour</option>
                  <option value="24">24 Hour</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">Currency *</label>
                <BaseSelect v-model="formData.currency" class="w-full">
                  <option value="KES">KES - Kenyan Shilling</option>
                  <option value="USD">USD - US Dollar</option>
                  <option value="EUR">EUR - Euro</option>
                </BaseSelect>
              </div>

              <div>
                <label class="block text-sm font-medium text-slate-700 mb-2">First Day of Week</label>
                <BaseSelect v-model="formData.first_day_of_week" class="w-full">
                  <option value="0">Sunday</option>
                  <option value="1">Monday</option>
                </BaseSelect>
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
import { Globe, Save } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Settings', to: '/dashboard/settings' }, { label: 'Timezone & Locale' }]

const saving = ref(false)

const formData = ref({
  timezone: 'Africa/Nairobi',
  language: 'en',
  date_format: 'DD/MM/YYYY',
  time_format: '24',
  currency: 'KES',
  first_day_of_week: '1'
})

const saveSettings = async () => {
  saving.value = true
  await new Promise(resolve => setTimeout(resolve, 1000))
  alert('Settings saved!')
  saving.value = false
}
</script>
