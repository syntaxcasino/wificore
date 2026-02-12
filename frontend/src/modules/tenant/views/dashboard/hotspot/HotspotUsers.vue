<template>
  <PageContainer>
    <!-- Header with Filters -->
    <div class="bg-white border-b border-slate-200">
      <!-- Title, Filters and Actions -->
      <div class="px-3 py-3 sm:px-6 sm:py-4">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3 sm:gap-4 flex-wrap">
          <!-- Title + Action row -->
          <div class="flex items-center justify-between sm:justify-start gap-3">
            <h1 class="text-lg sm:text-2xl font-bold text-slate-900">Hotspot Users</h1>
            <div class="flex sm:hidden items-center gap-2">
              <BaseButton @click="handleRefresh" variant="ghost" size="sm" :disabled="loading">
                <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
              </BaseButton>
              <BaseButton @click="$router.push('/dashboard/hotspot/vouchers')" variant="primary" size="sm">
                <Ticket class="w-4 h-4 mr-1" />
                Voucher
              </BaseButton>
            </div>
          </div>
          
          <!-- Search Box -->
          <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
            <BaseSearch v-model="searchQuery" placeholder="Search hotspot users..." />
          </div>
          
          <!-- Filters Group -->
          <div class="flex items-center gap-2 flex-wrap">
            <BaseSelect v-model="filters.status" placeholder="All Status" class="w-28 sm:w-36">
              <option value="">All Status</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="expired">Expired</option>
            </BaseSelect>
            
            <BaseSelect v-model="filters.package" placeholder="All Packages" class="w-32 sm:w-40">
              <option value="">All Packages</option>
              <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
            </BaseSelect>
            
            <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
              <X class="w-4 h-4 mr-1" />
              Clear
            </BaseButton>
          </div>
          
          <!-- Stats Badges -->
          <div class="flex items-center gap-2 flex-wrap">
            <BaseBadge variant="info">{{ totalUsers }} Total</BaseBadge>
            <BaseBadge variant="success" dot pulse>{{ activeUsers.length }} Active</BaseBadge>
            <BaseBadge variant="warning">{{ expiredUsers.length }} Expired</BaseBadge>
          </div>
          
          <!-- Action Buttons (desktop) -->
          <div class="hidden sm:flex ml-auto items-center gap-2">
            <BaseButton @click="handleRefresh" variant="ghost" size="sm" :disabled="loading">
              <RefreshCw class="w-4 h-4" :class="{ 'animate-spin': loading }" />
            </BaseButton>
            <BaseButton @click="$router.push('/dashboard/hotspot/vouchers')" variant="primary">
              <Ticket class="w-4 h-4 mr-1" />
              Create Voucher
            </BaseButton>
          </div>
        </div>
        
        <!-- Subtitle -->
        <p class="text-xs sm:text-sm text-slate-600 mt-2">View and manage hotspot customer accounts (auto-created on payment)</p>
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
          :title="searchQuery ? 'No hotspot users found' : 'No hotspot users yet'"
          :description="searchQuery ? 'No users match your search criteria.' : 'Hotspot users are automatically created when customers make payments.'"
          icon="Wifi"
          actionText="View Vouchers"
          actionIcon="Ticket"
          @action="$router.push('/dashboard/hotspot/vouchers')"
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
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Voucher Code</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Status</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Expiry</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Used</th>
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
                      <div class="w-10 h-10 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-full flex items-center justify-center text-white text-sm font-semibold">
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500">{{ user.phone || 'No phone' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-mono text-slate-900">{{ user.voucher_code || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900">{{ user.package?.name || 'No package' }}</div>
                    <div class="text-xs text-slate-500">{{ user.package?.duration || 'N/A' }}</div>
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
                    {{ formatDate(user.expiry_date) }}
                  </td>
                  <td class="px-6 py-4 text-sm text-slate-600">
                    {{ formatBytes(user.data_used) }}
                  </td>
                  <td class="px-6 py-4 text-right" @click.stop>
                    <div class="flex items-center justify-end gap-1">
                      <BaseButton @click="viewSessions(user)" variant="ghost" size="sm">
                        <Activity class="w-3 h-3" />
                      </BaseButton>
                      <BaseButton 
                        @click="handleDisconnect(user)" 
                        variant="warning" 
                        size="sm" 
                        v-if="user.status === 'active'"
                        :disabled="disconnecting[user.id]"
                      >
                        <template v-if="disconnecting[user.id]">
                          <RefreshCw class="w-3 h-3 animate-spin mr-1" />
                          Disconnecting...
                        </template>
                        <template v-else>
                          <WifiOff class="w-3 h-3 mr-1" />
                          Disconnect
                        </template>
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

    <!-- User Details Slide-Over -->
    <SlideOverlay
      v-model="showUserDetails"
      title="Hotspot User Details"
      subtitle="View user account information and sessions"
      icon="Wifi"
      width="50%"
      @close="closeUserDetails"
    >
      <div v-if="selectedUser" class="space-y-6">
        <!-- User Info Card -->
        <div class="bg-gradient-to-br from-blue-50 to-cyan-50 rounded-xl p-6">
          <div class="flex items-center gap-4">
            <div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-cyan-500 rounded-full flex items-center justify-center text-white text-xl font-bold">
              {{ getUserInitials(selectedUser) }}
            </div>
            <div>
              <h3 class="text-lg font-semibold text-slate-900">{{ selectedUser.name || selectedUser.username }}</h3>
              <p class="text-sm text-slate-600">{{ selectedUser.phone || 'No phone' }}</p>
              <BaseBadge 
                :variant="getStatusVariant(selectedUser.status)" 
                :dot="selectedUser.status === 'active'"
                :pulse="selectedUser.status === 'active'"
                class="mt-2"
              >
                {{ selectedUser.status || 'inactive' }}
              </BaseBadge>
            </div>
          </div>
        </div>

        <!-- User Details Grid -->
        <div class="grid grid-cols-2 gap-4">
          <div class="bg-white rounded-lg border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Voucher Code</p>
            <p class="text-sm font-mono font-medium text-slate-900 mt-1">{{ selectedUser.voucher_code || 'N/A' }}</p>
          </div>
          <div class="bg-white rounded-lg border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Package</p>
            <p class="text-sm font-medium text-slate-900 mt-1">{{ selectedUser.package?.name || 'No package' }}</p>
          </div>
          <div class="bg-white rounded-lg border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Expiry Date</p>
            <p class="text-sm font-medium text-slate-900 mt-1">{{ formatDate(selectedUser.expiry_date) }}</p>
          </div>
          <div class="bg-white rounded-lg border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Data Used</p>
            <p class="text-sm font-medium text-slate-900 mt-1">{{ formatBytes(selectedUser.data_used) }}</p>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="flex items-center gap-3">
          <BaseButton 
            @click="viewUserSessions" 
            variant="secondary" 
            class="flex-1"
          >
            <Activity class="w-4 h-4 mr-2" />
            View Sessions
          </BaseButton>
          <BaseButton 
            v-if="selectedUser.status === 'active'"
            @click="handleDisconnectFromModal" 
            variant="warning" 
            class="flex-1"
            :loading="disconnecting[selectedUser.id]"
          >
            <WifiOff class="w-4 h-4 mr-2" />
            Disconnect
          </BaseButton>
        </div>
      </div>

      <template #footer>
        <div class="flex items-center justify-end">
          <BaseButton variant="secondary" @click="closeUserDetails">
            Close
          </BaseButton>
        </div>
      </template>
    </SlideOverlay>
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Ticket, X, RefreshCw, Activity, Wifi, WifiOff } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
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
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'

import { useFilters } from '@/modules/common/composables/utils/useFilters'
import { usePagination } from '@/modules/common/composables/utils/usePagination'
import { usePackages } from '@/modules/tenant/composables/data/usePackages'
import { useHotspot } from '@/modules/tenant/composables/useHotspot'
import { useBroadcasting } from '@/modules/common/composables/websocket/useBroadcasting'
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()
const { subscribeToPrivateChannel } = useBroadcasting()

// Use the WebSocket-enabled hotspot composable
const {
  users,
  loading,
  error,
  pagination,
  activeUsers,
  expiredUsers,
  totalUsers,
  fetchUsers,
  disconnectUser,
  setPage,
  setPerPage,
} = useHotspot()

const { packages, fetchPackages } = usePackages()

// Local state for disconnect confirmation
const disconnecting = ref({})

// Slide-over state for user details
const showUserDetails = ref(false)
const selectedUser = ref(null)

// Filtering
const { 
  filters, 
  searchQuery, 
  filteredData, 
  hasActiveFilters, 
  clearFilters 
} = useFilters(users, { status: '', package: '' })

// Pagination (local filtering on top of server pagination)
const { 
  currentPage, 
  itemsPerPage, 
  paginatedData, 
  totalPages, 
  paginationInfo 
} = usePagination(filteredData, 10)


// Methods
const getUserInitials = (user) => {
  const name = user.name || user.username || 'U'
  return name.split(' ').map(n => n[0]).join('').toUpperCase().slice(0, 2)
}

const getStatusVariant = (status) => {
  const variants = {
    active: 'success',
    inactive: 'warning',
    expired: 'danger',
    revoked: 'danger',
    disconnecting: 'warning',
  }
  return variants[status] || 'default'
}

const formatDate = (date) => {
  if (!date) return 'N/A'
  return new Date(date).toLocaleDateString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric'
  })
}

const formatBytes = (bytes) => {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

const openUserDetails = (user) => {
  selectedUser.value = user
  showUserDetails.value = true
}

const closeUserDetails = () => {
  showUserDetails.value = false
  selectedUser.value = null
}

const viewUserSessions = () => {
  if (selectedUser.value) {
    console.log('View sessions for:', selectedUser.value)
    // Future: Navigate to sessions filtered by this user
  }
}

const handleDisconnectFromModal = async () => {
  if (selectedUser.value) {
    await handleDisconnect(selectedUser.value)
  }
}

const viewSessions = (user) => {
  openUserDetails(user)
}

const handleDisconnect = async (user) => {
  const confirmed = confirm(`Are you sure you want to disconnect ${user.name || user.username}?`)
  
  if (confirmed) {
    try {
      disconnecting.value[user.id] = true
      await disconnectUser(user.id, 'Admin disconnect')
    } catch (err) {
      console.error('Failed to disconnect user:', err)
    } finally {
      disconnecting.value[user.id] = false
    }
  }
}

const handleRefresh = () => {
  fetchUsers()
}

// Lifecycle - fetch initial data + subscribe to WebSocket events
onMounted(() => {
  fetchUsers()
  fetchPackages()

  // Subscribe to tenant-scoped hotspot events
  const tenantId = authStore.tenantId
  if (tenantId) {
    subscribeToPrivateChannel(`tenant.${tenantId}.hotspot`, {
      '.HotspotUserCreated': () => fetchUsers(),
      '.hotspot.access.granted': () => fetchUsers(),
      '.hotspot.access.revoked': () => fetchUsers(),
      '.hotspot.package.expired': () => fetchUsers(),
      '.hotspot.provisioned': () => fetchUsers(),
      '.hotspot.login.attempted': () => fetchUsers(),
      HotspotUserCreated: () => fetchUsers(),
    })
  }
})
</script>
