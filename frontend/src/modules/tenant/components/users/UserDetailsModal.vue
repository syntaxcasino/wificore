<template>
  <BaseModal v-model="isOpen" title="User Details" size="lg" @close="handleClose">
    <div v-if="user" class="space-y-6">
      <!-- User Header -->
      <div class="flex items-center gap-4 pb-4 border-b border-slate-200">
        <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-2xl font-semibold">
          {{ getUserInitials(user) }}
        </div>
        <div class="flex-1">
          <h3 class="text-lg font-semibold text-slate-900">{{ user.name || user.username }}</h3>
          <div class="flex items-center gap-2 mt-1">
            <BaseBadge :variant="getStatusVariant(user.status)" :dot="user.status === 'active'" :pulse="user.status === 'active'">
              {{ user.status || 'inactive' }}
            </BaseBadge>
            <BaseBadge :variant="user.type === 'pppoe' ? 'purple' : 'info'" size="sm">
              {{ user.type || 'hotspot' }}
            </BaseBadge>
          </div>
        </div>
      </div>

      <!-- User Information -->
      <div class="grid grid-cols-2 gap-4">
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">Username</label>
          <p class="text-sm text-slate-900 mt-1">{{ user.username || 'N/A' }}</p>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">User ID</label>
          <p class="text-sm text-slate-900 mt-1">{{ user.id }}</p>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">Email</label>
          <p class="text-sm text-slate-900 mt-1">{{ user.email || 'Not provided' }}</p>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">Phone</label>
          <p class="text-sm text-slate-900 mt-1">{{ user.phone || 'Not provided' }}</p>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">Package</label>
          <p class="text-sm text-slate-900 mt-1">{{ user.package?.name || 'No package assigned' }}</p>
        </div>
        <div>
          <label class="text-xs font-medium text-slate-500 uppercase">Created</label>
          <p class="text-sm text-slate-900 mt-1">{{ formatDate(user.created_at) }}</p>
        </div>
      </div>

      <!-- Session Information (if available) -->
      <div v-if="user.session" class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-900 mb-3">Current Session</h4>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <label class="text-xs font-medium text-slate-500 uppercase">Session Start</label>
            <p class="text-sm text-slate-900 mt-1">{{ formatDateTime(user.session.start_time) }}</p>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 uppercase">Duration</label>
            <p class="text-sm text-slate-900 mt-1">{{ formatDuration(user.session.duration) }}</p>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 uppercase">Data Used</label>
            <p class="text-sm text-slate-900 mt-1">{{ formatBytes(user.session.data_used) }}</p>
          </div>
          <div>
            <label class="text-xs font-medium text-slate-500 uppercase">IP Address</label>
            <p class="text-sm text-slate-900 mt-1">{{ user.session.ip_address || 'N/A' }}</p>
          </div>
        </div>
      </div>

      <!-- Statistics (if available) -->
      <div v-if="user.statistics" class="border-t border-slate-200 pt-4">
        <h4 class="text-sm font-semibold text-slate-900 mb-3">Statistics</h4>
        <div class="grid grid-cols-3 gap-4">
          <BaseCard :padding="true" hoverable>
            <div class="text-center">
              <p class="text-2xl font-bold text-blue-600">{{ user.statistics.total_sessions || 0 }}</p>
              <p class="text-xs text-slate-600 mt-1">Total Sessions</p>
            </div>
          </BaseCard>
          <BaseCard :padding="true" hoverable>
            <div class="text-center">
              <p class="text-2xl font-bold text-green-600">{{ formatBytes(user.statistics.total_data || 0) }}</p>
              <p class="text-xs text-slate-600 mt-1">Total Data</p>
            </div>
          </BaseCard>
          <BaseCard :padding="true" hoverable>
            <div class="text-center">
              <p class="text-2xl font-bold text-purple-600">{{ formatDuration(user.statistics.total_time || 0) }}</p>
              <p class="text-xs text-slate-600 mt-1">Total Time</p>
            </div>
          </BaseCard>
        </div>
      </div>
    </div>

    <template #footer>
      <BaseButton @click="handleClose" variant="secondary">
        Close
      </BaseButton>
      <BaseButton @click="handleEdit" variant="primary">
        Edit User
      </BaseButton>
    </template>
  </BaseModal>
</template>

<script setup>
import { computed } from 'vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'

const props = defineProps({
  modelValue: Boolean,
  user: Object
})

const emit = defineEmits(['update:modelValue', 'edit'])

const isOpen = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value)
})

// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
    blocked: 'danger'
  }
  return variants[status] || 'default'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'long',
    day: 'numeric'
  })
}

const formatDateTime = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

const formatDuration = (seconds) => {
  if (!seconds) return '0m'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  
  if (hours > 0) {
    return `${hours}h ${minutes}m`
  }
  return `${minutes}m`
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const handleClose = () => {
  isOpen.value = false
}

const handleEdit = () => {
  emit('edit', props.user)
  handleClose()
}
</script>
