<template>
  <DataViewContainer
    title="Roles & Permissions"
    subtitle="Manage user roles and access control"
    icon="Shield"
    :breadcrumbs="breadcrumbs"
  >
    <template #actions>
      <BaseButton @click="openCreateModal" variant="primary">
        <Plus class="w-4 h-4 mr-1" />
        Add Role
      </BaseButton>
    </template>

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

    <!-- Add/Edit Role SlideOverlay -->
    <SlideOverlay
      v-model="showOverlay"
      :title="isEditing ? 'Edit Role' : 'Add Role'"
      :subtitle="isEditing ? 'Update role details and permissions' : 'Create a new role with permissions'"
      icon="Shield"
      width="480px"
      @close="closeModal"
    >
      <div class="p-6 space-y-4">
        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Role Name</label>
          <input
            v-model="formData.name"
            type="text"
            placeholder="e.g., Manager"
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-1">Description</label>
          <textarea
            v-model="formData.description"
            rows="3"
            placeholder="Brief description of this role..."
            class="w-full px-3 py-2 border border-slate-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none"
          ></textarea>
        </div>

        <div>
          <label class="block text-sm font-medium text-slate-700 mb-2">Permissions</label>
          <div class="space-y-2 max-h-48 overflow-y-auto">
            <label v-for="perm in availablePermissions" :key="perm" class="flex items-center gap-2 p-2 hover:bg-slate-50 rounded cursor-pointer">
              <input
                type="checkbox"
                :value="perm"
                v-model="formData.permissions"
                class="w-4 h-4 text-blue-600 border-slate-300 rounded focus:ring-blue-500"
              />
              <span class="text-sm text-slate-700">{{ perm }}</span>
            </label>
          </div>
        </div>
      </div>

      <template #footer>
        <div class="flex gap-3">
          <button
            @click="closeModal"
            class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
          >
            Cancel
          </button>
          <button
            @click="saveRole"
            :disabled="saving"
            class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50"
          >
            {{ saving ? 'Saving...' : (isEditing ? 'Update Role' : 'Create Role') }}
          </button>
        </div>
      </template>
    </SlideOverlay>
  </DataViewContainer>
</template>

<script setup>
import { ref } from 'vue'
import { Shield, Plus, Edit2, Trash2, CheckCircle } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

const breadcrumbs = [{ label: 'Dashboard', to: '/dashboard' }, { label: 'Admin', to: '/dashboard/admin' }, { label: 'Roles & Permissions' }]

const showOverlay = ref(false)
const isEditing = ref(false)
const saving = ref(false)
const editingId = ref(null)

const formData = ref({
  name: '',
  description: '',
  permissions: []
})

const availablePermissions = [
  'All Permissions',
  'System Settings',
  'User Management',
  'Billing',
  'Reports',
  'Support',
  'Packages',
  'View Users',
  'Support Tickets',
  'View Reports'
]

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

const openCreateModal = () => {
  isEditing.value = false
  editingId.value = null
  formData.value = { name: '', description: '', permissions: [] }
  showOverlay.value = true
}

const editRole = (role) => {
  isEditing.value = true
  editingId.value = role.id
  formData.value = {
    name: role.name,
    description: role.description,
    permissions: [...role.permissions]
  }
  showOverlay.value = true
}

const closeModal = () => {
  showOverlay.value = false
  setTimeout(() => {
    formData.value = { name: '', description: '', permissions: [] }
    isEditing.value = false
    editingId.value = null
  }, 300)
}

const saveRole = async () => {
  saving.value = true
  try {
    await new Promise(resolve => setTimeout(resolve, 500))
    if (isEditing.value) {
      const index = roles.value.findIndex(r => r.id === editingId.value)
      if (index !== -1) {
        roles.value[index] = { ...roles.value[index], ...formData.value }
      }
    } else {
      const newRole = {
        id: Date.now(),
        ...formData.value,
        gradient: 'from-blue-500 to-indigo-600',
        iconColor: 'text-blue-600',
        users_count: 0,
        is_system: false
      }
      roles.value.push(newRole)
    }
    closeModal()
  } finally {
    saving.value = false
  }
}

const deleteRole = (role) => {
  if (!role.is_system && confirm(`Delete role ${role.name}?`)) {
    roles.value = roles.value.filter(r => r.id !== role.id)
  }
}
</script>
