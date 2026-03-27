<template>
  <DataViewContainer
    title="Roles & Permissions"
    subtitle="Manage admin user roles and permissions"
    icon="Shield"
    :breadcrumbs="breadcrumbs"
  >
    <template #actions>
      <BaseButton variant="primary" @click="showComingSoon">
        <Plus class="w-4 h-4 mr-1" />
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
import { Plus } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Admin Users', to: '/dashboard/users/all' },
  { label: 'Roles & Permissions' }
])

const showComingSoon = () => {
  alert('Roles and permissions management will be implemented in the next phase.')
}
</script>
