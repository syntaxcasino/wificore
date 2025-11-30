<template>
  <PageContainer>
    <PageHeader title="Backup & Restore" subtitle="Manage system backups" icon="Database" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="createBackup" variant="primary" :loading="creating">
          <Plus class="w-4 h-4 mr-1" />
          Create Backup
        </BaseButton>
      </template>
    </PageHeader>

    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-gradient-to-br from-blue-50 to-indigo-50 rounded-lg p-4 border border-blue-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-blue-600 font-medium mb-1">Total Backups</div>
              <div class="text-2xl font-bold text-blue-900">{{ backups.length }}</div>
            </div>
            <Database class="w-6 h-6 text-blue-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-lg p-4 border border-green-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-green-600 font-medium mb-1">Last Backup</div>
              <div class="text-lg font-bold text-green-900">{{ formatDate(backups[0]?.created_at) }}</div>
            </div>
            <Clock class="w-6 h-6 text-green-600" />
          </div>
        </div>

        <div class="bg-gradient-to-br from-purple-50 to-indigo-50 rounded-lg p-4 border border-purple-200">
          <div class="flex items-center justify-between">
            <div>
              <div class="text-xs text-purple-600 font-medium mb-1">Total Size</div>
              <div class="text-2xl font-bold text-purple-900">{{ formatBytes(totalSize) }}</div>
            </div>
            <HardDrive class="w-6 h-6 text-purple-600" />
          </div>
        </div>
      </div>
    </div>

    <PageContent :padding="false">
      <div class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Backup Name</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Created</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Size</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700">Type</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="backup in backups" :key="backup.id" class="border-b border-slate-100 hover:bg-blue-50/50">
                  <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ backup.name }}</td>
                  <td class="px-6 py-4 text-sm text-slate-600">{{ formatDateTime(backup.created_at) }}</td>
                  <td class="px-6 py-4 text-sm text-slate-900">{{ formatBytes(backup.size) }}</td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="backup.type === 'auto' ? 'info' : 'success'" size="sm">{{ backup.type }}</BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-right">
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="downloadBackup(backup)" variant="ghost" size="sm">
                        <Download class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton @click="restoreBackup(backup)" variant="success" size="sm">
                        <RotateCcw class="w-3 h-3 mr-1" />
                        Restore
                      </BaseButton>
                      <BaseButton @click="deleteBackup(backup)" variant="danger" size="sm">
                        <Trash2 class="w-3 h-3" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Database, Plus, Clock, HardDrive, Download, RotateCcw, Trash2 } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Admin', to: '/dashboard/admin' }, { label: 'Backup & Restore' }]

const creating = ref(false)

const backups = ref(Array.from({ length: 10 }, (_, i) => ({
  id: i + 1,
  name: `backup_${new Date(Date.now() - i * 86400000).toISOString().split('T')[0]}.sql`,
  created_at: new Date(Date.now() - i * 86400000).toISOString(),
  size: Math.floor(Math.random() * 100000000) + 10000000,
  type: i === 0 ? 'manual' : 'auto'
})))

const totalSize = computed(() => backups.value.reduce((sum, b) => sum + b.size, 0))

const formatBytes = (bytes) => {
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const formatDate = (date) => new Date(date).toLocaleDateString()
const formatDateTime = (date) => new Date(date).toLocaleString()

const createBackup = async () => {
  creating.value = true
  await new Promise(resolve => setTimeout(resolve, 2000))
  backups.value.unshift({
    id: Date.now(),
    name: `backup_${new Date().toISOString().split('T')[0]}.sql`,
    created_at: new Date().toISOString(),
    size: Math.floor(Math.random() * 100000000) + 10000000,
    type: 'manual'
  })
  creating.value = false
  alert('Backup created successfully!')
}

const downloadBackup = (backup) => alert(`Downloading ${backup.name}`)
const restoreBackup = (backup) => {
  if (confirm(`Restore backup ${backup.name}? This will overwrite current data.`)) {
    alert('Restore initiated!')
  }
}
const deleteBackup = (backup) => {
  if (confirm(`Delete backup ${backup.name}?`)) {
    backups.value = backups.value.filter(b => b.id !== backup.id)
  }
}
</script>
