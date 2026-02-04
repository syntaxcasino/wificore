<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="PPPoE Users"
      subtitle="Manage PPPoE customer accounts"
      icon="Network"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="$router.push('/dashboard/pppoe/add-user')" variant="primary">
          <Plus class="w-4 h-4 mr-1" />
          Add PPPoE User
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-6 py-4 bg-white border-b border-slate-200">
      <div class="flex items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-[300px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search PPPoE users..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
            <option value="">All Status</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="blocked">Blocked</option>
            <option value="expired">Expired</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.package_id" placeholder="All Packages" class="w-40">
            <option value="">All Packages</option>
            <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Stats Badges -->
        <div class="ml-auto flex items-center gap-2">
          <BaseBadge variant="info">{{ totalUsers }} Total</BaseBadge>
          <BaseBadge variant="success" dot pulse>{{ activeUsers.length }} Active</BaseBadge>
          <BaseBadge variant="warning">{{ inactiveUsers.length }} Inactive</BaseBadge>
        </div>
      </div>
    </div>

    <!-- Content -->
    <PageContent :padding="false">
      <!-- Loading State -->
      <div v-if="loading" class="p-6">
        <BaseLoading type="table" :rows="5" />
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="p-6">
        <BaseAlert variant="danger" :title="error" dismissible>
          <div class="mt-2">
            <BaseButton @click="fetchUsers" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No PPPoE users found' : 'No PPPoE users yet'"
          :description="searchQuery ? 'No users match your search criteria.' : 'Get started by creating your first PPPoE user account.'"
          icon="Network"
          actionText="Add PPPoE User"
          actionIcon="Plus"
          @action="$router.push('/dashboard/pppoe/add-user')"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Router</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expiry</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in paginatedData"
                  :key="user.id"
                  class="border-b border-slate-100 hover:bg-blue-50/50 transition-colors cursor-pointer"
                  @click="openUserDetails(user)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div class="w-10 h-10 bg-gradient-to-br from-purple-500 to-indigo-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">{{ user.username }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ user.router?.name || 'N/A' }}</div>
                    <div class="text-xs text-slate-500">{{ user.rate_limit || 'No rate limit' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'No package' }}</div>
                    <div class="text-xs text-slate-500">{{ formatPackageSpeed(user.package) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge 
                      :variant="getStatusVariant(user.status)" 
                      :dot="user.status === 'active'"
                      :pulse="user.status === 'active'"
                    >
                      {{ user.status || 'inactive' }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">
                    {{ formatDate(user.expires_at) }}
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <!-- View icon only - primary action -->
                      <BaseButton @click="openUserDetails(user)" variant="ghost" size="sm" title="View Details">
                        <Eye class="w-4 h-4 text-slate-600" />
                      </BaseButton>
                      
                      <!-- 3-dot menu for other actions -->
                      <div class="relative" @click.stop>
                        <BaseButton 
                          @click="toggleActionMenu(user.id)" 
                          variant="ghost" 
                          size="sm"
                          title="More Actions"
                        >
                          <MoreVertical class="w-4 h-4 text-slate-600" />
                        </BaseButton>
                        
                        <!-- Dropdown Menu -->
                        <div 
                          v-if="activeMenuId === user.id" 
                          class="absolute right-0 top-full mt-1 w-44 bg-white border border-slate-200 rounded-lg shadow-lg z-50"
                        >
                          <div class="py-1">
                            <button 
                              @click="handleEdit(user); closeActionMenu()" 
                              class="w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50 flex items-center gap-2"
                            >
                              <Edit2 class="w-4 h-4" />
                              Edit User
                            </button>
                            <button 
                              v-if="user.status !== 'blocked'"
                              @click="handleToggleStatus(user); closeActionMenu()" 
                              class="w-full px-4 py-2 text-left text-sm text-amber-600 hover:bg-amber-50 flex items-center gap-2"
                            >
                              <UserX class="w-4 h-4" />
                              Block User
                            </button>
                            <button 
                              v-else
                              @click="handleToggleStatus(user); closeActionMenu()" 
                              class="w-full px-4 py-2 text-left text-sm text-green-600 hover:bg-green-50 flex items-center gap-2"
                            >
                              <UserCheck class="w-4 h-4" />
                              Unblock User
                            </button>
                            <button 
                              v-if="user.status === 'active'"
                              @click="handleDeactivate(user); closeActionMenu()" 
                              class="w-full px-4 py-2 text-left text-sm text-slate-600 hover:bg-slate-50 flex items-center gap-2"
                            >
                              <PowerOff class="w-4 h-4" />
                              Deactivate
                            </button>
                            <button 
                              v-if="user.status === 'inactive'"
                              @click="handleActivate(user); closeActionMenu()" 
                              class="w-full px-4 py-2 text-left text-sm text-blue-600 hover:bg-blue-50 flex items-center gap-2"
                            >
                              <Power class="w-4 h-4" />
                              Activate
                            </button>
                          </div>
                        </div>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>
      </div>
    </PageContent>

    <!-- Footer -->
    <PageFooter>
      <div class="text-sm text-slate-600">
        Showing {{ paginationInfo.start }} to {{ paginationInfo.end }} of {{ paginationInfo.total }} users
      </div>
      <BasePagination
        v-model="currentPage"
        :total-pages="totalPages"
        :items-per-page="itemsPerPage"
        @update:items-per-page="itemsPerPage = $event"
      />
    </PageFooter>

    <SlideOverlay
      v-model="showAddUserOverlay"
      title="Add PPPoE User"
      subtitle="Create a PPPoE customer account"
      icon="Network"
      width="40%"
      :closeOnBackdrop="false"
      @close="closeAddUser"
    >
      <div class="space-y-4">
        <BaseAlert
          v-if="addFormError"
          variant="danger"
          :title="addFormError"
          dismissible
        />

        <form id="addPppoeUserForm" class="space-y-4" @submit.prevent="handleCreateUser">
          <div class="grid grid-cols-1 gap-4">
            <BaseInput
              v-model="addForm.username"
              label="Username"
              placeholder="e.g. john.doe"
              :error="addFieldErrors.username"
              required
              autocomplete="off"
            />

            <BaseSelect
              v-model="addForm.router_id"
              label="Router"
              placeholder="Select a router"
              :error="addFieldErrors.router_id"
              required
            >
              <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
            </BaseSelect>

            <BaseSelect
              v-model="addForm.package_id"
              label="Package"
              placeholder="Select a PPPoE package"
              :error="addFieldErrors.package_id"
              required
            >
              <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
            </BaseSelect>

            <BaseInput
              v-model="addForm.simultaneous_use"
              type="number"
              label="Simultaneous Use"
              placeholder="1"
              :error="addFieldErrors.simultaneous_use"
              required
            />
          </div>
        </form>
      </div>

      <template #footer>
        <div class="flex items-center justify-end gap-3">
          <BaseButton
            variant="secondary"
            type="button"
            :disabled="addSubmitting"
            @click="closeAddUser"
          >
            Cancel
          </BaseButton>
          <BaseButton
            variant="primary"
            type="submit"
            form="addPppoeUserForm"
            :loading="addSubmitting"
          >
            Create User
          </BaseButton>
        </div>
      </template>
    </SlideOverlay>

    <SlideOverlay
      v-model="showEditUserOverlay"
      title="Edit PPPoE User"
      subtitle="Update PPPoE account settings"
      icon="Network"
      width="40%"
      :closeOnBackdrop="false"
      @close="closeEditUser"
    >
      <div class="space-y-4">
        <BaseAlert
          v-if="editFormError"
          variant="danger"
          :title="editFormError"
          dismissible
        />

        <form id="editPppoeUserForm" class="space-y-4" @submit.prevent="handleUpdateUser">
          <div class="grid grid-cols-1 gap-4">
            <BaseInput
              :modelValue="editingUser?.username"
              label="Username"
              disabled
            />

            <BaseSelect
              v-model="editForm.router_id"
              label="Router"
              placeholder="Select a router"
              :error="editFieldErrors.router_id"
              required
            >
              <option v-for="router in routers" :key="router.id" :value="router.id">{{ router.name }}</option>
            </BaseSelect>

            <BaseSelect
              v-model="editForm.package_id"
              label="Package"
              placeholder="Select a PPPoE package"
              :error="editFieldErrors.package_id"
              required
            >
              <option v-for="pkg in pppoePackages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
            </BaseSelect>

            <BaseInput
              v-model="editForm.simultaneous_use"
              type="number"
              label="Simultaneous Use"
              placeholder="1"
              :error="editFieldErrors.simultaneous_use"
              required
            />

            <BaseSelect
              v-model="editForm.status"
              label="Status"
              placeholder="Select status"
              :error="editFieldErrors.status"
              required
            >
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="blocked">Blocked</option>
              <option value="expired">Expired</option>
            </BaseSelect>
          </div>
        </form>
      </div>

      <template #footer>
        <div class="flex items-center justify-end gap-3">
          <BaseButton
            variant="secondary"
            type="button"
            :disabled="editSubmitting"
            @click="closeEditUser"
          >
            Cancel
          </BaseButton>
          <BaseButton
            variant="primary"
            type="submit"
            form="editPppoeUserForm"
            :loading="editSubmitting"
          >
            Save Changes
          </BaseButton>
        </div>
      </template>
    </SlideOverlay>

    <BaseModal v-model="showPasswordModal" title="PPPoE User Created" :closeOnBackdrop="false">
      <div class="space-y-4">
        <div>
          <div class="text-sm font-medium text-slate-700">Username</div>
          <div class="mt-1 text-sm text-slate-900 font-mono">{{ createdUser?.username }}</div>
        </div>

        <div>
          <div class="text-sm font-medium text-slate-700">Generated Password</div>
          <div class="mt-1 flex items-center gap-2">
            <div class="flex-1 text-sm text-slate-900 font-mono bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
              {{ generatedPassword }}
            </div>
            <BaseButton variant="secondary" size="sm" @click="copyPassword">
              Copy
            </BaseButton>
          </div>
          <div class="mt-2 text-xs text-slate-500">This password is shown only once. Store it securely.</div>
        </div>

        <div class="flex items-center justify-end gap-3">
          <BaseButton variant="primary" @click="finishCreateUser">
            Done
          </BaseButton>
        </div>
      </div>
    </BaseModal>

  <SlideOverlay
    v-model="showUserDetailsModal"
    title="PPPoE User Details"
    subtitle="View PPPoE account information"
    icon="Network"
    width="45%"
    :closeOnBackdrop="true"
  >
    <div class="space-y-5">
      <!-- Account Info Section -->
      <div class="bg-slate-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Account Information</h4>
        
        <!-- Username with Account Number prominently displayed below -->
        <div class="mb-4 p-3 bg-white rounded-lg border border-slate-200">
          <div class="text-xs text-slate-500 mb-1">PPPoE Username</div>
          <div class="text-lg font-mono font-semibold text-slate-900">{{ selectedUser?.username || 'N/A' }}</div>
          <div class="text-sm font-mono text-indigo-600 mt-1">
            ACC({{ selectedUser?.account_number || 'N/A' }})
          </div>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500">Status</div>
            <div class="mt-1">
              <BaseBadge :variant="getStatusVariant(selectedUser?.status)" :dot="selectedUser?.status === 'active'">
                {{ selectedUser?.status || 'inactive' }}
              </BaseBadge>
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Created</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedUser?.created_at) }}</div>
          </div>
        </div>
      </div>

      <!-- Password Section -->
      <div class="bg-blue-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Credentials</h4>
        <div class="flex items-center justify-between">
          <div class="flex-1">
            <div class="text-xs text-slate-500">Password</div>
            <div class="flex items-center gap-2 mt-1">
              <div class="text-sm font-mono text-slate-900 bg-white border border-slate-200 rounded px-3 py-1.5 flex-1">
                {{ showPasswordValue ? userPassword : '••••••••••••' }}
              </div>
              <BaseButton 
                v-if="!showPasswordValue" 
                variant="secondary" 
                size="sm" 
                @click="handleViewPassword"
                :loading="loadingPassword"
              >
                <Eye class="w-4 h-4 mr-1" />
                View
              </BaseButton>
              <BaseButton 
                v-else 
                variant="ghost" 
                size="sm" 
                @click="hidePassword"
              >
                <EyeOff class="w-4 h-4" />
              </BaseButton>
              <BaseButton variant="secondary" size="sm" @click="handleResetPassword">
                <Key class="w-4 h-4 mr-1" />
                Reset
              </BaseButton>
            </div>
          </div>
        </div>
      </div>

      <!-- Service Details -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <div class="text-xs text-slate-500">Router</div>
          <div class="text-sm text-slate-900">{{ selectedUser?.router?.name || 'N/A' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500">Package</div>
          <div class="text-sm text-slate-900">{{ selectedUser?.package?.name || 'N/A' }}</div>
          <div class="text-xs text-slate-500">{{ formatPackageSpeed(selectedUser?.package) }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500">Rate Limit</div>
          <div class="text-sm text-slate-900">{{ selectedUser?.rate_limit || 'Not set' }}</div>
        </div>
        <div>
          <div class="text-xs text-slate-500">Simultaneous Sessions</div>
          <div class="text-sm text-slate-900">{{ selectedUser?.simultaneous_use || 1 }}</div>
        </div>
      </div>

      <!-- Expiry & Payment Section -->
      <div class="bg-amber-50 rounded-lg p-4">
        <h4 class="text-sm font-semibold text-slate-700 mb-3">Subscription & Payment</h4>
        <div class="grid grid-cols-2 gap-4">
          <div>
            <div class="text-xs text-slate-500">Expiry Date</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedUser?.expires_at) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Days to Expiry</div>
            <div :class="['text-sm font-semibold', getDaysToExpiryClass(selectedUser?.days_to_expiry)]">
              {{ formatDaysToExpiry(selectedUser?.days_to_expiry) }}
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Payment Status</div>
            <div class="mt-1">
              <BaseBadge :variant="selectedUser?.payment_status === 'paid' ? 'success' : 'warning'">
                {{ selectedUser?.payment_status || 'unpaid' }}
              </BaseBadge>
            </div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Last Payment</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedUser?.last_payment_date) || 'Never' }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Next Payment Due</div>
            <div class="text-sm text-slate-900">{{ formatDate(selectedUser?.next_payment_due) }}</div>
          </div>
          <div>
            <div class="text-xs text-slate-500">Amount Due</div>
            <div class="text-sm font-semibold text-slate-900">KES {{ selectedUser?.amount_due || 0 }}</div>
          </div>
        </div>
      </div>
    </div>

    <template #footer>
      <div class="flex items-center justify-end gap-3">
        <BaseButton variant="secondary" @click="showUserDetailsModal = false">Close</BaseButton>
        <BaseButton variant="primary" @click="handleEdit(selectedUser)">Edit</BaseButton>
      </div>
    </template>
  </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import { Plus, X, RefreshCw, Edit2, Eye, EyeOff, Key, MoreVertical, Power, PowerOff, UserCheck, UserX } from 'lucide-vue-next'
import { useRoute, useRouter } from 'vue-router'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import PageFooter from '@/modules/common/components/layout/templates/PageFooter.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseModal from '@/modules/common/components/base/BaseModal.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import axios from 'axios'

import { usePppoeUsers } from '@/modules/tenant/composables/data/usePppoeUsers'
import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'
import { useRouters } from '@/modules/tenant/composables/data/useRouters'
import { useConfirmStore } from '@/stores/confirm'

const authStore = useAuthStore()
const confirmStore = useConfirmStore()
const { subscribeToPrivateChannel } = useBroadcasting()
const route = useRoute()
const router = useRouter()

// Data management
const { 
  users, 
  loading, 
  error, 
  activeUsers, 
  inactiveUsers, 
  totalUsers,
  fetchUsers, 
  createUser,
  updateUser,
  viewPassword,
  resetPassword,
  toggleUserStatus 
} = usePppoeUsers()

const { packages, fetchPackages } = usePackages()
const { routers, fetchRouters } = useRouters()

const pppoePackages = computed(() => (packages.value || []).filter((p) => p?.type === 'pppoe'))

// Add user overlay
const showAddUserOverlay = ref(false)
const addSubmitting = ref(false)
const addFormError = ref('')
const addFieldErrors = reactive({
  username: '',
  package_id: '',
  router_id: '',
  simultaneous_use: '',
})

const addForm = reactive({
  username: '',
  package_id: '',
  router_id: '',
  simultaneous_use: 1,
})

const showPasswordModal = ref(false)
const generatedPassword = ref('')
const createdUser = ref(null)

const showUserDetailsModal = ref(false)
const selectedUser = ref(null)
const showPasswordValue = ref(false)
const userPassword = ref('')
const loadingPassword = ref(false)

// 3-dot menu state
const activeMenuId = ref(null)

const showEditUserOverlay = ref(false)
const editSubmitting = ref(false)
const editFormError = ref('')
const editingUser = ref(null)
const editFieldErrors = reactive({
  package_id: '',
  router_id: '',
  simultaneous_use: '',
  status: '',
})

const editForm = reactive({
  package_id: '',
  router_id: '',
  simultaneous_use: 1,
  status: 'active',
})

const resetAddErrors = () => {
  addFormError.value = ''
  addFieldErrors.username = ''
  addFieldErrors.package_id = ''
  addFieldErrors.router_id = ''
  addFieldErrors.simultaneous_use = ''
}

const resetEditErrors = () => {
  editFormError.value = ''
  editFieldErrors.package_id = ''
  editFieldErrors.router_id = ''
  editFieldErrors.simultaneous_use = ''
  editFieldErrors.status = ''
}

const openAddUser = () => {
  showAddUserOverlay.value = true
}

const closeAddUser = () => {
  showAddUserOverlay.value = false
  resetAddErrors()
  addForm.username = ''
  addForm.package_id = ''
  addForm.router_id = ''
  addForm.simultaneous_use = 1

  if (route.name === 'pppoe.add-user') {
    router.push('/dashboard/pppoe/users')
  }
}

const handleCreateUser = async () => {
  resetAddErrors()
  addSubmitting.value = true

  try {
    const payload = {
      username: String(addForm.username || '').trim(),
      package_id: addForm.package_id,
      router_id: addForm.router_id,
      simultaneous_use: Number(addForm.simultaneous_use || 1),
    }

    const { user, generatedPassword: password } = await createUser(payload)
    createdUser.value = user
    generatedPassword.value = password || ''
    showPasswordModal.value = true
  } catch (err) {
    const status = err.response?.status
    const message = err.response?.data?.message || err.response?.data?.error || 'Failed to create PPPoE user'

    if (status === 422) {
      const errors = err.response?.data?.errors || {}
      addFieldErrors.username = errors.username?.[0] || ''
      addFieldErrors.package_id = errors.package_id?.[0] || ''
      addFieldErrors.router_id = errors.router_id?.[0] || ''
      addFieldErrors.simultaneous_use = errors.simultaneous_use?.[0] || ''
      addFormError.value = message
    } else {
      addFormError.value = message
    }
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
  closeAddUser()
  await fetchUsers()
}

// Filtering
const { 
  filters, 
  searchQuery, 
  filteredData, 
  hasActiveFilters, 
  clearFilters 
} = useFilters(users, { status: '', package_id: '' })

// Pagination
const { 
  currentPage, 
  itemsPerPage, 
  paginatedData, 
  totalPages, 
  paginationInfo 
} = usePagination(filteredData, 10)

// Computed
const breadcrumbs = computed(() => [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'PPPoE', to: '/dashboard/pppoe/sessions' },
  { label: 'Users' }
])

// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
    blocked: 'danger',
    expired: 'danger'
  }
  return variants[status] || 'default'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  
  // Handle Unix timestamp (number or numeric string)
  if (typeof date === 'number' || (typeof date === 'string' && /^\d+$/.test(date))) {
    const timestamp = typeof date === 'string' ? parseInt(date, 10) : date
    return new Date(timestamp * 1000).toLocaleString('en-US', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true
    })
  }
  
  // Handle ISO date string
  return new Date(date).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: true
  })
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
    variant: 'warning',
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
  resetEditErrors()
  editingUser.value = null
  editForm.package_id = ''
  editForm.router_id = ''
  editForm.simultaneous_use = 1
  editForm.status = 'active'
}

const handleUpdateUser = async () => {
  if (!editingUser.value) return
  resetEditErrors()
  editSubmitting.value = true

  try {
    const payload = {
      package_id: editForm.package_id,
      router_id: editForm.router_id,
      simultaneous_use: Number(editForm.simultaneous_use || 1),
      status: String(editForm.status || 'active'),
    }

    await updateUser(editingUser.value.id, payload)
    closeEditUser()
    await fetchUsers()
  } catch (err) {
    const status = err.response?.status
    const message = err.response?.data?.message || err.response?.data?.error || 'Failed to update PPPoE user'

    if (status === 422) {
      const errors = err.response?.data?.errors || {}
      editFieldErrors.package_id = errors.package_id?.[0] || ''
      editFieldErrors.router_id = errors.router_id?.[0] || ''
      editFieldErrors.simultaneous_use = errors.simultaneous_use?.[0] || ''
      editFieldErrors.status = errors.status?.[0] || ''
      editFormError.value = message
    } else {
      editFormError.value = message
    }
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
    variant: user.status === 'blocked' ? 'success' : 'warning',
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

// 3-dot menu handlers
const toggleActionMenu = (userId) => {
  activeMenuId.value = activeMenuId.value === userId ? null : userId
}

const closeActionMenu = () => {
  activeMenuId.value = null
}

// Activate/Deactivate handlers
const handleActivate = async (user) => {
  const confirmed = await confirmStore.open({
    title: 'Activate User',
    message: `Are you sure you want to activate ${user.name || user.username}? This will allow them to connect.`,
    confirmText: 'Activate',
    cancelText: 'Cancel',
    variant: 'info',
  })
  
  if (confirmed) {
    try {
      await axios.post(`pppoe/users/${user.id}/activate`)
      await fetchUsers()
    } catch (err) {
      console.error('Failed to activate user:', err)
    }
  }
}

const handleDeactivate = async (user) => {
  const confirmed = await confirmStore.open({
    title: 'Deactivate User',
    message: `Are you sure you want to deactivate ${user.name || user.username}? This will prevent them from connecting.`,
    confirmText: 'Deactivate',
    cancelText: 'Cancel',
    variant: 'warning',
  })
  
  if (confirmed) {
    try {
      await axios.post(`pppoe/users/${user.id}/deactivate`)
      await fetchUsers()
    } catch (err) {
      console.error('Failed to deactivate user:', err)
    }
  }
}

// Close menu when clicking outside
const handleClickOutside = (event) => {
  if (activeMenuId.value && !event.target.closest('.relative')) {
    closeActionMenu()
  }
}


// Lifecycle
onMounted(() => {
  fetchUsers()
  fetchPackages()
  fetchRouters()

  // Add click outside listener for dropdown menu
  document.addEventListener('click', handleClickOutside)

  const tenantId = authStore.tenantId
  if (tenantId) {
    subscribeToPrivateChannel(`tenant.${tenantId}.pppoe-users`, {
      PppoeUserCreated: () => fetchUsers(),
      PppoeUserUpdated: () => fetchUsers(),
      PppoeUserDeleted: () => fetchUsers(),
      '.PppoeUserCreated': () => fetchUsers(),
      '.PppoeUserUpdated': () => fetchUsers(),
      '.PppoeUserDeleted': () => fetchUsers(),
    })
  }
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})

watch(
  () => route.name,
  (name) => {
    if (name === 'pppoe.add-user') {
      openAddUser()
    }
  },
  { immediate: true },
)
</script>
