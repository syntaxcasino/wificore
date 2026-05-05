<template>
  <PageContainer>
    <!-- Header -->
    <PageHeader
      title="Online Users"
      subtitle="Monitor all currently connected users across Hotspot and PPPoE"
      icon="Users"
      :breadcrumbs="breadcrumbs"
    >
      <template #actions>
        <BaseButton @click="refreshUsers" variant="ghost" size="sm" :loading="loading">
          <RefreshCw class="w-4 h-4 mr-1" :class="{ 'animate-spin': loading }" />
          Refresh
        </BaseButton>
        <BaseButton @click="exportData" variant="secondary">
          <Download class="w-4 h-4 mr-1" />
          Export
        </BaseButton>
      </template>
    </PageHeader>

    <!-- Search and Filters Bar -->
    <div class="px-3 py-3 sm:px-6 sm:py-4 bg-white border-b border-slate-200 dark:border-slate-700">
      <div class="flex flex-col sm:flex-row sm:items-center gap-3 flex-wrap">
        <!-- Search Box -->
        <div class="flex-1 min-w-0 sm:min-w-[250px] max-w-md">
          <BaseSearch v-model="searchQuery" placeholder="Search by username, IP, phone..." />
        </div>
        
        <!-- Filters Group -->
        <div class="flex items-center gap-2">
          <BaseSelect v-model="filters.type" placeholder="All Types" class="w-36">
            <option value="">All Types</option>
            <option value="hotspot">Hotspot</option>
            <option value="pppoe">PPPoE</option>
          </BaseSelect>
          
          <BaseSelect v-model="filters.package" placeholder="All Packages" class="w-40">
            <option value="">All Packages</option>
            <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
          </BaseSelect>
          
          <BaseButton v-if="hasActiveFilters" @click="clearFilters" variant="ghost" size="sm">
            <X class="w-4 h-4 mr-1" />
            Clear
          </BaseButton>
        </div>
        
        <!-- Stats Badges -->
        <div class="ml-auto flex items-center gap-2">
          <BaseBadge variant="success" dot pulse>{{ totalOnline }} Online</BaseBadge>
          <BaseBadge variant="info">{{ hotspotCount }} Hotspot</BaseBadge>
          <BaseBadge variant="purple">{{ pppoeCount }} PPPoE</BaseBadge>
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
            <BaseButton @click="refreshUsers" variant="danger" size="sm">
              <RefreshCw class="w-4 h-4 mr-1" />
              Retry
            </BaseButton>
          </div>
        </BaseAlert>
      </div>

      <!-- Empty State -->
      <div v-else-if="!filteredData.length">
        <BaseEmpty
          :title="searchQuery ? 'No users found' : 'No users online'"
          :description="searchQuery ? 'No users match your search criteria.' : 'There are currently no users connected.'"
          icon="Users"
          :actionText="searchQuery ? 'Clear Search' : 'Refresh'"
          actionIcon="RefreshCw"
          @action="searchQuery ? (searchQuery = '') : refreshUsers()"
        />
      </div>

      <!-- Data Table -->
      <div v-else class="p-6">
        <BaseCard :padding="false">
          <div class="overflow-x-auto">
            <table class="w-full">
              <thead class="bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">User</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Type</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Connection</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Package</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Duration</th>
                  <th class="px-6 py-3 text-left text-xs font-semibold text-slate-700 uppercase tracking-wider">Data Usage</th>
                  <th class="px-6 py-3 text-right text-xs font-semibold text-slate-700 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="user in paginatedData"
                  :key="user.id"
                  class="border-b border-slate-100 hover:bg-slate-50 transition-colors cursor-pointer"
                  @click="viewUserDetails(user)"
                >
                  <td class="px-6 py-4">
                    <div class="flex items-center gap-3">
                      <div 
                        class="w-10 h-10 rounded-full flex items-center justify-center text-white text-sm font-semibold"
                        :class="user.type === 'hotspot' ? 'bg-gradient-to-br from-blue-500 to-cyan-500' : 'bg-gradient-to-br from-purple-500 to-indigo-500'"
                      >
                        {{ getUserInitials(user) }}
                      </div>
                      <div>
                        <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.name || user.username }}</div>
                        <div class="text-xs text-slate-500 dark:text-slate-400">{{ user.phone || 'No phone' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <BaseBadge :variant="user.type === 'hotspot' ? 'info' : 'purple'">
                      <Wifi v-if="user.type === 'hotspot'" class="w-3 h-3 mr-1" />
                      <Network v-else class="w-3 h-3 mr-1" />
                      {{ user.type === 'hotspot' ? 'Hotspot' : 'PPPoE' }}
                    </BaseBadge>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ user.ip_address }}</div>
                    <div class="text-xs text-slate-500 font-mono">{{ user.mac_address || user.calling_station }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm font-medium text-slate-900 dark:text-slate-100">{{ user.package?.name || 'N/A' }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ user.package?.speed || 'N/A' }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatDuration(user.session_duration) }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">{{ formatTime(user.login_time) }}</div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="text-sm text-slate-900">{{ formatBytes(user.total_bytes) }}</div>
                    <div class="text-xs text-slate-500 dark:text-slate-400">
                      <span class="text-green-600">↓ {{ formatBytes(user.bytes_in) }}</span>
                      <span class="mx-1">•</span>
                      <span class="text-blue-600">↑ {{ formatBytes(user.bytes_out) }}</span>
                    </div>
                  </td>
                  <td class="px-6 py-4">
                    <div class="flex items-center justify-end gap-2">
                      <BaseButton @click.stop="viewUserDetails(user)" variant="ghost" size="sm">
                        <Eye class="w-4 h-4" />
                      </BaseButton>
                      <BaseButton @click.stop="disconnectUser(user)" variant="danger" size="sm">
                        <Power class="w-4 h-4" />
                      </BaseButton>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </BaseCard>

        <!-- Pagination -->
        <div class="mt-4 flex items-center justify-between">
          <div class="text-sm text-slate-600 dark:text-slate-400">
            Showing {{ paginationStart }} to {{ paginationEnd }} of {{ filteredData.length }} users
          </div>
          <BasePagination
            v-model="currentPage"
            :total-pages="totalPages"
            :total-items="filteredData.length"
          />
        </div>
      </div>
    </PageContent>

    <!-- User Details Overlay -->
    <SessionDetailsOverlay
      :show="showDetailsOverlay"
      :session="selectedUser"
      :icon="Users"
      @close="closeDetailsOverlay"
      @disconnect="disconnectUser"
    />
  </PageContainer>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RefreshCw, Power, Eye, X, Users, Download, Wifi, Network } from 'lucide-vue-next'
import PageContainer from '@/modules/common/components/layout/templates/PageContainer.vue'
import PageHeader from '@/modules/common/components/layout/templates/PageHeader.vue'
import PageContent from '@/modules/common/components/layout/templates/PageContent.vue'
import BaseButton from '@/modules/common/components/base/BaseButton.vue'
import BaseSearch from '@/modules/common/components/base/BaseSearch.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseBadge from '@/modules/common/components/base/BaseBadge.vue'
import BaseCard from '@/modules/common/components/base/BaseCard.vue'
import BaseLoading from '@/modules/common/components/base/BaseLoading.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'
import BaseEmpty from '@/modules/common/components/base/BaseEmpty.vue'
import BasePagination from '@/modules/common/components/base/BasePagination.vue'
import SessionDetailsOverlay from '@/modules/tenant/components/sessions/SessionDetailsOverlay.vue'
import { useOnlineUsers } from '@/modules/tenant/composables/useOnlineUsers.js'

const breadcrumbs = [
  { label: 'Dashboard', to: '/dashboard' },
  { label: 'Users', to: '/dashboard/users' },
  { label: 'Online Users' }
]

const {
  loading, error, users, selectedUser, showDetailsOverlay,
  totalOnline, hotspotCount, pppoeCount,
  getUserInitials, formatBytes, formatDuration, formatTime, formatDateTime,
  fetchUsers, viewUserDetails, closeDetailsOverlay, disconnectUser, exportData
} = useOnlineUsers()

const searchQuery = ref('')
const currentPage = ref(1)
const itemsPerPage = ref(10)
const filters = ref({ type: '', package: '' })
const packages = ref([])

const filteredData = computed(() => {
  let result = users.value
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    result = result.filter(user =>
      user.username?.toLowerCase().includes(query) ||
      user.name?.toLowerCase().includes(query) ||
      user.phone?.includes(query) ||
      user.ip_address?.includes(query)
    )
  }
  if (filters.value.type) result = result.filter(user => user.type === filters.value.type)
  if (filters.value.package) {
    result = result.filter(user => user.package?.name === packages.value.find(p => p.id === parseInt(filters.value.package))?.name)
  }
  return result
})

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredData.value.slice(start, start + itemsPerPage.value)
})

const totalPages = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const paginationStart = computed(() => (currentPage.value - 1) * itemsPerPage.value + 1)
const paginationEnd = computed(() => Math.min(currentPage.value * itemsPerPage.value, filteredData.value.length))
const hasActiveFilters = computed(() => filters.value.type || filters.value.package)

const clearFilters = () => { filters.value = { type: '', package: '' }; searchQuery.value = '' }
const handleExport = () => exportData(filteredData.value)

onMounted(() => { fetchUsers() })
</script>
