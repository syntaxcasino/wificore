<template>
  <DataViewContainer
    title="Inactive Sessions"
    subtitle="Disconnected PPPoE session history — grouped by user"
    color-theme="slate"
    v-model:search-model="localSearch"
    search-placeholder="Search by username..."
    :stats="[
      { color: 'bg-slate-500', value: aggregatedUsers.length },
      { color: 'bg-rose-500', value: total }
    ]"
    :total="total"
    :loading="loading"
    :showAdd="false"
    @refresh="onRefresh"
    @search-clear="localSearch = ''"
  >
    <template #icon>
      <WifiOff class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <template #filters>
      <BaseSelect v-model="filters.router" placeholder="All Routers" class="w-44">
        <option value="">All Routers</option>
        <option v-for="r in routers" :key="r.id" :value="r.id">{{ r.name }}</option>
      </BaseSelect>
      <button v-if="hasActiveFilters" @click="clearFilters"
        class="text-xs text-slate-500 hover:text-slate-800 font-medium flex items-center gap-1">
        <X class="w-3 h-3" /> Clear
      </button>
    </template>

    <div v-if="error" class="flex flex-col items-center justify-center gap-3 p-10 text-rose-500">
      <WifiOff class="w-10 h-10 opacity-50" />
      <p class="text-sm text-center">{{ error }}</p>
    </div>

    <DataSkeleton v-else-if="loading" :count="6" />

    <div v-else-if="filteredAggregated.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Desktop table -->
      <div class="hidden md:flex bg-white border border-slate-200 rounded-lg flex-col min-h-0 flex-1 overflow-hidden shadow-sm">
        <div class="bg-slate-50 border-b border-slate-200 flex-shrink-0">
          <table class="w-full table-fixed">
            <thead>
              <tr>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[28%]">User</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[12%]">Router</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[11%]">Sessions</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[15%]">Total Usage</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[20%]">Last Disconnected</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[14%]">Last Reason</th>
              </tr>
            </thead>
          </table>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full table-fixed">
            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="u in paginatedData" :key="u.username"
                class="hover:bg-indigo-50/40 transition-colors cursor-pointer group"
                @click="openDetails(u)"
              >
                <td class="px-5 py-3.5 w-[28%]">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center text-white text-xs font-bold shadow-sm flex-shrink-0">
                      {{ u.initials }}
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-semibold text-slate-800 truncate">{{ u.username }}</div>
                      <div class="text-xs text-slate-400 truncate">{{ u.phone || 'No phone' }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[12%]">
                  <span class="text-sm text-slate-600 truncate">{{ u.routerName || 'N/A' }}</span>
                </td>
                <td class="px-5 py-3.5 w-[11%]">
                  <span class="inline-flex items-center justify-center min-w-[26px] h-5 px-2 rounded-full text-xs font-bold bg-rose-100 text-rose-700">{{ u.count }}</span>
                </td>
                <td class="px-5 py-3.5 w-[15%]">
                  <div class="text-sm font-medium text-slate-700">{{ formatBytes(u.totalBytes) }}</div>
                  <div class="text-xs text-slate-400">{{ formatDuration(u.totalDuration) }}</div>
                </td>
                <td class="px-5 py-3.5 w-[20%]">
                  <div class="flex items-center gap-1.5">
                    <span class="w-1.5 h-1.5 rounded-full bg-rose-400 flex-shrink-0"></span>
                    <div>
                      <div class="text-sm text-slate-700">{{ formatDateShort(u.lastDisconnected) }}</div>
                      <div class="text-xs text-slate-400">{{ formatTimeOnly(u.lastDisconnected) }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[14%]">
                  <div class="flex items-center justify-between gap-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium truncate max-w-[90px]" :class="reasonClass(u.lastReason)">
                      {{ formatTerminateCause(u.lastReason) }}
                    </span>
                    <button class="p-1.5 rounded-lg text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100 flex-shrink-0"
                      @click.stop="openDetails(u)" title="View history">
                      <Eye class="w-3.5 h-3.5" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Mobile cards -->
      <div class="md:hidden space-y-2 overflow-y-auto flex-1 min-h-0 px-1">
        <div
          v-for="u in paginatedData" :key="u.username"
          class="bg-white rounded-xl border border-slate-200 p-4 cursor-pointer hover:border-indigo-300 shadow-sm transition-all"
          @click="openDetails(u)"
        >
          <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-slate-400 to-slate-600 flex items-center justify-center text-white font-bold text-sm flex-shrink-0">{{ u.initials }}</div>
            <div class="flex-1 min-w-0">
              <div class="font-semibold text-slate-800 text-sm">{{ u.username }}</div>
              <div class="text-xs text-slate-400">{{ u.phone || 'No phone' }}</div>
            </div>
            <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-rose-100 text-rose-700">{{ u.count }}×</span>
          </div>
          <div class="grid grid-cols-2 gap-1.5 text-xs text-slate-600">
            <div><span class="text-slate-400">Last off: </span>{{ formatDateShort(u.lastDisconnected) }}</div>
            <div><span class="text-slate-400">Router: </span>{{ u.routerName || 'N/A' }}</div>
            <div><span class="text-slate-400">Usage: </span>{{ formatBytes(u.totalBytes) }}</div>
            <div><span class="text-slate-400">Reason: </span>{{ formatTerminateCause(u.lastReason) }}</div>
          </div>
        </div>
      </div>

      <DataPagination
        v-model:current-page="currentPage"
        v-model:items-per-page="itemsPerPage"
        :total-pages="totalPages"
        :total-items="filteredAggregated.length"
        item-name="users"
        class="mt-auto"
      />
    </div>

    <DataEmptyState
      v-else
      :title="localSearch || hasActiveFilters ? 'No Matches Found' : 'No Inactive Sessions'"
      :description="localSearch || hasActiveFilters ? 'No users match your criteria.' : 'No disconnected PPPoE sessions on record.'"
      icon="WifiOff"
      color-theme="slate"
      :showActions="false"
      :show-clear="!!(localSearch || hasActiveFilters)"
      clear-text="Clear"
      @clear="clearFilters"
    />
  </DataViewContainer>

  <!-- Session History Overlay -->
  <SlideOverlay
    v-model="showDetails"
    :title="selectedUser ? selectedUser.username : 'History'"
    :subtitle="selectedUser ? `${selectedUser.count} disconnection${selectedUser.count !== 1 ? 's' : ''} on record` : ''"
    icon="History"
    gradient
    width="60%"
    no-padding
    @close="showDetails = false"
  >
    <div v-if="selectedUser" class="flex flex-col h-full">
      <!-- Stats banner -->
      <div class="grid grid-cols-3 divide-x divide-white/10 bg-gradient-to-r from-indigo-700 to-blue-600 flex-shrink-0">
        <div class="px-4 py-4 text-center">
          <div class="text-2xl font-bold text-white tabular-nums">{{ selectedUser.count }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Sessions</div>
        </div>
        <div class="px-4 py-4 text-center">
          <div class="text-xl font-bold text-white">{{ formatBytes(selectedUser.totalBytes) }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Data Used</div>
        </div>
        <div class="px-4 py-4 text-center">
          <div class="text-xl font-bold text-white">{{ formatDuration(selectedUser.totalDuration) }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Online Time</div>
        </div>
      </div>

      <!-- User info -->
      <div class="flex items-center gap-4 px-6 py-4 bg-slate-50 border-b border-slate-200 flex-shrink-0">
        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-indigo-400 to-blue-600 flex items-center justify-center text-white font-bold text-base shadow-md flex-shrink-0">
          {{ selectedUser.initials }}
        </div>
        <div class="flex-1 min-w-0">
          <div class="text-sm font-bold text-slate-800">{{ selectedUser.username }}</div>
          <div class="text-xs text-slate-400 mt-0.5">{{ selectedUser.phone || 'No phone on record' }}</div>
        </div>
        <div class="text-right flex-shrink-0">
          <div class="text-[11px] text-slate-400 uppercase tracking-wide">Last seen</div>
          <div class="text-sm font-semibold text-slate-700">{{ formatDateShort(selectedUser.lastDisconnected) }}</div>
          <div class="text-xs text-slate-400">{{ formatTimeOnly(selectedUser.lastDisconnected) }}</div>
        </div>
      </div>

      <!-- History list -->
      <div class="flex-1 overflow-y-auto min-h-0 bg-white">
        <div class="sticky top-0 z-10 px-6 py-2.5 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
          <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
            <History class="w-3.5 h-3.5" /> Disconnection History
          </span>
          <span class="text-[11px] text-slate-400">Newest first</span>
        </div>

        <div class="divide-y divide-slate-100">
          <div
            v-for="(s, idx) in selectedUser.sessions"
            :key="s.id"
            class="px-6 py-4 hover:bg-slate-50/60 transition-colors"
          >
            <div class="flex items-start gap-4">
              <div
                class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 mt-0.5 border-2"
                :class="idx === 0 ? 'bg-rose-100 text-rose-600 border-rose-200' : 'bg-slate-100 text-slate-400 border-slate-200'"
              >{{ selectedUser.count - idx }}</div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center flex-wrap gap-x-4 gap-y-1 mb-2">
                  <span class="inline-flex items-center gap-1 text-xs font-medium" :class="reasonClass(s.terminate_cause)">
                    <span class="w-1.5 h-1.5 rounded-full bg-current"></span>
                    {{ formatTerminateCause(s.terminate_cause) }}
                  </span>
                  <span class="text-xs text-slate-400 flex items-center gap-1">
                    <Clock class="w-3 h-3" /> {{ formatDuration(s.duration) }}
                  </span>
                  <span class="text-xs text-slate-400 flex items-center gap-1">
                    <Database class="w-3 h-3" /> {{ formatBytes((s.input_octets || 0) + (s.output_octets || 0)) }}
                  </span>
                </div>
                <div class="grid grid-cols-2 gap-x-6 gap-y-1 text-xs">
                  <div class="flex gap-1.5">
                    <span class="text-slate-400 w-20 flex-shrink-0">Connected</span>
                    <span class="font-medium text-slate-700">{{ formatDateTime(s.connected_at) }}</span>
                  </div>
                  <div class="flex gap-1.5">
                    <span class="text-slate-400 w-20 flex-shrink-0">Disconn.</span>
                    <span class="font-medium text-rose-600">{{ formatDateTime(s.disconnected_at) }}</span>
                  </div>
                  <div class="flex gap-1.5">
                    <span class="text-slate-400 w-20 flex-shrink-0">IP</span>
                    <span class="font-mono text-slate-700">{{ s.ip_address || 'N/A' }}</span>
                  </div>
                  <div class="flex gap-1.5">
                    <span class="text-slate-400 w-20 flex-shrink-0">MAC</span>
                    <span class="font-mono text-slate-700">{{ s.mac_address || 'N/A' }}</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </SlideOverlay>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { WifiOff, X, Eye, History, Clock, Database } from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useInactivePppoeSessions } from '@/modules/tenant/composables/useInactivePppoeSessions'

const {
  sessions, loading, error, total, routers,
  fetchSessions, formatBytes, formatDuration, formatDateTime, formatTerminateCause,
} = useInactivePppoeSessions()

// ── Aggregation ──────────────────────────────────────────────────────────────
const aggregatedUsers = computed(() => {
  const map = new Map()
  for (const s of sessions.value) {
    if (!map.has(s.username)) {
      map.set(s.username, {
        username:        s.username,
        initials:        (s.username || '?').slice(0, 2).toUpperCase(),
        phone:           s.user?.phone ?? null,
        routerName:      s.router_name ?? null,
        count:           0,
        totalBytes:      0,
        totalDuration:   0,
        lastDisconnected: null,
        lastReason:      null,
        sessions:        [],
      })
    }
    const u = map.get(s.username)
    u.count++
    u.totalBytes    += (s.input_octets || 0) + (s.output_octets || 0)
    u.totalDuration += s.duration || 0
    u.sessions.push(s)
    const dt = s.disconnected_at ? new Date(s.disconnected_at) : null
    if (dt && (!u.lastDisconnected || dt > new Date(u.lastDisconnected))) {
      u.lastDisconnected = s.disconnected_at
      u.lastReason       = s.terminate_cause
    }
  }
  return Array.from(map.values()).sort((a, b) =>
    (b.lastDisconnected ? new Date(b.lastDisconnected) : 0) -
    (a.lastDisconnected ? new Date(a.lastDisconnected) : 0)
  )
})

// ── Filters & pagination ─────────────────────────────────────────────────────
const localSearch  = ref('')
const currentPage  = ref(1)
const itemsPerPage = ref(25)
const filters      = ref({ router: '' })

const filteredAggregated = computed(() => {
  let list = aggregatedUsers.value
  if (localSearch.value) {
    const q = localSearch.value.toLowerCase()
    list = list.filter(u => u.username?.toLowerCase().includes(q))
  }
  if (filters.value.router) {
    list = list.filter(u =>
      u.sessions.some(s => String(s.router_id ?? '') === String(filters.value.router))
    )
  }
  return list
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredAggregated.value.length / itemsPerPage.value)))
const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage.value
  return filteredAggregated.value.slice(start, start + itemsPerPage.value)
})
const hasActiveFilters = computed(() => !!filters.value.router)

watch([localSearch, itemsPerPage, () => filters.value.router], () => { currentPage.value = 1 })

const clearFilters = () => { filters.value = { router: '' }; localSearch.value = '' }
const onRefresh = () => fetchSessions({ per_page: 200 })

// ── Detail overlay ───────────────────────────────────────────────────────────
const showDetails  = ref(false)
const selectedUser = ref(null)

const openDetails = (user) => {
  selectedUser.value = {
    ...user,
    sessions: [...user.sessions].sort((a, b) =>
      (b.disconnected_at ? new Date(b.disconnected_at) : 0) -
      (a.disconnected_at ? new Date(a.disconnected_at) : 0)
    ),
  }
  showDetails.value = true
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const formatDateShort = (d) => {
  if (!d) return 'N/A'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}
const formatTimeOnly = (d) => {
  if (!d) return ''
  return new Date(d).toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit' })
}

const reasonClass = (cause) => {
  if (!cause) return 'bg-slate-100 text-slate-600'
  if (cause === 'User-Request')    return 'bg-blue-100 text-blue-700'
  if (cause.includes('Timeout'))   return 'bg-amber-100 text-amber-700'
  if (cause.includes('Error') || cause.includes('Lost')) return 'bg-rose-100 text-rose-700'
  if (cause.includes('Admin') || cause.includes('NAS'))  return 'bg-purple-100 text-purple-700'
  return 'bg-slate-100 text-slate-600'
}

onMounted(() => fetchSessions({ per_page: 200 }))
</script>
