<template>
  <DataViewContainer
    title="PPPoE Users"
    subtitle="Manage PPPoE customer accounts"
    color-theme="purple"
    v-model:search-model="searchQuery"
    search-placeholder="Search PPPoE users..."
    :stats="[
      { color: 'bg-purple-500', value: totalUsers },
      { color: 'bg-emerald-500', value: activeUsers.length },
      { color: 'bg-yellow-500', value: inactiveUsers.length }
    ]"
    :total="users.length"
    :loading="loading"
    add-button-text="Add User"
    @refresh="fetchUsers"
    @add="openAddUser"
    @search-clear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="blocked">Blocked</option>
        <option value="expired">Expired</option>
      </BaseSelect>
      <BaseSelect v-model="filters.package_id" placeholder="All Packages" class="w-44">
        <option value="">All Packages</option>
        <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
      </BaseSelect>
    </template>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <X class="w-10 h-10" />
      <p class="text-center">{{ error }}</p>
      <button @click="fetchUsers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Data Content -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full px-4 md:px-6 pt-2 pb-2 min-h-0">
      <!-- Mobile Cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0">
        <MobileDataCard
          v-for="user in paginatedData"
          :key="user.id"
          :title="user.name || user.username"
          :subtitle="user.username"
          :meta-lines="[{ text: user.router?.name || 'N/A' }, { text: user.package?.name || 'No package' }, { text: formatDate(user.expires_at) }]"
          :status="user.status"
          :actions="getUserActions(user)"
          hoverable
        />
      </div>

      <!-- Desktop Table -->
      <div class="hidden md:flex bg-white border border-slate-200 shadow-sm overflow-hidden flex-col min-h-0 flex-1">
        <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
          <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200 sticky top-0 z-[5]">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expires</th>
                <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="user in paginatedData" :key="user.id" class="hover:bg-purple-50/50 transition-colors cursor-pointer" @click="openUserDetails(user)">
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">{{ getUserInitials(user) }}</div>
                    <div>
                      <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                      <div class="text-xs text-slate-500">{{ user.username }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 text-sm text-slate-900">{{ user.router?.name || 'N/A' }}</td>
                <td class="px-6 py-4">
                  <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'No package' }}</div>
                  <div class="text-xs text-slate-500">{{ formatPackageSpeed(user.package) }}</div>
                </td>
                <td class="px-6 py-4">
                  <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
                </td>
                <td class="px-6 py-4 text-sm text-slate-600">{{ formatDate(user.expires_at) }}</td>
                <td class="px-6 py-4 text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1">
                    <button @click="openUserDetails(user)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">View</button>
                    <button @click="handleEdit(user)" class="px-2 py-1 text-xs font-medium text-slate-700 bg-slate-100 rounded hover:bg-slate-200 transition-colors">Edit</button>
                    <button @click="handleToggleStatus(user)" :class="user.status === 'blocked' ? 'text-green-700 bg-green-50 hover:bg-green-100' : 'text-amber-700 bg-amber-50 hover:bg-amber-100'" class="px-2 py-1 text-xs font-medium rounded transition-colors">{{ user.status === 'blocked' ? 'Unblock' : 'Block' }}</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="users" class="mt-auto" />
    </div>

    <!-- Empty State -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No PPPoE Users Found'"
      :description="searchQuery ? 'No users match your search criteria.' : 'Get started by creating your first PPPoE user account.'"
      icon="users"
      color-theme="purple"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      clear-text="Clear Search"
      add-text="Add User"
      @clear="searchQuery = ''"
      @add="openAddUser"
    />
  </DataViewContainer>

  <!-- SlideOverlay for Add User -->
  <SlideOverlay
    v-model="showAddUserOverlay"
    title="Add PPPoE User"
    subtitle="Create a PPPoE customer account"
    icon="Network"
    width="480px"
    :closeOnBackdrop="false"
    @close="closeAddUser"
  >
    <div class="p-6 space-y-4">
      <BaseAlert v-if="addFormError" variant="danger" :title="addFormError" dismissible />
      <form id="addPppoeUserForm" class="space-y-4" @submit.prevent="handleCreateUser">
        <div class="grid grid-cols-1 gap-4">
          <BaseInput v-model="addForm.username" label="Username" placeholder="e.g. john.doe" :error="addFieldErrors.username" required autocomplete="off" />
          <BaseSelect v-model="addForm.router_id" label="Router" placeholder="Select a router" :error="addFieldErrors.router_id" required>
            <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
          </BaseSelect>
          <BaseSelect v-model="addForm.package_id" label="Package" placeholder="Select a PPPoE package" :error="addFieldErrors.package_id" required>
            <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </BaseSelect>
          <BaseInput v-model="addForm.simultaneous_use" type="number" label="Simultaneous Use" placeholder="1" :error="addFieldErrors.simultaneous_use" required />
        </div>
      </form>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          :disabled="addSubmitting"
          @click="closeAddUser"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="addPppoeUserForm"
          :disabled="addSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ addSubmitting ? 'Creating...' : 'Create User' }}
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- SlideOverlay for Edit User -->
  <SlideOverlay
    v-model="showEditUserOverlay"
    title="Edit PPPoE User"
    subtitle="Update PPPoE account settings"
    icon="Network"
    width="480px"
    :closeOnBackdrop="false"
    @close="closeEditUser"
  >
    <div class="p-6 space-y-4">
      <BaseAlert v-if="editFormError" variant="danger" :title="editFormError" dismissible />
      <form id="editPppoeUserForm" class="space-y-4" @submit.prevent="handleUpdateUser">
        <div class="grid grid-cols-1 gap-4">
          <BaseInput :modelValue="editingUser?.username" label="Username" disabled />
          <BaseSelect v-model="editForm.router_id" label="Router" placeholder="Select a router" :error="editFieldErrors.router_id" required>
            <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
          </BaseSelect>
          <BaseSelect v-model="editForm.package_id" label="Package" placeholder="Select a PPPoE package" :error="editFieldErrors.package_id" required>
            <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </BaseSelect>
          <BaseInput v-model="editForm.simultaneous_use" type="number" label="Simultaneous Use" placeholder="1" :error="editFieldErrors.simultaneous_use" required />
          <BaseSelect v-model="editForm.status" label="Status" placeholder="Select status" :error="editFieldErrors.status" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="blocked">Blocked</option>
            <option value="expired">Expired</option>
          </BaseSelect>
        </div>
      </form>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          type="button"
          :disabled="editSubmitting"
          @click="closeEditUser"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50"
        >
          Cancel
        </button>
        <button
          type="submit"
          form="editPppoeUserForm"
          :disabled="editSubmitting"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors disabled:opacity-50"
        >
          {{ editSubmitting ? 'Saving...' : 'Save Changes' }}
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- SlideOverlay for Password Modal -->
  <SlideOverlay
    v-model="showPasswordModal"
    title="PPPoE User Created"
    subtitle="Account credentials generated successfully"
    icon="Key"
    width="480px"
    :closeOnBackdrop="false"
    :closeOnEscape="false"
  >
    <div class="p-6 space-y-4">
      <div>
        <div class="text-sm font-medium text-slate-700">Username</div>
        <div class="mt-1 text-sm text-slate-900 font-mono">{{ createdUser?.username }}</div>
      </div>
      <div>
        <div class="text-sm font-medium text-slate-700">Generated Password</div>
        <div class="mt-1 flex items-center gap-2">
          <div class="flex-1 text-sm text-slate-900 font-mono bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">{{ generatedPassword }}</div>
          <BaseButton variant="secondary" size="sm" @click="copyPassword">Copy</BaseButton>
        </div>
        <div class="mt-2 text-xs text-slate-500">This password is shown only once. Store it securely.</div>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="finishCreateUser"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors"
        >
          Done
        </button>
      </div>
    </template>
  </SlideOverlay>

  <!-- SlideOverlay for User Details -->
  <SlideOverlay
    v-model="showUserDetailsModal"
    title="PPPoE User Details"
    subtitle="View PPPoE account information"
    icon="Network"
    width="480px"
    :closeOnBackdrop="true"
  >
    <div class="p-6 space-y-5">
      <div class="bg-slate-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Account Information</h4>
        <div class="mb-4 p-3 bg-white rounded-lg border border-slate-200">
          <div class="text-xs text-slate-500 mb-1">PPPoE Username</div>
          <div class="text-lg font-mono font-semibold text-slate-900">{{ selectedUser?.username || 'N/A' }}</div>
          <div class="text-sm font-mono text-indigo-600 mt-1">ACC({{ selectedUser?.account_number || 'N/A' }})</div>
        </div>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500">Status</div>
            <div class="mt-1"><EntityStatusBadge :status="selectedUser?.status || 'inactive'" size="sm" /></div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Created</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedUser?.created_at) }}</div>
          </div>
        </div>
      </div>

      <div class="bg-blue-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Credentials</h4>
        <div class="flex items-center justify-between">
          <div class="flex-1">
            <div class="text-xs text-slate-500">Password</div>
            <div class="flex items-center gap-2 mt-1">
              <div class="text-sm font-mono text-slate-900 bg-white border border-slate-200 rounded px-3 py-1.5 flex-1">{{ showPasswordValue ? userPassword : '••••••••••••' }}</div>
              <BaseButton v-if="!showPasswordValue" variant="secondary" size="sm" @click="handleViewPassword" :loading="loadingPassword"><Eye class="w-4 h-4 mr-1" /> View</BaseButton>
              <BaseButton v-else variant="ghost" size="sm" @click="hidePassword"><EyeOff class="w-4 h-4" /></BaseButton>
              <BaseButton variant="secondary" size="sm" @click="handleResetPassword"><Key class="w-4 h-4 mr-1" /> Reset</BaseButton>
            </div>
          </div>
        </div>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div><div class="text-xs text-slate-500">Router</div><div class="text-sm text-slate-900">{{ selectedUser?.router?.name || 'N/A' }}</div></div>
        <div><div class="text-xs text-slate-500">Package</div><div class="text-sm text-slate-900">{{ selectedUser?.package?.name || 'N/A' }}</div><div class="text-xs text-slate-500">{{ formatPackageSpeed(selectedUser?.package) }}</div></div>
        <div><div class="text-xs text-slate-500">Rate Limit</div><div class="text-sm text-slate-900">{{ selectedUser?.rate_limit || 'Not set' }}</div></div>
        <div><div class="text-xs text-slate-500">Simultaneous Sessions</div><div class="text-sm text-slate-900">{{ selectedUser?.simultaneous_use || 1 }}</div></div>
      </div>

      <div class="bg-amber-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Subscription & Payment</h4>
        <div class="grid grid-cols-2 gap-4">
          <div><div class="text-xs text-slate-500">Expiry Date</div><div class="text-sm text-slate-900">{{ formatDate(selectedUser?.expires_at) }}</div></div>
          <div><div class="text-xs text-slate-500">Days to Expiry</div><div :class="['text-sm font-semibold', getDaysToExpiryClass(selectedUser?.days_to_expiry)]">{{ formatDaysToExpiry(selectedUser?.days_to_expiry) }}</div></div>
          <div><div class="text-xs text-slate-500">Payment Status</div><div class="mt-1"><EntityStatusBadge :status="selectedUser?.payment_status || 'unpaid'" size="sm" /></div></div>
          <div><div class="text-xs text-slate-500">Last Payment</div><div class="text-sm text-slate-900">{{ formatDate(selectedUser?.last_payment_date) || 'Never' }}</div></div>
          <div><div class="text-xs text-slate-500">Next Payment Due</div><div class="text-sm text-slate-900">{{ formatDate(selectedUser?.next_payment_due) }}</div></div>
          <div><div class="text-xs text-slate-500">Amount Due</div><div class="text-sm font-semibold text-slate-900">KES {{ selectedUser?.amount_due || 0 }}</div></div>
        </div>
      </div>
    </div>
    <template #footer>
      <div class="flex gap-3">
        <button
          @click="showUserDetailsModal = false"
          class="flex-1 px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50"
        >
          Close
        </button>
        <button
          @click="handleEdit(selectedUser)"
          class="flex-1 px-4 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 rounded-lg transition-colors"
        >
          Edit
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { X, Eye, EyeOff, Key } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import MobileDataCard from '@/modules/common/components/base/MobileDataCard.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import { usePppoeUsers } from '@/modules/tenant/composables/data/usePppoeUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

// Data management
const { users, loading, error, activeUsers, inactiveUsers, totalUsers, fetchUsers, createUser, updateUser, viewPassword, resetPassword, toggleUserStatus } = usePppoeUsers()
const { packages, fetchPackages } = usePackages()
const { routers, fetchRouters } = useRouters()

const pppoePackages = computed(() => (packages.value || []).filter((p) => p?.type === 'pppoe'))

// Filtering
const { filters, searchQuery, filteredData, hasActiveFilters } = useFilters(users, { status: '', package_id: '' })

// Pagination
const { currentPage, itemsPerPage, paginatedData, totalPages } = usePagination(filteredData, 10)

// Overlay state
const showAddUserOverlay = ref(false)
const addSubmitting = ref(false)
const addFormError = ref('')
const addFieldErrors = reactive({ username: '', package_id: '', router_id: '', simultaneous_use: '' })
const addForm = reactive({ username: '', package_id: '', router_id: '', simultaneous_use: 1 })

const showPasswordModal = ref(false)
const generatedPassword = ref('')
const createdUser = ref(null)

const showUserDetailsModal = ref(false)
const selectedUser = ref(null)
const showPasswordValue = ref(false)
const userPassword = ref('')
const loadingPassword = ref(false)

const showEditUserOverlay = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editingUser = ref(null)
const editFieldErrors = reactive({ package_id: '', router_id: '', simultaneous_use: '', status: '' })
const editForm = reactive({ package_id: '', router_id: '', simultaneous_use: 1, status: 'active' })

// Helpers
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  if (typeof date === 'number' || (typeof date === 'string' && /^\d+$/.test(date))) {
    const timestamp = typeof date === 'string' ? parseInt(date, 10) : date
    return new Date(timestamp * 1000).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
  }
  return new Date(date).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true })
}

const formatPackageSpeed = (pkg) => {
  if (!pkg) return 'N/A'
  const down = pkg.download_speed ? String(pkg.download_speed).trim() : ''
  const up = pkg.upload_speed ? String(pkg.upload_speed).trim() : ''
  if (down && up) return `${down} / ${up}`
  if (down) return down
  if (up) return up
  return 'N/A'
}

const getDaysToExpiryClass = (days) => {
  if (days === null || days === undefined) return 'text-slate-500'
  if (days < 0) return 'text-red-600'
  if (days <= 7) return 'text-amber-600'
  return 'text-green-600'
}

const formatDaysToExpiry = (days) => {
  if (days === null || days === undefined) return 'N/A'
  if (days < 0) return `Expired ${Math.abs(days)} days ago`
  if (days === 0) return 'Expires today'
  return `${days} days`
}

const getUserActions = (user) => [
  { label: 'View', onClick: () => openUserDetails(user), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: 'Edit', onClick: () => handleEdit(user), class: 'text-slate-700 bg-slate-100 hover:bg-slate-200' },
  { label: user.status === 'blocked' ? 'Unblock' : 'Block', onClick: () => handleToggleStatus(user), class: user.status === 'blocked' ? 'text-green-700 bg-green-50 hover:bg-green-100' : 'text-amber-700 bg-amber-50 hover:bg-amber-100' }
]

// Form handlers
const resetAddErrors = () => {
  addFormError.value = ''
  Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = '')
}

const resetEditErrors = () => {
  editFormError.value = ''
  Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = '')
}

const openAddUser = () => {
  resetAddErrors()
  Object.keys(addForm).forEach(k => addForm[k] = k === 'simultaneous_use' ? 1 : '')
  showAddUserOverlay.value = true
}

const closeAddUser = () => {
  showAddUserOverlay.value = false
}

const handleCreateUser = async () => {
  resetAddErrors()
  addSubmitting.value = true
  try {
    const { user, generatedPassword: password } = await createUser({
      username: String(addForm.username || '').trim(),
      package_id: addForm.package_id,
      router_id: addForm.router_id,
      simultaneous_use: Number(addForm.simultaneous_use || 1)
    })
    createdUser.value = user
    generatedPassword.value = password || ''
    showPasswordModal.value = true
    closeAddUser()
  } catch (err) {
    const status = err.response?.status
    const message = err.response?.data?.message || err.response?.data?.error || 'Failed to create PPPoE user'
    if (status === 422) {
      const errors = err.response?.data?.errors || {}
      Object.keys(addFieldErrors).forEach(k => addFieldErrors[k] = errors[k]?.[0] || '')
    }
    addFormError.value = message
  } finally {
    addSubmitting.value = false
  }
}

const copyPassword = async () => {
  if (!generatedPassword.value) return
  try {
    await navigator.clipboard.writeText(generatedPassword.value)
  } catch (e) {
    addFormError.value = 'Failed to copy password'
  }
}

const finishCreateUser = async () => {
  showPasswordModal.value = false
  generatedPassword.value = ''
  createdUser.value = null
  await fetchUsers()
}

const openUserDetails = (user) => {
  selectedUser.value = user
  showPasswordValue.value = false
  userPassword.value = ''
  showUserDetailsModal.value = true
}

const handleViewPassword = async () => {
  if (!selectedUser.value) return
  loadingPassword.value = true
  try {
    const data = await viewPassword(selectedUser.value.id)
    userPassword.value = data?.password || ''
    showPasswordValue.value = true
  } catch (err) {
    console.error('Failed to view password:', err)
  } finally {
    loadingPassword.value = false
  }
}

const hidePassword = () => {
  showPasswordValue.value = false
  userPassword.value = ''
}

const handleResetPassword = async () => {
  if (!selectedUser.value) return
  const confirmed = await confirmStore.open({
    title: 'Reset Password',
    message: `Are you sure you want to reset the password for ${selectedUser.value.username}?`,
    confirmText: 'Reset',
    cancelText: 'Cancel',
    variant: 'warning'
  })
  if (confirmed) {
    try {
      const { generatedPassword: newPassword } = await resetPassword(selectedUser.value.id)
      userPassword.value = newPassword || ''
      showPasswordValue.value = true
    } catch (err) {
      console.error('Failed to reset password:', err)
    }
  }
}

const handleEdit = (user) => {
  if (!user) return
  showUserDetailsModal.value = false
  editingUser.value = user
  editForm.package_id = user.package_id || user.package?.id || ''
  editForm.router_id = user.router_id || user.router?.id || ''
  editForm.simultaneous_use = Number(user.simultaneous_use || 1)
  editForm.status = user.status || 'active'
  resetEditErrors()
  showEditUserOverlay.value = true
}

const closeEditUser = () => {
  showEditUserOverlay.value = false
  editingUser.value = null
}

const handleUpdateUser = async () => {
  if (!editingUser.value) return
  resetEditErrors()
  editSubmitting.value = true
  try {
    await updateUser(editingUser.value.id, {
      package_id: editForm.package_id,
      router_id: editForm.router_id,
      simultaneous_use: Number(editForm.simultaneous_use || 1),
      status: String(editForm.status || 'active')
    })
    closeEditUser()
    await fetchUsers()
  } catch (err) {
    const status = err.response?.status
    const message = err.response?.data?.message || err.response?.data?.error || 'Failed to update PPPoE user'
    if (status === 422) {
      const errors = err.response?.data?.errors || {}
      Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = errors[k]?.[0] || '')
    }
    editFormError.value = message
  } finally {
    editSubmitting.value = false
  }
}

const handleToggleStatus = async (user) => {
  const action = user.status === 'blocked' ? 'unblock' : 'block'
  const confirmed = await confirmStore.open({
    title: 'Confirm Action',
    message: `Are you sure you want to ${action} ${user.name || user.username}?`,
    confirmText: 'OK',
    cancelText: 'Cancel',
    variant: user.status === 'blocked' ? 'success' : 'warning'
  })
  if (confirmed) {
    try {
      await toggleUserStatus(user.id, user.status !== 'blocked')
      await fetchUsers()
    } catch (err) {
      console.error(`Failed to ${action} user:`, err)
    }
  }
}

// Lifecycle
onMounted(() => {
  fetchUsers()
  fetchPackages()
  fetchRouters()
})
</script>

<style scoped>
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
