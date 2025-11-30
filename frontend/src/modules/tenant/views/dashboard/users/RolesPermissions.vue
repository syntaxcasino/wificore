<template>
  <PageContainer>
    <PageHeader
      title="Roles & Permissions"
      subtitle="Manage admin user roles and permissions"
      icon="Shield"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Create Role
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <BaseAlert variant="info" title="Coming Soon">
        Roles and permissions management will be implemented in the next phase.
      </BaseAlert>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { computed } from 'vue'
import { Plus } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Admin Users', to: '/dashboard/users/all' },
  { label: 'Roles & Permissions' }
])
</script>
