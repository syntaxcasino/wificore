<template>
  <PageContainer>
    <PageHeader title="Roles & Permissions" subtitle="Manage user roles and access control" icon="Shield" :breadcrumbs="breadcrumbs">
      <template #actions>
        <BaseButton @click="openCreateModal" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add Role
        </BaseButton>
      </template>
    </PageHeader>

    <PageContent>
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div v-for="role in roles" :key="role.id" class="bg-white rounded-xl border-2 border-slate-200 hover:border-blue-400 hover:shadow-lg transition-all">
          <div class="p-6 bg-gradient-to-br" :class="role.gradient">
            <div class="flex items-start justify-between mb-4">
              <div class="p-3 bg-white/90 rounded-lg">
                <Shield class="w-6 h-6" :class="role.iconColor" />
              </div>
              <BaseBadge variant="secondary" size="sm">{{ role.users_count }} users</BaseBadge>
            </div>
            <h3 class="text-xl font-bold text-white mb-1">{{ role.name }}</h3>
            <p class="text-white/80 text-sm">{{ role.description }}</p>
          </div>

          <div class="p-6 space-y-4">
            <div>
              <div class="text-xs font-semibold text-slate-500 uppercase mb-2">Permissions</div>
              <div class="space-y-2">
                <div v-for="perm in role.permissions.slice(0, 5)" :key="perm" class="flex items-center gap-2 text-sm">
                  <CheckCircle class="w-4 h-4 text-green-600" />
                  <span class="text-slate-700">{{ perm }}</span>
                </div>
                <div v-if="role.permissions.length > 5" class="text-xs text-slate-500">
                  +{{ role.permissions.length - 5 }} more
                </div>
              </div>
            </div>

            <div class="pt-4 border-t border-slate-200 flex items-center gap-2">
              <BaseButton @click="editRole(role)" variant="ghost" size="sm" class="flex-1">
                <Edit2 class="w-3 h-3 mr-1" />
                Edit
              </BaseButton>
              <BaseButton @click="deleteRole(role)" variant="ghost" size="sm" class="text-red-600" :disabled="role.is_system">
                <Trash2 class="w-3 h-3" />
              </BaseButton>
            </div>
          </div>
        </div>
      </div>
    </PageContent>
  </PageContainer>
</template>

<script setup>
import { ref } from 'vue'
import { Shield, Plus, Edit2, Trash2, CheckCircle } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Admin', to: '/dashboard/admin' }, { label: 'Roles & Permissions' }]

const roles = ref([
  {
    id: 1,
    name: 'Super Admin',
    description: 'Full system access',
    gradient: 'from-red-500 to-rose-600',
    iconColor: 'text-red-600',
    users_count: 2,
    is_system: true,
    permissions: ['All Permissions', 'System Settings', 'User Management', 'Billing', 'Reports']
  },
  {
    id: 2,
    name: 'Admin',
    description: 'Administrative access',
    gradient: 'from-blue-500 to-indigo-600',
    iconColor: 'text-blue-600',
    users_count: 5,
    is_system: false,
    permissions: ['User Management', 'Billing', 'Reports', 'Support', 'Packages']
  },
  {
    id: 3,
    name: 'Support',
    description: 'Customer support access',
    gradient: 'from-green-500 to-emerald-600',
    iconColor: 'text-green-600',
    users_count: 8,
    is_system: false,
    permissions: ['View Users', 'Support Tickets', 'View Reports']
  }
])

const openCreateModal = () => alert('Create role modal coming soon!')
const editRole = (role) => console.log('Edit role:', role)
const deleteRole = (role) => {
  if (!role.is_system && confirm(`Delete role ${role.name}?`)) {
    roles.value = roles.value.filter(r => r.id !== role.id)
  }
}
</script>
