<template>
  <DataViewContainer
    title="Tenant IP Pools"
    subtitle="Manage IP address pool allocations across tenants"
    color-theme="indigo"
    :search-model="localSearch"
    search-placeholder="Search pools or tenants..."
    add-button-text="Add Pool"
    :loading="loading"
    :stats="[
      { color: 'bg-indigo-500', value: statsDisplay.total_pools, tooltip: 'Total pools' },
      { color: 'bg-emerald-500', value: statsDisplay.available_ips, tooltip: 'Available IPs' },
      { color: 'bg-rose-500', value: statsDisplay.allocated_ips, tooltip: 'Allocated IPs' },
    ]"
    :total="statsDisplay.total_ips"
    @update:search-model="localSearch = $event"
    @refresh="fetchPools"
    @add="showCreateModal = true"
    @searchClear="localSearch = ''"
  >
    <template #icon>
      <Network class="h-5 w-5 md:h-6 md:w-6 text-white" />
    </template>

    <!-- Error -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-3 p-10 text-rose-500">
      <WifiOff class="w-10 h-10 opacity-50" />
      <p class="text-sm">{{ error }}</p>
      <button @click="fetchPools" class="text-sm text-indigo-600 hover:underline font-medium">Retry</button>
    </div>

    <!-- Loading skeleton -->
    <DataSkeleton v-else-if="loading" :count="4" />

    <div v-else class="flex flex-col h-full gap-4 min-h-0">

      <!-- Stats cards -->
      <div class="grid grid-cols-2 md:grid-cols-5 gap-3 flex-shrink-0">
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total Pools</span>
            <div class="w-7 h-7 rounded-lg bg-indigo-100 flex items-center justify-center">
              <Database class="w-3.5 h-3.5 text-indigo-600" />
            </div>
          </div>
          <div class="text-2xl font-bold text-slate-800">{{ statsDisplay.total_pools }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Total IPs</span>
            <div class="w-7 h-7 rounded-lg bg-blue-100 flex items-center justify-center">
              <Globe class="w-3.5 h-3.5 text-blue-600" />
            </div>
          </div>
          <div class="text-2xl font-bold text-slate-800">{{ statsDisplay.total_ips }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Allocated</span>
            <div class="w-7 h-7 rounded-lg bg-rose-100 flex items-center justify-center">
              <Lock class="w-3.5 h-3.5 text-rose-600" />
            </div>
          </div>
          <div class="text-2xl font-bold text-rose-600">{{ statsDisplay.allocated_ips }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Available</span>
            <div class="w-7 h-7 rounded-lg bg-emerald-100 flex items-center justify-center">
              <CheckCircle class="w-3.5 h-3.5 text-emerald-600" />
            </div>
          </div>
          <div class="text-2xl font-bold text-emerald-600">{{ statsDisplay.available_ips }}</div>
        </div>
        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm col-span-2 md:col-span-1">
          <div class="flex items-center justify-between mb-2">
            <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider">Utilization</span>
            <div class="w-7 h-7 rounded-lg bg-amber-100 flex items-center justify-center">
              <TrendingUp class="w-3.5 h-3.5 text-amber-600" />
            </div>
          </div>
          <div class="text-2xl font-bold" :class="utilizationColor">{{ statsDisplay.utilization_percentage }}%</div>
          <div class="mt-2 h-1.5 bg-slate-100 rounded-full overflow-hidden">
            <div class="h-full rounded-full transition-all duration-500" :class="utilizationBarColor" :style="{ width: `${Math.min(statsDisplay.utilization_percentage, 100)}%` }"></div>
          </div>
        </div>
      </div>

      <!-- Filter bar -->
      <div class="flex items-center gap-3 flex-shrink-0">
        <div class="flex items-center gap-1 bg-white border border-slate-200 rounded-lg p-1 shadow-sm">
          <button
            v-for="tab in serviceTypeTabs"
            :key="tab.value"
            @click="serviceFilter = tab.value"
            class="px-3 py-1.5 text-xs font-semibold rounded-md transition-all"
            :class="serviceFilter === tab.value
              ? 'bg-indigo-600 text-white shadow-sm'
              : 'text-slate-500 hover:text-slate-700 hover:bg-slate-50'"
          >{{ tab.label }}</button>
        </div>
        <button
          v-if="serviceFilter !== '' || localSearch"
          @click="serviceFilter = ''; localSearch = ''"
          class="text-xs text-slate-500 hover:text-slate-800 font-medium flex items-center gap-1"
        >
          <X class="w-3 h-3" /> Clear
        </button>
      </div>

      <!-- Table -->
      <div v-if="filteredPools.length" class="flex flex-col min-h-0 flex-1">
        <div class="bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm flex flex-col min-h-0 flex-1">
          <!-- Header -->
          <div class="bg-slate-50 border-b border-slate-200 flex-shrink-0">
            <table class="w-full table-fixed">
              <thead>
                <tr>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[22%]">Pool Name</th>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[12%]">Type</th>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[18%]">Network (CIDR)</th>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[18%]">Tenant</th>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[20%]">Usage</th>
                  <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[10%]">Status</th>
                </tr>
              </thead>
            </table>
          </div>
          <!-- Body -->
          <div class="overflow-y-auto flex-1 min-h-0">
            <table class="w-full table-fixed">
              <tbody class="divide-y divide-slate-100">
                <tr
                  v-for="pool in filteredPools"
                  :key="pool.id"
                  class="hover:bg-indigo-50/40 transition-colors cursor-pointer group"
                  @click="openDetail(pool)"
                >
                  <td class="px-5 py-3.5 w-[22%]">
                    <div class="flex items-center gap-2.5">
                      <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0 shadow-sm" :class="serviceIconBg(pool.service_type)">
                        <component :is="serviceIcon(pool.service_type)" class="w-4 h-4 text-white" />
                      </div>
                      <div class="min-w-0">
                        <div class="text-sm font-semibold text-slate-800 truncate">{{ pool.name || poolLabel(pool) }}</div>
                        <div class="text-xs text-slate-400 font-mono truncate">{{ pool.gateway_ip || '—' }}</div>
                      </div>
                    </div>
                  </td>
                  <td class="px-5 py-3.5 w-[12%]">
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold uppercase tracking-wide" :class="serviceTypeBadge(pool.service_type)">
                      {{ pool.service_type || '—' }}
                    </span>
                  </td>
                  <td class="px-5 py-3.5 w-[18%]">
                    <span class="font-mono text-sm text-slate-700">{{ pool.network_cidr || '—' }}</span>
                  </td>
                  <td class="px-5 py-3.5 w-[18%]">
                    <div class="flex items-center gap-2">
                      <div class="w-6 h-6 rounded-full bg-gradient-to-br from-indigo-400 to-blue-500 flex items-center justify-center text-[10px] text-white font-bold flex-shrink-0">
                        {{ (pool.tenant?.name || '?').slice(0,1).toUpperCase() }}
                      </div>
                      <span class="text-sm text-slate-700 truncate">{{ pool.tenant?.name || '—' }}</span>
                    </div>
                  </td>
                  <td class="px-5 py-3.5 w-[20%]">
                    <div class="flex items-center gap-2">
                      <div class="flex-1 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                        <div class="h-full rounded-full" :class="usageBarColor(pool)" :style="{ width: `${usagePct(pool)}%` }"></div>
                      </div>
                      <span class="text-xs tabular-nums text-slate-600 font-medium flex-shrink-0">{{ pool.allocated_ips ?? 0 }}/{{ pool.total_ips ?? 0 }}</span>
                    </div>
                  </td>
                  <td class="px-5 py-3.5 w-[10%]">
                    <div class="flex items-center justify-between">
                      <span
                        class="inline-flex items-center gap-1 text-[11px] font-semibold"
                        :class="pool.is_active !== false ? 'text-emerald-600' : 'text-slate-400'"
                      >
                        <span class="w-1.5 h-1.5 rounded-full" :class="pool.is_active !== false ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                        {{ pool.is_active !== false ? 'Active' : 'Off' }}
                      </span>
                      <button
                        class="p-1 rounded text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 transition-colors opacity-0 group-hover:opacity-100"
                        @click.stop="openDetail(pool)"
                        title="View details"
                      >
                        <Eye class="w-3.5 h-3.5" />
                      </button>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <!-- Empty state -->
      <div v-else class="flex flex-col items-center justify-center gap-3 py-16 text-slate-400">
        <Database class="w-12 h-12 opacity-30" />
        <p class="text-sm font-medium">{{ localSearch || serviceFilter ? 'No pools match your criteria' : 'No IP pools configured' }}</p>
        <button v-if="!localSearch && !serviceFilter" @click="showCreateModal = true" class="text-sm text-indigo-600 hover:underline font-medium">Add the first pool</button>
      </div>
    </div>
  </DataViewContainer>

  <!-- ── Pool Detail Overlay ─────────────────────────────────────────────── -->
  <SlideOverlay
    v-model="showDetail"
    :title="selectedPool ? (selectedPool.name || poolLabel(selectedPool)) : 'Pool Details'"
    :subtitle="selectedPool?.network_cidr ?? ''"
    icon="Network"
    :badge="selectedPool?.service_type ?? undefined"
    gradient
    width="50%"
    no-padding
    @close="showDetail = false"
  >
    <div v-if="selectedPool" class="flex flex-col h-full">
      <!-- Usage banner -->
      <div class="grid grid-cols-3 divide-x divide-white/10 bg-gradient-to-r from-indigo-700 to-blue-600 flex-shrink-0">
        <div class="px-4 py-4 text-center">
          <div class="text-2xl font-bold text-white tabular-nums">{{ selectedPool.total_ips ?? 0 }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Total IPs</div>
        </div>
        <div class="px-4 py-4 text-center">
          <div class="text-2xl font-bold text-white tabular-nums">{{ selectedPool.allocated_ips ?? 0 }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Allocated</div>
        </div>
        <div class="px-4 py-4 text-center">
          <div class="text-2xl font-bold text-white tabular-nums">{{ selectedPool.available_ips ?? 0 }}</div>
          <div class="text-[11px] text-indigo-200 mt-0.5 font-medium uppercase tracking-wide">Available</div>
        </div>
      </div>

      <!-- Utilization bar -->
      <div class="px-6 py-3 bg-indigo-600/20 border-b border-indigo-100 flex-shrink-0">
        <div class="flex items-center justify-between mb-1.5">
          <span class="text-xs font-semibold text-indigo-700">Utilization</span>
          <span class="text-xs font-bold text-indigo-700">{{ usagePct(selectedPool) }}%</span>
        </div>
        <div class="h-2 bg-white/50 rounded-full overflow-hidden">
          <div class="h-full rounded-full transition-all duration-700"
            :class="usageBarColor(selectedPool)"
            :style="{ width: `${usagePct(selectedPool)}%` }">
          </div>
        </div>
      </div>

      <!-- Detail body -->
      <div class="flex-1 overflow-y-auto min-h-0 bg-white">
        <!-- Tenant info -->
        <div class="px-6 py-4 border-b border-slate-100">
          <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-400 to-blue-600 flex items-center justify-center text-white font-bold flex-shrink-0 shadow-md">
              {{ (selectedPool.tenant?.name || '?').slice(0,1).toUpperCase() }}
            </div>
            <div>
              <div class="text-sm font-bold text-slate-800">{{ selectedPool.tenant?.name || 'Unknown Tenant' }}</div>
              <div class="text-xs text-slate-400 mt-0.5">{{ selectedPool.tenant?.slug || '' }}</div>
            </div>
            <div class="ml-auto">
              <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold" :class="serviceTypeBadge(selectedPool.service_type)">
                <component :is="serviceIcon(selectedPool.service_type)" class="w-3 h-3" />
                {{ selectedPool.service_type || '—' }}
              </span>
            </div>
          </div>
        </div>

        <!-- Network details -->
        <div class="px-6 py-4">
          <div class="flex items-center justify-between mb-3">
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
              <Globe class="w-3.5 h-3.5" /> Network Configuration
            </h3>
            <button
              v-if="!editing"
              @click="startEditing"
              class="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold text-indigo-600 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-lg transition-colors"
            >
              <Pencil class="w-3 h-3" /> Edit
            </button>
          </div>
          <div class="grid grid-cols-2 gap-3">
            <DetailRow label="Network CIDR"    :value="selectedPool.network_cidr" mono />
            <DetailRow label="Gateway"         :value="selectedPool.gateway_ip"   mono />
            <DetailRow label="Range Start"     :value="selectedPool.range_start"  mono />
            <DetailRow label="Range End"       :value="selectedPool.range_end"    mono />
            <!-- Editable DNS fields -->
            <div v-if="editing" class="bg-slate-50 rounded-lg p-3">
              <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Primary DNS</div>
              <input v-model="editForm.dns_primary" type="text" placeholder="8.8.8.8"
                class="w-full px-2.5 py-1.5 border border-slate-300 rounded-md text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <DetailRow v-else label="Primary DNS" :value="selectedPool.dns_primary || '—'" mono />
            <div v-if="editing" class="bg-slate-50 rounded-lg p-3">
              <div class="text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1">Secondary DNS</div>
              <input v-model="editForm.dns_secondary" type="text" placeholder="8.8.4.4"
                class="w-full px-2.5 py-1.5 border border-slate-300 rounded-md text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
            </div>
            <DetailRow v-else label="Secondary DNS" :value="selectedPool.dns_secondary || '—'" mono />
          </div>
        </div>

        <!-- Status & meta -->
        <div class="px-6 py-4 border-t border-slate-100">
          <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
            <Activity class="w-3.5 h-3.5" /> Status & Metadata
          </h3>
          <div class="grid grid-cols-2 gap-3">
            <DetailRow label="Status">
              <!-- Editable status toggle -->
              <div v-if="editing" class="flex items-center gap-2">
                <button
                  @click="editForm.is_active = !editForm.is_active"
                  class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                  :class="editForm.is_active ? 'bg-emerald-500' : 'bg-slate-300'"
                >
                  <span class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform shadow-sm"
                    :class="editForm.is_active ? 'translate-x-4' : 'translate-x-0.5'" />
                </button>
                <span class="text-xs font-semibold" :class="editForm.is_active ? 'text-emerald-600' : 'text-slate-400'">
                  {{ editForm.is_active ? 'Active' : 'Inactive' }}
                </span>
              </div>
              <span v-else class="inline-flex items-center gap-1 text-xs font-semibold" :class="selectedPool.is_active !== false ? 'text-emerald-600' : 'text-slate-400'">
                <span class="w-1.5 h-1.5 rounded-full" :class="selectedPool.is_active !== false ? 'bg-emerald-500' : 'bg-slate-300'"></span>
                {{ selectedPool.is_active !== false ? 'Active' : 'Inactive' }}
              </span>
            </DetailRow>
            <DetailRow label="Pool ID" :value="selectedPool.id ? selectedPool.id.slice(0,8) + '…' : '—'" mono />
            <DetailRow label="Created" :value="formatDate(selectedPool.created_at)" />
            <DetailRow label="Updated" :value="formatDate(selectedPool.updated_at)" />
          </div>
        </div>
      </div>

      <!-- Footer actions -->
      <div class="px-6 py-4 border-t border-slate-200 bg-slate-50 flex-shrink-0 flex items-center justify-between gap-3">
        <button
          v-if="!editing"
          @click="deletePool(selectedPool)"
          :disabled="deleting"
          class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium text-rose-600 bg-rose-50 hover:bg-rose-100 border border-rose-200 rounded-lg transition-colors disabled:opacity-50"
        >
          <Trash2 class="w-4 h-4" /> {{ deleting ? 'Deleting…' : 'Delete Pool' }}
        </button>
        <div v-else class="flex items-center gap-2">
          <button @click="cancelEditing"
            class="px-3 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
            Cancel
          </button>
          <button @click="updatePool" :disabled="updating"
            class="inline-flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-indigo-600 to-blue-600 text-white text-sm font-semibold rounded-lg hover:from-indigo-700 hover:to-blue-700 disabled:opacity-50 transition-all shadow-md">
            <Save class="w-4 h-4" /> {{ updating ? 'Saving…' : 'Save Changes' }}
          </button>
        </div>
        <button
          v-if="!editing"
          @click="showDetail = false"
          class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors"
        >Close</button>
      </div>
    </div>
  </SlideOverlay>

  <!-- ── Create Pool Overlay ─────────────────────────────────────────────── -->
  <SlideOverlay
    v-model="showCreateModal"
    title="Add IP Pool"
    subtitle="Allocate a new IP address pool for a tenant"
    icon="Plus"
    gradient
    width="50%"
    @close="showCreateModal = false"
  >
    <form @submit.prevent="createPool" class="space-y-5">
      <!-- Tenant -->
      <div>
        <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Tenant *</label>
        <select v-model="form.tenant_id" required class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
          <option value="">Select a tenant…</option>
          <option v-for="t in tenantsList" :key="t.id" :value="t.id">{{ t.name }}</option>
        </select>
      </div>

      <!-- Service type -->
      <div>
        <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Service Type *</label>
        <div class="grid grid-cols-3 gap-2">
          <button
            v-for="tab in serviceTypeTabs.slice(1)"
            :key="tab.value"
            type="button"
            @click="form.service_type = tab.value"
            class="py-2 px-3 rounded-lg border-2 text-sm font-semibold transition-all"
            :class="form.service_type === tab.value
              ? 'border-indigo-600 bg-indigo-50 text-indigo-700'
              : 'border-slate-200 text-slate-500 hover:border-slate-300'"
          >{{ tab.label }}</button>
        </div>
      </div>

      <!-- Network CIDR -->
      <div>
        <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Network (CIDR) *</label>
        <input v-model="form.network_cidr" type="text" required placeholder="e.g. 10.0.0.0/24"
          class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
      </div>

      <!-- Gateway -->
      <div>
        <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Gateway IP *</label>
        <input v-model="form.gateway_ip" type="text" required placeholder="e.g. 10.0.0.1"
          class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500" />
      </div>

      <!-- Range -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Range Start *</label>
          <input v-model="form.range_start" type="text" required placeholder="10.0.0.2"
            class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Range End *</label>
          <input v-model="form.range_end" type="text" required placeholder="10.0.0.254"
            class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500" />
        </div>
      </div>

      <!-- DNS (optional) -->
      <div class="grid grid-cols-2 gap-3">
        <div>
          <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Primary DNS</label>
          <input v-model="form.dns_primary" type="text" placeholder="8.8.8.8"
            class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500" />
        </div>
        <div>
          <label class="block text-xs font-semibold text-slate-600 uppercase tracking-wider mb-1.5">Secondary DNS</label>
          <input v-model="form.dns_secondary" type="text" placeholder="8.8.4.4"
            class="w-full px-3 py-2.5 border border-slate-300 rounded-lg text-sm font-mono text-slate-800 bg-white focus:ring-2 focus:ring-indigo-500" />
        </div>
      </div>

      <div v-if="formError" class="flex items-center gap-2 p-3 bg-rose-50 border border-rose-200 rounded-lg text-sm text-rose-700">
        <AlertCircle class="w-4 h-4 flex-shrink-0" />
        {{ formError }}
      </div>
    </form>

    <template #footer>
      <div class="flex justify-end gap-3">
        <button type="button" @click="showCreateModal = false"
          class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
          Cancel
        </button>
        <button @click="createPool" :disabled="creating"
          class="px-5 py-2 bg-gradient-to-r from-indigo-600 to-blue-600 text-white text-sm font-semibold rounded-lg hover:from-indigo-700 hover:to-blue-700 disabled:opacity-50 transition-all shadow-md">
          {{ creating ? 'Creating…' : 'Create Pool' }}
        </button>
      </div>
    </template>
  </SlideOverlay>
</template>

<script setup>
import { ref, reactive, computed, onMounted, defineComponent, h } from 'vue'
import axios from 'axios'
import {
  Network, Plus, Trash2, Eye, Globe, Lock, CheckCircle, Database,
  TrendingUp, Wifi, WifiOff, Activity, AlertCircle, X, Zap, Pencil, Save
} from 'lucide-vue-next'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import { useConfirmStore } from '@/stores/confirm'
import { useToast } from '@/modules/common/composables/useToast.js'

const confirmStore = useConfirmStore()
const toast = useToast()

// ── State ─────────────────────────────────────────────────────────────────
const pools       = ref([])
const tenantsList = ref([])
const loading     = ref(true)
const error       = ref(null)
const localSearch = ref('')
const serviceFilter = ref('')

const showCreateModal = ref(false)
const creating   = ref(false)
const formError  = ref(null)
const form = reactive({
  tenant_id: '', service_type: 'pppoe',
  network_cidr: '', gateway_ip: '',
  range_start: '', range_end: '',
  dns_primary: '', dns_secondary: '',
})

const showDetail   = ref(false)
const selectedPool = ref(null)
const editing      = ref(false)
const updating     = ref(false)
const deleting     = ref(false)
const editForm = reactive({
  dns_primary: '',
  dns_secondary: '',
  is_active: true,
})

// ── Stats (computed from local pools array — no separate fetch needed) ────
const statsDisplay = computed(() => {
  const list = pools.value
  const totalPools = list.length
  const totalIps = list.reduce((s, p) => s + (p.total_ips ?? 0), 0)
  const allocatedIps = list.reduce((s, p) => s + (p.allocated_ips ?? 0), 0)
  const availableIps = list.reduce((s, p) => s + (p.available_ips ?? 0), 0)
  return {
    total_pools: totalPools,
    total_ips: totalIps,
    allocated_ips: allocatedIps,
    available_ips: availableIps,
    utilization_percentage: totalIps > 0 ? Math.round((allocatedIps / totalIps) * 100) : 0,
  }
})

const utilizationColor = computed(() => {
  const p = statsDisplay.value.utilization_percentage
  if (p >= 90) return 'text-rose-600'
  if (p >= 70) return 'text-amber-600'
  return 'text-emerald-600'
})
const utilizationBarColor = computed(() => {
  const p = statsDisplay.value.utilization_percentage
  if (p >= 90) return 'bg-rose-500'
  if (p >= 70) return 'bg-amber-500'
  return 'bg-emerald-500'
})

const filteredPools = computed(() => {
  let list = pools.value
  if (serviceFilter.value) list = list.filter(p => p.service_type === serviceFilter.value)
  if (localSearch.value) {
    const q = localSearch.value.toLowerCase()
    list = list.filter(p =>
      (p.name || '').toLowerCase().includes(q) ||
      (p.network_cidr || '').includes(q) ||
      (p.tenant?.name || '').toLowerCase().includes(q)
    )
  }
  return list
})

// ── Helpers ───────────────────────────────────────────────────────────────
const serviceTypeTabs = [
  { label: 'All',        value: '' },
  { label: 'PPPoE',      value: 'pppoe' },
  { label: 'Hotspot',    value: 'hotspot' },
  { label: 'Management', value: 'management' },
]

const serviceIcon = (type) => {
  if (type === 'pppoe')      return Zap
  if (type === 'hotspot')    return Wifi
  if (type === 'management') return Activity
  return Globe
}
const serviceIconBg = (type) => {
  if (type === 'pppoe')      return 'bg-gradient-to-br from-indigo-500 to-blue-600'
  if (type === 'hotspot')    return 'bg-gradient-to-br from-emerald-500 to-teal-600'
  if (type === 'management') return 'bg-gradient-to-br from-amber-500 to-orange-600'
  return 'bg-gradient-to-br from-slate-400 to-slate-600'
}
const serviceTypeBadge = (type) => {
  if (type === 'pppoe')      return 'bg-indigo-100 text-indigo-700'
  if (type === 'hotspot')    return 'bg-emerald-100 text-emerald-700'
  if (type === 'management') return 'bg-amber-100 text-amber-700'
  return 'bg-slate-100 text-slate-600'
}
const poolLabel = (pool) => pool.network_cidr || pool.service_type || 'Pool'
const usagePct  = (pool) => {
  const t = pool.total_ips ?? 0
  if (!t) return 0
  return Math.round(((pool.allocated_ips ?? 0) / t) * 100)
}
const usageBarColor = (pool) => {
  const p = usagePct(pool)
  if (p >= 90) return 'bg-rose-500'
  if (p >= 70) return 'bg-amber-500'
  return 'bg-emerald-500'
}
const formatDate = (d) => {
  if (!d) return '—'
  return new Date(d).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
}

/** Replace a pool in the local array by id */
const replacePoolInList = (updated) => {
  const idx = pools.value.findIndex(p => p.id === updated.id)
  if (idx !== -1) pools.value.splice(idx, 1, updated)
  if (selectedPool.value?.id === updated.id) selectedPool.value = updated
}

const resetForm = () => {
  Object.assign(form, {
    tenant_id: '', service_type: 'pppoe',
    network_cidr: '', gateway_ip: '',
    range_start: '', range_end: '',
    dns_primary: '', dns_secondary: '',
  })
  formError.value = null
}

// ── API: Initial fetch (loading skeleton only on first load) ─────────────
const fetchPools = async () => {
  const isInitial = pools.value.length === 0 && !error.value
  try {
    if (isInitial) loading.value = true
    error.value = null
    const [poolsRes, tenantsRes] = await Promise.all([
      axios.get('/system/tenant/ip-pools'),
      tenantsList.value.length
        ? Promise.resolve(null)
        : axios.get('/system/tenants').catch(() => ({ data: { data: [] } })),
    ])
    pools.value = poolsRes.data.pools || poolsRes.data.data || []
    if (tenantsRes) tenantsList.value = tenantsRes.data.data || tenantsRes.data || []
  } catch (err) {
    if (err.response?.status === 401) return
    error.value = err.response?.data?.message || 'Failed to load IP pools'
  } finally {
    loading.value = false
  }
}

// ── API: Create (optimistic — insert from response, no refetch) ──────────
const createPool = async () => {
  try {
    creating.value = true
    formError.value = null
    const { data } = await axios.post('/system/tenant/ip-pools', form)
    const newPool = data.pool
    if (newPool) pools.value.push(newPool)
    showCreateModal.value = false
    resetForm()
    toast.success('IP pool created successfully')
  } catch (err) {
    formError.value = err.response?.data?.message || 'Failed to create pool'
  } finally {
    creating.value = false
  }
}

// ── API: Update (optimistic — replace from response, no refetch) ─────────
const updatePool = async () => {
  if (!selectedPool.value) return
  try {
    updating.value = true
    const { data } = await axios.put(`/system/tenant/ip-pools/${selectedPool.value.id}`, {
      dns_primary: editForm.dns_primary || null,
      dns_secondary: editForm.dns_secondary || null,
      is_active: editForm.is_active,
    })
    if (data.pool) replacePoolInList(data.pool)
    editing.value = false
    toast.success('IP pool updated successfully')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Failed to update pool')
  } finally {
    updating.value = false
  }
}

// ── API: Delete (optimistic — remove from list, no refetch) ──────────────
const deletePool = async (pool) => {
  const confirmed = await confirmStore.open({
    title: 'Delete IP Pool',
    message: `Delete pool "${pool.name || pool.network_cidr}"? This cannot be undone.`,
    confirmText: 'Delete', cancelText: 'Cancel', variant: 'danger',
  })
  if (!confirmed) return
  const poolId = pool.id
  try {
    deleting.value = true
    await axios.delete(`/system/tenant/ip-pools/${poolId}`)
    pools.value = pools.value.filter(p => p.id !== poolId)
    showDetail.value = false
    selectedPool.value = null
    toast.success('IP pool deleted successfully')
  } catch (err) {
    toast.error(err.response?.data?.message || 'Failed to delete pool')
  } finally {
    deleting.value = false
  }
}

// ── Detail panel ─────────────────────────────────────────────────────────
const openDetail = (pool) => {
  selectedPool.value = pool
  editing.value = false
  showDetail.value = true
}

const startEditing = () => {
  if (!selectedPool.value) return
  editForm.dns_primary = selectedPool.value.dns_primary || ''
  editForm.dns_secondary = selectedPool.value.dns_secondary || ''
  editForm.is_active = selectedPool.value.is_active !== false
  editing.value = true
}

const cancelEditing = () => {
  editing.value = false
}

const DetailRow = defineComponent({
  props: { label: String, value: String, mono: Boolean },
  setup(props, { slots }) {
    return () => h('div', { class: 'bg-slate-50 rounded-lg p-3' }, [
      h('div', { class: 'text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1' }, props.label),
      slots.default
        ? slots.default()
        : h('span', { class: props.mono ? 'font-mono text-sm text-slate-700' : 'text-sm text-slate-700' }, props.value || '—'),
    ])
  }
})

onMounted(() => fetchPools())
</script>
