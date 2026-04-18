<template>
  <DataViewContainer
    title="Roles & Permissions"
    subtitle="Manage admin user roles and permissions"
    color-theme="indigo"
    :breadcrumbs="breadcrumbs"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
      </svg>
    </template>

    <template #actions>
      <BaseButton variant="primary" @click="showComingSoon">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
        </svg>
        Create Role
      </BaseButton>
    </template>

    <div class="flex flex-col items-center justify-center py-16">
      <BaseAlert variant="info" title="Coming Soon" class="max-w-md">
        Roles and permissions management will be implemented in the next phase.
      </BaseAlert>
    </div>
  </DataViewContainer>
</template>

<script setup>
import { computed } from 'vue'
import { useToast } from '@/modules/common/composables/useToast.js'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

const { info: showInfo } = useToast()

const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Admin Users', to: '/dashboard/users/all' },
  { label: 'Roles & Permissions' }
])

const showComingSoon = () => {
  showInfo('Roles and permissions management will be implemented in the next phase.')
}
</script>
