<template>
  <DataViewContainer
    title="Hotspot Users"
    subtitle="Auto-created accounts upon payment. Users reactivate on repeat purchases."
    color-theme="cyan"
    v-model:search-model="searchQuery"
    search-placeholder="Search users, usernames, phone..."
    :stats="[
      { color: 'bg-emerald-500', value: activeUsers.length, tooltip: 'Active' },
      { color: 'bg-amber-500', value: expiredUsers.length, tooltip: 'Expired' },
      { color: 'bg-blue-500', value: inactiveUsers.length, tooltip: 'Inactive / No Payment' },
      { color: 'bg-purple-500', value: (users || []).filter(u => u.data_used > 0).length, tooltip: 'Ever Used' },
    ]"
    :total="users.length"
    :loading="loading"
    :show-add="false"
    @refresh="fetchUsers"
    @searchClear="searchQuery = ''"
  >
    <!-- Icon Slot -->
    <template #icon>
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 md:h-6 md:w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
      </svg>
    </template>

    <!-- Filters -->
    <template #filters>
      <BaseSelect v-model="filters.status" placeholder="All Status" class="w-36">
        <option value="">All Status</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
        <option value="expired">Expired</option>
      </BaseSelect>
      <BaseSelect v-model="filters.package_id" placeholder="All Packages" class="w-40">
        <option value="">All Packages</option>
        <option v-for="pkg in packages" :key="pkg.id" :value="String(pkg.id)">{{ pkg.name }}</option>
      </BaseSelect>
    </template>

    <!-- Auto-creation info banner -->
    <div class="mx-4 mt-3 mb-1 px-4 py-3 bg-gradient-to-r from-cyan-50 to-teal-50 border border-cyan-200 rounded-xl flex items-start gap-3">
      <Wifi class="w-5 h-5 text-cyan-600 flex-shrink-0 mt-0.5" />
      <div class="text-sm text-cyan-800">
        <p class="font-semibold">Auto-Login Hotspot System</p>
        <p class="text-cyan-700 mt-1">Users are automatically created when they make a payment. The same account reactivates on repeat purchases. Sessions auto-terminate when duration expires.</p>
      </div>
    </div>

    <!-- Error State -->
    <div v-if="error" class="flex flex-col items-center justify-center gap-4 p-8 text-red-500">
      <svg xmlns="http://www.w3.org/2000/svg" class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <p class="text-center">{{ error }}</p>
      <button @click="fetchUsers" class="px-4 py-2 text-sm font-medium text-white bg-red-500 rounded-md hover:bg-red-600 transition-colors">Retry</button>
    </div>

    <!-- Loading Skeleton -->
    <DataSkeleton v-else-if="loading" :count="5" />

    <!-- Users table -->
    <div v-else-if="filteredData.length" class="flex flex-col h-full pt-2 pb-2 min-h-0">
      <!-- Mobile cards -->
      <div class="md:hidden space-y-3 overflow-y-auto flex-1 min-h-0 px-1">
        <div
          v-for="user in paginatedData"
          :key="user.id"
          class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm hover:shadow-md hover:border-cyan-300 transition-all"
        >
          <div class="flex items-center gap-3" @click="openUserPanel(user)">
            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-cyan-500 to-teal-600 flex items-center justify-center text-white text-sm font-bold flex-shrink-0 shadow">
              {{ getUserInitials(user) }}
            </div>
            <div class="flex-1 min-w-0">
              <div class="text-sm font-semibold text-slate-800 truncate">{{ user.name || user.username }}</div>
              <div class="text-xs text-slate-500 font-mono truncate">{{ user.username }}</div>
            </div>
            <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
          </div>
          <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
            <div><span class="text-slate-400">Package:</span> {{ user.package_name || 'N/A' }}</div>
            <div><span class="text-slate-400">Contact:</span> {{ user.phone || user.email || '—' }}</div>
            <div><span class="text-slate-400">Data:</span> {{ formatBytes(user.data_used) }}</div>
            <div><span class="text-slate-400">Expires:</span> <span :class="getDaysClass(user.days_to_expiry)">{{ formatDaysToExpiry(user.days_to_expiry) }}</span></div>
          </div>
          <div class="mt-3 pt-3 border-t border-slate-100 flex items-center gap-2" @click.stop>
            <button @click="openUserPanel(user)"
              class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-cyan-700 bg-cyan-50 border border-cyan-200 rounded-lg hover:bg-cyan-100 transition-colors">
              <Eye class="w-3.5 h-3.5" /> View
            </button>
            <button @click="handleToggleBlock(user)"
              class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-1.5 text-xs font-semibold rounded-lg border transition-colors"
              :class="user.status === 'blocked'
                ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
                : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'">
              <ShieldOff v-if="user.status !== 'blocked'" class="w-3.5 h-3.5" />
              <ShieldCheck v-else class="w-3.5 h-3.5" />
              {{ user.status === 'blocked' ? 'Unblock' : 'Block' }}
            </button>
            <button @click="toggleMenu(user.id, $event)" data-menu-button
              class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg border border-slate-200 transition-colors">
              <MoreVertical class="w-4 h-4" />
            </button>
          </div>
        </div>
      </div>

      <!-- Desktop table -->
      <div class="hidden md:flex bg-white border border-slate-200 rounded-lg flex-col min-h-0 flex-1 shadow-sm overflow-hidden">
        <div class="bg-slate-50 border-b border-slate-200 flex-shrink-0">
          <table class="w-full table-fixed">
            <thead>
              <tr>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[24%]">User</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[18%]">Package</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[12%]">Status</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[20%]">Data Usage</th>
                <th class="px-5 py-3 text-left text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[14%]">Expires</th>
                <th class="px-5 py-3 text-right text-[11px] font-semibold text-slate-400 uppercase tracking-widest w-[12%]">Actions</th>
              </tr>
            </thead>
          </table>
        </div>
        <div class="overflow-y-auto flex-1 min-h-0">
          <table class="w-full table-fixed">
            <tbody class="divide-y divide-slate-100">
              <tr
                v-for="user in paginatedData"
                :key="user.id"
                class="hover:bg-cyan-50/50 transition-colors cursor-pointer group"
                @click="openUserPanel(user)"
              >
                <td class="px-5 py-3.5 w-[24%]">
                  <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-cyan-500 to-teal-600 flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-sm">
                      {{ getUserInitials(user) }}
                    </div>
                    <div class="min-w-0">
                      <div class="text-sm font-semibold text-slate-800 truncate">{{ user.name || user.username }}</div>
                      <div class="text-xs text-slate-400 font-mono truncate">{{ user.phone || user.email || user.username }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[18%]">
                  <div class="text-sm text-slate-700 truncate">{{ user.package_name || '—' }}</div>
                  <div class="text-xs text-slate-400">{{ formatPackageSpeed(user) }}</div>
                </td>
                <td class="px-5 py-3.5 w-[12%]">
                  <EntityStatusBadge :status="user.status || 'inactive'" size="sm" />
                </td>
                <td class="px-5 py-3.5 w-[20%]">
                  <div class="flex items-center gap-2">
                    <div class="flex-1 h-1.5 bg-slate-200 rounded-full overflow-hidden">
                      <div class="h-full rounded-full transition-all" :class="getDataUsageColor(user.data_used, user.data_limit)" :style="{ width: getDataUsagePercent(user.data_used, user.data_limit) }"></div>
                    </div>
                    <span class="text-xs text-slate-500 w-16 text-right">{{ formatBytes(user.data_used) }}</span>
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[14%]">
                  <div class="text-sm text-slate-600">{{ formatDateShort(user.subscription_expires_at) }}</div>
                  <div v-if="user.days_to_expiry !== null && user.days_to_expiry !== undefined" class="text-xs mt-0.5" :class="getDaysClass(user.days_to_expiry)">
                    {{ formatDaysToExpiry(user.days_to_expiry) }}
                  </div>
                </td>
                <td class="px-5 py-3.5 w-[12%] text-right" @click.stop>
                  <div class="flex items-center justify-end gap-1.5">
                    <button @click="openUserPanel(user)"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold text-cyan-700 bg-cyan-50 hover:bg-cyan-100 rounded-lg transition-colors">
                      <Eye class="w-3.5 h-3.5" /> View
                    </button>
                    <button @click="handleToggleBlock(user)"
                      class="inline-flex items-center gap-1 px-2.5 py-1.5 text-xs font-semibold rounded-lg border transition-colors"
                      :class="user.status === 'blocked'
                        ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
                        : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'"
                    >
                      <ShieldOff v-if="user.status !== 'blocked'" class="w-3.5 h-3.5" />
                      <ShieldCheck v-else class="w-3.5 h-3.5" />
                      {{ user.status === 'blocked' ? 'Unblock' : 'Block' }}
                    </button>
                    <button data-menu-button @click="toggleMenu(user.id, $event)"
                      class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                      <MoreVertical class="w-4 h-4" />
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <DataPagination v-model:current-page="currentPage" v-model:items-per-page="itemsPerPage" :total-pages="totalPages" :total-items="filteredData.length" item-name="users" class="mt-auto" />
    </div>

    <!-- Empty -->
    <DataEmptyState
      v-else
      :title="searchQuery ? 'No Matches Found' : 'No Hotspot Users Yet'"
      :description="searchQuery ? 'No users match your search.' : 'Users will appear here automatically when they make their first payment.'"
      icon="wifi"
      color-theme="cyan"
      :show-clear="!!searchQuery"
      :has-filters="hasActiveFilters"
      :show-add="false"
      clear-text="Clear Search"
      @clear="searchQuery = ''"
    />
  </DataViewContainer>

  <!-- ═══ THREE-DOT DROPDOWN MENU (portal) ═══ -->
  <Teleport to="body">
    <div
      v-if="activeMenu !== null"
      data-dropdown-menu
      :style="menuPosition"
      class="fixed w-52 bg-white rounded-xl shadow-2xl border border-slate-200 py-1.5 z-[9999] overflow-hidden"
    >
      <button @click="openUserPanel(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition-colors gap-3">
        <Eye class="w-4 h-4 flex-shrink-0" /> View Details
      </button>
      <button @click="openEditFromMenu(users.find(u => u.id === activeMenu))"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-700 hover:bg-cyan-50 hover:text-cyan-700 transition-colors gap-3">
        <Pencil class="w-4 h-4 flex-shrink-0" /> Edit
      </button>
      <div class="border-t border-slate-100 my-1"></div>
      <button v-if="users.find(u => u.id === activeMenu)?.status === 'blocked'"
        @click="handleToggleBlock(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-emerald-700 hover:bg-emerald-50 transition-colors gap-3">
        <ShieldCheck class="w-4 h-4 flex-shrink-0" /> Unblock
      </button>
      <button v-else
        @click="handleToggleBlock(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-amber-700 hover:bg-amber-50 transition-colors gap-3">
        <ShieldOff class="w-4 h-4 flex-shrink-0" /> Block
      </button>
      <div class="border-t border-slate-100 my-1"></div>
      <button v-if="users.find(u => u.id === activeMenu)?.status === 'active'"
        @click="handleDisconnect(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-rose-600 hover:bg-rose-50 transition-colors gap-3">
        <WifiOff class="w-4 h-4 flex-shrink-0" /> Disconnect
      </button>
      <button v-if="users.find(u => u.id === activeMenu)?.status !== 'active'"
        @click="handleGrantAccess(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-emerald-700 hover:bg-emerald-50 transition-colors gap-3">
        <Wifi class="w-4 h-4 flex-shrink-0" /> Grant Access
      </button>
      <button @click="handleRevokeAccess(users.find(u => u.id === activeMenu)); closeMenu()"
        class="flex items-center w-full px-4 py-2.5 text-sm text-slate-500 hover:bg-slate-50 transition-colors gap-3">
        <Ban class="w-4 h-4 flex-shrink-0" /> Revoke Access
      </button>
      <div class="border-t border-slate-100 my-1"></div>
      <button @click="handleDeleteUser(users.find(u => u.id === activeMenu))"
        class="flex items-center w-full px-4 py-2.5 text-sm text-rose-700 hover:bg-rose-50 transition-colors gap-3">
        <Trash2 class="w-4 h-4 flex-shrink-0" /> Delete User
      </button>
    </div>
  </Teleport>

  <!-- ═══════════════════════════════════════════════════════════════════════
       USER DETAIL PANEL — full-featured tabbed slide-out
  ═══════════════════════════════════════════════════════════════════════ -->
  <SlideOverlay
    v-model="showUserPanel"
    :title="panelUser ? (panelUser.name || panelUser.username) : 'Hotspot User'"
    :subtitle="panelUser?.username ?? ''"
    gradient
    width="70%"
    no-padding
    @close="showUserPanel = false"
  >
    <div v-if="panelUser" class="flex flex-col h-full">

      <!-- Panel header strip -->
      <div class="flex-shrink-0 bg-gradient-to-r from-cyan-700 to-teal-700 px-6 py-4">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl bg-white/20 backdrop-blur flex items-center justify-center text-white text-xl font-bold shadow-lg flex-shrink-0">
            {{ getUserInitials(panelUser) }}
          </div>
          <div class="flex-1 min-w-0">
            <div class="text-lg font-bold text-white truncate">{{ panelUser.name || panelUser.username }}</div>
            <div class="text-sm text-cyan-200 font-mono mt-0.5">{{ panelUser.username }}</div>
            <div class="flex items-center gap-2 mt-1.5">
              <EntityStatusBadge :status="panelUser.status || 'inactive'" size="sm" />
              <span v-if="panelUser.package_name" class="text-xs text-cyan-200 bg-white/10 px-2 py-0.5 rounded-full">{{ panelUser.package_name }}</span>
            </div>
          </div>
          <!-- Quick stats -->
          <div class="hidden md:flex items-center gap-4 flex-shrink-0">
            <div class="text-center">
              <div class="text-lg font-bold text-white">{{ panelUser.days_to_expiry != null ? Math.round(panelUser.days_to_expiry) : '—' }}</div>
              <div class="text-[10px] text-cyan-300 uppercase tracking-wide">Days Left</div>
            </div>
            <div class="text-center">
              <div class="text-lg font-bold text-white">{{ formatBytes(panelUser.data_used) }}</div>
              <div class="text-[10px] text-cyan-300 uppercase tracking-wide">Data Used</div>
            </div>
          </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 mt-4 bg-white/10 rounded-xl p-1 overflow-x-auto scrollbar-hide">
          <button
            v-for="tab in panelTabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            class="flex-shrink-0 flex items-center justify-center gap-1.5 py-1.5 px-2 text-xs font-semibold rounded-lg transition-all relative whitespace-nowrap"
            :class="activeTab === tab.id ? 'bg-white text-cyan-700 shadow-sm' : 'text-cyan-200 hover:text-white hover:bg-white/10'"
          >
            <component :is="tab.icon" class="w-3.5 h-3.5" />
            {{ tab.label }}
            <span v-if="tab.id === 'session' && !sessionLoading && currentSession"
              class="absolute top-1 right-1 w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
            <span v-else-if="tab.id === 'session' && !sessionLoading && !currentSession"
              class="absolute top-1 right-1 w-1.5 h-1.5 rounded-full bg-slate-400/60"></span>
          </button>
        </div>
      </div>

      <!-- Tab content -->
      <div class="flex-1 overflow-y-auto min-h-0 bg-slate-50" ref="panelScrollRef">

        <!-- ── TAB: User Details ── -->
        <div v-if="activeTab === 'details'" class="p-6 space-y-5">

          <!-- Account info -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <UserCircle2 class="w-3.5 h-3.5" /> Account Information
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm grid grid-cols-2 gap-4">
              <InfoCell label="Username" :value="panelUser.username" mono />
              <InfoCell label="Full Name" :value="panelUser.name || '—'" />
              <InfoCell label="Phone" :value="panelUser.phone || panelUser.phone_number || '—'" />
              <InfoCell label="Email" :value="panelUser.email || '—'" />
              <InfoCell label="MAC Address" :value="panelUser.mac_address || '—'" mono />
              <InfoCell label="Status">
                <EntityStatusBadge :status="panelUser.status || 'inactive'" size="sm" />
              </InfoCell>
              <InfoCell label="Last Login" :value="formatDate(panelUser.last_login_at)" />
              <InfoCell label="Created" :value="formatDate(panelUser.created_at)" />
            </div>
          </section>

          <!-- Credentials -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <KeyRound class="w-3.5 h-3.5" /> Credentials
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm space-y-3">
              <div>
                <div class="text-xs text-slate-500 mb-1.5">Password</div>
                <div class="flex items-center gap-2">
                  <div class="flex-1 font-mono text-sm bg-slate-50 border border-slate-200 rounded-lg px-3 py-2 text-slate-800">
                    {{ showPasswordValue ? userPassword : '••••••••••••' }}
                  </div>
                  <button v-if="!showPasswordValue" @click="handleViewPassword" :disabled="loadingPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-50 transition-colors">
                    <Eye class="w-3.5 h-3.5" />{{ loadingPassword ? '...' : 'View' }}
                  </button>
                  <button v-else @click="hidePassword" class="p-2 text-slate-400 hover:text-slate-700 border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                    <EyeOff class="w-3.5 h-3.5" />
                  </button>
                  <button @click="handleResetPassword"
                    class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" /> Reset
                  </button>
                </div>
              </div>
            </div>
          </section>

          <!-- Subscription & Data -->
          <section>
            <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider mb-3 flex items-center gap-1.5">
              <CreditCard class="w-3.5 h-3.5" /> Subscription & Data
            </h3>
            <div class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm grid grid-cols-2 gap-4">
              <InfoCell label="Package" :value="panelUser.package_name || '—'" />
              <InfoCell label="Speed" :value="formatPackageSpeed(panelUser)" />
              <InfoCell label="Simultaneous" :value="String(panelUser.simultaneous_use ?? 1)" />
              <InfoCell label="Subscription Start" :value="formatDateShort(panelUser.subscription_starts_at)" />
              <InfoCell label="Expires">
                <div :class="['text-sm font-semibold', getDaysClass(panelUser.days_to_expiry)]">
                  {{ formatDateShort(panelUser.subscription_expires_at) }}
                </div>
              </InfoCell>
              <InfoCell label="Days Left">
                <div :class="['text-sm font-semibold', getDaysClass(panelUser.days_to_expiry)]">
                  {{ formatDaysToExpiry(panelUser.days_to_expiry) }}
                </div>
              </InfoCell>
              <InfoCell label="Data Used" :value="formatBytes(panelUser.data_used)" />
              <InfoCell label="Data Limit" :value="panelUser.data_limit ? formatBytes(panelUser.data_limit) : 'Unlimited'" />
            </div>
            <!-- Data usage bar -->
            <div v-if="panelUser.data_limit" class="mt-3 bg-white rounded-xl border border-slate-200 p-4 shadow-sm">
              <div class="flex items-center justify-between text-xs text-slate-500 mb-2">
                <span>Data Usage</span>
                <span>{{ formatBytes(panelUser.data_used) }} / {{ formatBytes(panelUser.data_limit) }}</span>
              </div>
              <div class="h-2 bg-slate-200 rounded-full overflow-hidden">
                <div class="h-full rounded-full transition-all" :class="getDataUsageColor(panelUser.data_used, panelUser.data_limit)" :style="{ width: getDataUsagePercent(panelUser.data_used, panelUser.data_limit) }"></div>
              </div>
            </div>
          </section>

          <!-- Edit form (inline, toggleable) -->
          <section>
            <div class="flex items-center justify-between mb-3">
              <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                <Pencil class="w-3.5 h-3.5" /> Edit Account
              </h3>
            </div>
            <Transition name="slide-down">
              <div v-if="editExpanded" class="bg-white rounded-xl border border-cyan-200 p-4 shadow-sm space-y-4">
                <BaseAlert v-if="editFormError" variant="danger" :title="editFormError" dismissible />
                <div class="font-mono text-sm text-slate-500 bg-slate-50 border border-slate-200 rounded-lg px-3 py-2">
                  <span class="text-xs text-slate-400 block mb-0.5">Username (read-only)</span>
                  {{ panelUser.username }}
                </div>
                <div class="grid grid-cols-2 gap-4">
                  <BaseSelect v-model="editForm.package_id" label="Package" :error="editFieldErrors.package_id" required>
                    <option v-for="pkg in packages" :key="pkg.id" :value="pkg.id">{{ pkg.name }}</option>
                  </BaseSelect>
                  <BaseInput v-model="editForm.phone_number" label="Phone Number" :error="editFieldErrors.phone_number" />
                  <BaseInput v-model="editForm.mac_address" label="MAC Address" :error="editFieldErrors.mac_address" />
                  <BaseInput v-model="editForm.simultaneous_use" type="number" label="Simultaneous Sessions" :error="editFieldErrors.simultaneous_use" required />
                  <BaseSelect v-model="editForm.status" label="Status" :error="editFieldErrors.status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="blocked">Blocked</option>
                    <option value="expired">Expired</option>
                  </BaseSelect>
                </div>
                <div v-if="editForm.package_id && editForm.package_id !== panelUser.package_id && panelUser.subscription_expires_at"
                  class="flex items-start gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-700">
                  <span class="font-semibold">Package Change:</span> Remaining credit will be pro-rated to the new package duration automatically.
                </div>
                <div class="flex justify-end gap-2 pt-1">
                  <button @click="editExpanded = false" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">Cancel</button>
                  <button @click="handleUpdateUser" :disabled="editSubmitting"
                    class="px-5 py-2 text-sm font-semibold text-white bg-gradient-to-r from-cyan-600 to-teal-600 rounded-lg hover:from-cyan-700 hover:to-teal-700 disabled:opacity-50 transition-all shadow-md">
                    {{ editSubmitting ? 'Saving…' : 'Save Changes' }}
                  </button>
                </div>
              </div>
            </Transition>
          </section>
        </div>

        <!-- ── TAB: Current Session ── -->
        <div v-else-if="activeTab === 'session'" class="p-6">
          <div v-if="sessionLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="currentSession" class="space-y-5">
            <div v-if="currentSession.acct_staleness_seconds > 600"
              class="flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-700 font-medium">
              <span class="w-2 h-2 rounded-full bg-amber-500 flex-shrink-0"></span>
              Accounting stale — last update {{ Math.floor(currentSession.acct_staleness_seconds / 60) }}m ago
            </div>
            <div class="bg-gradient-to-r from-emerald-600 to-teal-600 rounded-xl p-4 text-white shadow-md">
              <div class="flex items-center gap-2 mb-3">
                <span class="w-2 h-2 bg-white rounded-full animate-pulse"></span>
                <span class="text-sm font-bold">Live Session</span>
              </div>
              <div class="grid grid-cols-3 gap-4">
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">Online Since</div>
                  <div class="text-sm font-bold mt-0.5">{{ formatDate(currentSession.connected_at || currentSession.acctstarttime || currentSession.start_time) }}</div>
                </div>
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">Session Time</div>
                  <div class="text-sm font-bold mt-0.5">{{ formatDuration(currentSession.uptime || currentSession.duration || currentSession.acctsessiontime) }}</div>
                </div>
                <div>
                  <div class="text-[10px] text-emerald-200 uppercase tracking-wide">IP Address</div>
                  <div class="text-sm font-mono font-bold mt-0.5">{{ currentSession.ip_address || currentSession.framed_ip || currentSession.framedipaddress || '—' }}</div>
                </div>
              </div>
            </div>
            <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
              <table class="w-full text-sm">
                <tbody class="divide-y divide-slate-100">
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">↓ Download</span>
                      <span class="font-semibold text-emerald-700">{{ formatBytes(currentSession.download_rate || currentSession.download_speed || 0) }}/s</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">↑ Upload</span>
                      <span class="font-semibold text-blue-700">{{ formatBytes(currentSession.upload_rate || currentSession.upload_speed || 0) }}/s</span>
                    </td>
                  </tr>
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Total Downloaded</span>
                      <span class="text-slate-700">{{ formatBytes(currentSession.output_octets || currentSession.acctoutputoctets || currentSession.bytes_in || 0) }}</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">Total Uploaded</span>
                      <span class="text-slate-700">{{ formatBytes(currentSession.input_octets || currentSession.acctinputoctets || currentSession.bytes_out || 0) }}</span>
                    </td>
                  </tr>
                  <tr class="grid grid-cols-2">
                    <td class="flex items-center justify-between px-4 py-2.5 border-r border-slate-100">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">NAS IP</span>
                      <span class="font-mono text-slate-700">{{ currentSession.nas_ip_address || currentSession.nasipaddress || currentSession.nas_ip || '—' }}</span>
                    </td>
                    <td class="flex items-center justify-between px-4 py-2.5">
                      <span class="text-[11px] font-semibold text-slate-400 uppercase tracking-wide">MAC Address</span>
                      <span class="font-mono text-slate-700 text-xs">{{ currentSession.calling_station_id || currentSession.callingstationid || currentSession.mac_address || '—' }}</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
            <!-- Traffic Chart -->
            <div class="space-y-3">
              <div class="flex items-center justify-between">
                <h3 class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider flex items-center gap-1.5">
                  <TrendingUp class="w-3.5 h-3.5" /> Traffic History
                </h3>
                <div class="flex items-center gap-2">
                  <select v-model="chartRange" @change="fetchUserChart" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-700 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                    <option value="15m">Last 15 min</option>
                    <option value="30m">Last 30 min</option>
                    <option value="1h">Last 1 hour</option>
                    <option value="6h">Last 6 hours</option>
                    <option value="24h">Last 24 hours</option>
                    <option value="7d">Last 7 days</option>
                  </select>
                  <select v-model="chartType" class="text-xs border border-slate-300 rounded-lg px-2 py-1.5 bg-white text-slate-700 focus:ring-2 focus:ring-cyan-500 focus:border-cyan-500">
                    <option value="line">Line</option>
                    <option value="area">Area</option>
                  </select>
                  <button @click="fetchUserChart" class="p-1.5 text-slate-400 hover:text-cyan-600 border border-slate-200 rounded-lg hover:border-cyan-300 transition-colors">
                    <RotateCcw class="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
              <div v-if="trafficLoading" class="flex items-center justify-center py-10">
                <div class="w-6 h-6 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin"></div>
              </div>
              <div v-else-if="chartHasData" class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="p-4">
                  <div class="flex" style="height:200px">
                    <div class="relative w-16 mr-2 border-r border-slate-100 flex-shrink-0">
                      <div v-for="tick in chartYTicks" :key="tick.label"
                        class="absolute right-2 text-[10px] text-slate-500 font-medium"
                        :style="{ bottom: tick.percent + '%', transform: 'translateY(50%)' }">{{ tick.label }}</div>
                    </div>
                    <div class="relative flex-1 flex flex-col min-w-0">
                      <div class="relative flex-1 overflow-hidden cursor-crosshair" @mousemove="onChartHover" @mouseleave="onChartLeave" ref="chartContainer">
                        <div v-for="tick in chartYTicks" :key="'g'+tick.label" class="absolute w-full border-t border-slate-100" :style="{ bottom: tick.percent + '%' }" />
                        <svg class="absolute inset-0 w-full h-full" viewBox="0 0 1000 200" preserveAspectRatio="none">
                          <defs>
                            <linearGradient id="hsGradDL" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#22c55e" stop-opacity="0.25"/>
                              <stop offset="100%" stop-color="#22c55e" stop-opacity="0"/>
                            </linearGradient>
                            <linearGradient id="hsGradUL" x1="0" x2="0" y1="0" y2="1">
                              <stop offset="0%" stop-color="#06b6d4" stop-opacity="0.25"/>
                              <stop offset="100%" stop-color="#06b6d4" stop-opacity="0"/>
                            </linearGradient>
                          </defs>
                          <template v-if="chartType === 'area'">
                            <path :d="svgDlAreaPath" fill="url(#hsGradDL)" stroke="none" />
                            <path :d="svgUlAreaPath" fill="url(#hsGradUL)" stroke="none" />
                          </template>
                          <path :d="svgDlPath" fill="none" stroke="#22c55e" stroke-width="2" vector-effect="non-scaling-stroke" />
                          <path :d="svgUlPath" fill="none" stroke="#06b6d4" stroke-width="2" vector-effect="non-scaling-stroke" />
                        </svg>
                        <div v-if="chartHoverIdx >= 0"
                          class="absolute top-0 bottom-0 border-l border-slate-400 border-dashed pointer-events-none z-10"
                          :style="{ left: chartHoverX + '%' }">
                          <div class="absolute w-2 h-2 bg-green-500 rounded-full -ml-1 border-2 border-white shadow" :style="{ bottom: (chartHoverData.dl / chartMax * 100) + '%' }"/>
                          <div class="absolute w-2 h-2 bg-cyan-500 rounded-full -ml-1 border-2 border-white shadow" :style="{ bottom: (chartHoverData.ul / chartMax * 100) + '%' }"/>
                          <div class="absolute top-0 left-3 bg-white/95 backdrop-blur shadow-xl border border-slate-200 rounded-xl p-2.5 text-xs whitespace-nowrap z-20 pointer-events-none"
                            :class="chartHoverX > 70 ? '-translate-x-full -left-3' : ''">
                            <div class="font-mono text-slate-400 mb-1.5">{{ chartFormatTime(chartHoverData.t) }}</div>
                            <div class="flex items-center gap-2 mb-1"><span class="w-2 h-2 rounded-full bg-green-500 flex-shrink-0"></span><span class="font-semibold text-slate-700">↓ {{ formatBytes(chartHoverData.dl) }}/s</span></div>
                            <div class="flex items-center gap-2"><span class="w-2 h-2 rounded-full bg-cyan-500 flex-shrink-0"></span><span class="font-semibold text-slate-700">↑ {{ formatBytes(chartHoverData.ul) }}/s</span></div>
                          </div>
                        </div>
                      </div>
                      <div class="h-7 relative mt-1 flex-shrink-0 border-t border-slate-200 pt-1">
                        <div v-for="tick in chartXTicks" :key="tick.x" class="absolute text-[10px] text-slate-400 font-medium -translate-x-1/2 whitespace-nowrap" :style="{ left: tick.x + '%' }">{{ tick.label }}</div>
                      </div>
                    </div>
                  </div>
                  <div class="flex items-center justify-between mt-3 pt-3 border-t border-slate-100">
                    <div class="flex items-center gap-5">
                      <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-full bg-green-500"></div><span class="text-xs text-slate-600 font-medium">Download</span></div>
                      <div class="flex items-center gap-1.5"><div class="w-3 h-3 rounded-full bg-cyan-500"></div><span class="text-xs text-slate-600 font-medium">Upload</span></div>
                    </div>
                    <div class="flex items-center gap-4 text-xs text-slate-500">
                      <span>Peak ↓ <strong class="text-green-700">{{ formatBytes(chartPeakDl) }}/s</strong></span>
                      <span>Peak ↑ <strong class="text-cyan-700">{{ formatBytes(chartPeakUl) }}/s</strong></span>
                    </div>
                  </div>
                </div>
              </div>
              <div v-else class="flex flex-col items-center gap-2 py-8 text-slate-400 bg-white rounded-xl border border-slate-200">
                <TrendingUp class="w-8 h-8 opacity-30" />
                <p class="text-xs font-medium">No traffic data for this session</p>
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <WifiOff class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No active session</p>
            <p class="text-xs">This user is currently offline</p>
          </div>
        </div>

        <!-- ── TAB: Historical Sessions ── -->
        <div v-else-if="activeTab === 'history'" class="p-6">
          <div v-if="historyLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="sessionHistory.length" class="space-y-3">
            <div class="text-xs text-slate-500 font-medium mb-2">{{ sessionHistory.length }} sessions — most recent first</div>
            <div
              v-for="(s, i) in sessionHistory"
              :key="s.radacctid || s.id || i"
              class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:border-cyan-200 transition-colors"
            >
              <div class="flex items-start justify-between gap-3">
                <div class="flex items-center gap-2">
                  <div class="w-7 h-7 rounded-lg bg-slate-100 flex items-center justify-center flex-shrink-0 text-xs font-bold text-slate-500">{{ i + 1 }}</div>
                  <div>
                    <div class="text-sm font-semibold text-slate-800">{{ formatDate(s.disconnected_at || s.acctstoptime || s.session_end) }}</div>
                    <div class="text-xs text-slate-400 mt-0.5">Connected: {{ formatDate(s.connected_at || s.acctstarttime || s.session_start) }}</div>
                  </div>
                </div>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold flex-shrink-0"
                  :class="reasonBadge(s.terminate_cause || s.acctterminatecause)">
                  {{ s.terminate_cause || s.acctterminatecause || 'Unknown' }}
                </span>
              </div>
              <div class="mt-3 grid grid-cols-3 gap-3 text-xs">
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Duration</div>
                  <div class="font-semibold text-slate-700">{{ formatDuration(s.duration || s.acctsessiontime) }}</div>
                </div>
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Download</div>
                  <div class="font-semibold text-slate-700">{{ formatBytes(s.output_octets || s.acctoutputoctets || 0) }}</div>
                </div>
                <div class="bg-slate-50 rounded-lg p-2">
                  <div class="text-slate-400 mb-0.5">Upload</div>
                  <div class="font-semibold text-slate-700">{{ formatBytes(s.input_octets || s.acctinputoctets || 0) }}</div>
                </div>
              </div>
              <div class="mt-2 flex items-center gap-4 text-xs text-slate-400">
                <span class="font-mono">{{ s.framed_ip_address || s.framedipaddress || s.ip_address || '—' }}</span>
                <span class="font-mono">{{ s.calling_station_id || s.callingstationid || s.mac_address || '—' }}</span>
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <History class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No session history</p>
          </div>
        </div>

        <!-- ── TAB: Payments ── -->
        <div v-else-if="activeTab === 'payments'" class="p-6">
          <div v-if="paymentsLoading" class="flex items-center justify-center py-16">
            <div class="w-7 h-7 border-2 border-cyan-500 border-t-transparent rounded-full animate-spin"></div>
          </div>
          <div v-else-if="paymentHistory.length" class="space-y-3">
            <div class="text-xs text-slate-500 font-medium mb-2">{{ paymentHistory.length }} payments — most recent first</div>
            <div class="grid grid-cols-3 gap-3 mb-4">
              <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-emerald-700">{{ paymentTotals.count }}</div>
                <div class="text-[10px] text-emerald-500 uppercase tracking-wide">Total Payments</div>
              </div>
              <div class="bg-blue-50 border border-blue-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-blue-700">KES {{ paymentTotals.total }}</div>
                <div class="text-[10px] text-blue-500 uppercase tracking-wide">Total Paid</div>
              </div>
              <div class="bg-cyan-50 border border-cyan-100 rounded-xl p-3 text-center">
                <div class="text-lg font-bold text-cyan-700">KES {{ paymentTotals.last }}</div>
                <div class="text-[10px] text-cyan-500 uppercase tracking-wide">Last Payment</div>
              </div>
            </div>
            <div v-for="(p, i) in paymentHistory" :key="p.id || i"
              class="bg-white rounded-xl border border-slate-200 p-4 shadow-sm hover:border-cyan-200 transition-colors">
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                  <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                    :class="p.status === 'verified' || p.status === 'completed' ? 'bg-emerald-100' : 'bg-amber-100'">
                    <ReceiptText v-if="p.status === 'verified' || p.status === 'completed'" class="w-4 h-4 text-emerald-600" />
                    <Clock v-else class="w-4 h-4 text-amber-600" />
                  </div>
                  <div>
                    <div class="text-sm font-semibold text-slate-800">KES {{ p.amount ?? '—' }}</div>
                    <div class="text-xs text-slate-400 mt-0.5">{{ formatDate(p.created_at || p.paid_at) }}</div>
                  </div>
                </div>
                <div class="text-right">
                  <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold"
                    :class="p.status === 'verified' || p.status === 'completed' ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                    {{ p.status || 'pending' }}
                  </span>
                  <div class="text-xs text-slate-400 mt-1">{{ p.payment_method || p.channel || '—' }}</div>
                </div>
              </div>
              <div v-if="p.reference || p.transaction_id || p.mpesa_receipt" class="mt-2 text-xs text-slate-400 font-mono bg-slate-50 px-3 py-1.5 rounded-lg">
                Ref: {{ p.reference || p.transaction_id || p.mpesa_receipt }}
              </div>
            </div>
          </div>
          <div v-else class="flex flex-col items-center gap-3 py-16 text-slate-400">
            <Banknote class="w-12 h-12 opacity-30" />
            <p class="text-sm font-medium">No payment history</p>
          </div>
        </div>

      </div><!-- end tab content -->

      <!-- Panel footer -->
      <div class="flex-shrink-0 px-6 py-3 bg-white border-t border-slate-200 flex items-center justify-between gap-3">
        <div class="flex items-center gap-2">
          <button
            @click="handleToggleBlock(panelUser)"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors"
            :class="panelUser.status === 'blocked'
              ? 'text-emerald-700 bg-emerald-50 border-emerald-200 hover:bg-emerald-100'
              : 'text-amber-700 bg-amber-50 border-amber-200 hover:bg-amber-100'"
          >
            <ShieldOff v-if="panelUser.status !== 'blocked'" class="w-3.5 h-3.5" />
            <ShieldCheck v-else class="w-3.5 h-3.5" />
            {{ panelUser.status === 'blocked' ? 'Unblock' : 'Block' }}
          </button>
          <button v-if="activeTab === 'session' && currentSession"
            @click="handleDisconnectSession(currentSession)"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold text-rose-600 bg-rose-50 border border-rose-200 hover:bg-rose-100 rounded-lg transition-colors">
            <Unplug class="w-3.5 h-3.5" /> Disconnect Session
          </button>
          <button v-if="activeTab === 'details'"
            @click="toggleEdit"
            class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border transition-colors"
            :class="editExpanded
              ? 'text-slate-600 bg-slate-50 border-slate-300 hover:bg-slate-100'
              : 'text-cyan-700 bg-cyan-50 border-cyan-200 hover:bg-cyan-100'">
            <Pencil class="w-3.5 h-3.5" />
            {{ editExpanded ? 'Collapse Edit' : 'Edit User' }}
          </button>
        </div>
        <button @click="showUserPanel = false" class="px-4 py-2 text-sm font-medium text-slate-600 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
          Close
        </button>
      </div>
    </div>
  </SlideOverlay>

</template>

<script setup>
import { computed, defineComponent, h, nextTick, onMounted, onUnmounted, reactive, ref, watch } from 'vue'
import {
  Eye, EyeOff, AlertCircle, ShieldOff, ShieldCheck, WifiOff, Unplug,
  UserCircle2, KeyRound, CreditCard, Pencil, History, Banknote, Clock,
  RotateCcw, Wifi, ReceiptText, TrendingUp, MoreVertical, Ban, Trash2
} from 'lucide-vue-next'
import axios from '@/modules/common/services/api/axios'
import { useHotspot } from '@/modules/tenant/composables/useHotspot'
import { useConfirmStore } from '@/stores/confirm'
import DataViewContainer from '@/modules/common/components/base/DataViewContainer.vue'
import DataSkeleton from '@/modules/common/components/base/DataSkeleton.vue'
import DataPagination from '@/modules/common/components/base/DataPagination.vue'
import DataEmptyState from '@/modules/common/components/base/DataEmptyState.vue'
import EntityStatusBadge from '@/modules/common/components/base/EntityStatusBadge.vue'
import SlideOverlay from '@/modules/common/components/base/SlideOverlay.vue'
import BaseInput from '@/modules/common/components/base/BaseInput.vue'
import BaseSelect from '@/modules/common/components/base/BaseSelect.vue'
import BaseAlert from '@/modules/common/components/base/BaseAlert.vue'

const confirmStore = useConfirmStore()

// ── Data ───────────────────────────────────────────────────────────────────────
const {
  users, packages, loading, error,
  activeUsers, expiredUsers,
  fetchUsers, fetchPackages,
  disconnectUser: disconnectUserAction,
  grantAccess, revokeAccess,
  getUserDetails, updateUser, deleteUser,
  subscribeToWebSocket, unsubscribeFromWebSocket,
} = useHotspot()

const inactiveUsers = computed(() => (users.value || []).filter(u => u.status === 'inactive'))
const blockedUsers  = computed(() => (users.value || []).filter(u => u.status === 'blocked'))

// ── Filters / search / pagination ─────────────────────────────────────────────
const searchQuery = ref('')
const filters = reactive({ status: '', package_id: '' })
const currentPage  = ref(1)
const itemsPerPage = ref(10)

const filteredData = computed(() => {
  let data = users.value || []
  if (searchQuery.value) {
    const q = searchQuery.value.toLowerCase()
    data = data.filter(u =>
      u.username?.toLowerCase().includes(q) ||
      u.name?.toLowerCase().includes(q) ||
      u.phone?.includes(q) ||
      u.phone_number?.includes(q) ||
      u.email?.toLowerCase().includes(q)
    )
  }
  if (filters.status)     data = data.filter(u => u.status === filters.status)
  if (filters.package_id) data = data.filter(u => String(u.package_id) === String(filters.package_id))
  return data
})

const paginatedData    = computed(() => filteredData.value.slice((currentPage.value - 1) * itemsPerPage.value, currentPage.value * itemsPerPage.value))
const totalPages       = computed(() => Math.ceil(filteredData.value.length / itemsPerPage.value))
const hasActiveFilters = computed(() => filters.status || filters.package_id || searchQuery.value)

watch([searchQuery, itemsPerPage, () => filters.status, () => filters.package_id], () => { currentPage.value = 1 })

// ── Panel state ────────────────────────────────────────────────────────────────
const showUserPanel  = ref(false)
const panelUser      = ref(null)
const activeTab      = ref('details')
const editExpanded   = ref(false)
const panelScrollRef = ref(null)

const panelTabs = [
  { id: 'details',  label: 'User Details',        icon: UserCircle2 },
  { id: 'session',  label: 'Current Session',      icon: Wifi },
  { id: 'history',  label: 'Historical Sessions',  icon: History },
  { id: 'payments', label: 'Payments',             icon: ReceiptText },
]

const currentSession  = ref(null)
const sessionHistory  = ref([])
const paymentHistory  = ref([])
const sessionLoading  = ref(false)
const historyLoading  = ref(false)
const paymentsLoading = ref(false)
const trafficLoading  = ref(false)

const paymentTotals = computed(() => {
  const list = paymentHistory.value
  return {
    count: list.length,
    total: list.reduce((s, p) => s + Number(p.amount ?? 0), 0),
    last:  list.length ? Number(list[0].amount ?? 0) : 0,
  }
})

const openUserPanel = async (user) => {
  panelUser.value    = user
  showUserPanel.value = true
  activeTab.value    = 'details'
  editExpanded.value = false
  showPasswordValue.value = false
  userPassword.value = ''
  editForm.package_id       = user.package_id       || ''
  editForm.phone_number     = user.phone_number     || user.phone || ''
  editForm.mac_address      = user.mac_address      || ''
  editForm.simultaneous_use = Number(user.simultaneous_use ?? 1)
  editForm.status           = user.status           || 'active'
  resetEditErrors()
  loadCurrentSession(user)
  loadSessionHistory(user)
  loadPaymentHistory(user)
  loadTrafficMetrics(user)
  try {
    const fresh = await getUserDetails(user.id)
    if (fresh && panelUser.value?.id === user.id) panelUser.value = { ...panelUser.value, ...fresh }
  } catch { /**/ }
}

watch(showUserPanel, (v) => {
  if (!v) {
    currentSession.value  = null
    sessionHistory.value  = []
    paymentHistory.value  = []
    downloadSeries.value  = []
    uploadSeries.value    = []
    editExpanded.value    = false
  }
})

watch(activeTab, (tab) => {
  if (!panelUser.value || !showUserPanel.value) return
  if (tab === 'session')  { loadCurrentSession(panelUser.value); fetchUserChart() }
  if (tab === 'history')  loadSessionHistory(panelUser.value)
  if (tab === 'payments') loadPaymentHistory(panelUser.value)
})

watch(users, (list) => {
  if (!panelUser.value || !showUserPanel.value) return
  const updated = list.find(u => u.id === panelUser.value.id)
  if (updated) panelUser.value = { ...panelUser.value, ...updated }
}, { deep: true })

const loadCurrentSession = async (user, silent = false) => {
  if (!silent) sessionLoading.value = true
  try {
    const res = await axios.get('hotspot/sessions/live')
    const list = res.data?.sessions || res.data?.data || res.data || []
    const match = Array.isArray(list)
      ? list.find(s => (s.username || '').toLowerCase() === user.username.toLowerCase())
      : null
    currentSession.value = match || null
  } catch { if (!silent) currentSession.value = null }
  finally { if (!silent) sessionLoading.value = false }
}

const loadSessionHistory = async (user) => {
  historyLoading.value = true
  try {
    const res = await axios.get('/hotspot/sessions/inactive', { params: { username: user.username, per_page: 200 } })
    const raw  = res.data?.data || res.data?.sessions || res.data || []
    const list = Array.isArray(raw) ? raw : []
    sessionHistory.value = list
      .filter(s => (s.username || '').toLowerCase() === user.username.toLowerCase())
      .sort((a, b) => new Date(b.disconnected_at || b.acctstoptime || b.session_end || 0) - new Date(a.disconnected_at || a.acctstoptime || a.session_end || 0))
  } catch { sessionHistory.value = [] }
  finally { historyLoading.value = false }
}

const loadPaymentHistory = async (user) => {
  paymentsLoading.value = true
  try {
    const res = await axios.get(`/hotspot/payments/user/${user.id}`)
    const list = res.data?.data || res.data?.payments || res.data || []
    paymentHistory.value = Array.isArray(list)
      ? list.sort((a, b) => new Date(b.created_at || b.paid_at || 0) - new Date(a.created_at || a.paid_at || 0))
      : []
  } catch { paymentHistory.value = [] }
  finally { paymentsLoading.value = false }
}

// ── Traffic chart ──────────────────────────────────────────────────────────────
const chartContainer  = ref(null)
const chartRange      = ref('1h')
const chartType       = ref('line')
const downloadSeries  = ref([])
const uploadSeries    = ref([])
const chartHoverIdx   = ref(-1)
const chartHoverData  = ref(null)

const chartHasData = computed(() => downloadSeries.value.some(p => p.v > 0) || uploadSeries.value.some(p => p.v > 0))
const chartMax     = computed(() => Math.max(...downloadSeries.value.map(p => p.v), ...uploadSeries.value.map(p => p.v), 1))
const chartPeakDl  = computed(() => Math.max(...downloadSeries.value.map(p => p.v), 0))
const chartPeakUl  = computed(() => Math.max(...uploadSeries.value.map(p => p.v), 0))

const chartYTicks = computed(() => {
  const max = chartMax.value, n = 4
  return Array.from({ length: n + 1 }, (_, i) => ({ value: (max / n) * i, label: formatBytes((max / n) * i) + '/s', percent: (i / n) * 100 }))
})

const chartXTicks = computed(() => {
  const data = downloadSeries.value
  if (!data.length) return []
  const count = 5, step = Math.floor((data.length - 1) / (count - 1)) || 1, pad = 5, usable = 100 - pad * 2
  const ticks = [], seen = new Set()
  let ti = 0
  for (let i = 0; i < data.length && ticks.length < count; i += step) {
    const label = new Date(data[i].t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
    if (seen.has(label) && i > 0 && i < data.length - 1) continue
    seen.add(label); ticks.push({ x: pad + (ti / (count - 1)) * usable, label }); ti++
  }
  const last = data[data.length - 1], lastLabel = new Date(last.t * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
  if (ticks.length && ticks[ticks.length - 1].label !== lastLabel) ticks.push({ x: pad + usable, label: lastLabel })
  return ticks
})

const chartHoverX = computed(() => chartHoverIdx.value < 0 || !downloadSeries.value.length ? 0 : (chartHoverIdx.value / (downloadSeries.value.length - 1)) * 100)

const buildChartPath = (series) => {
  if (!series.length) return ''
  const max = Math.max(chartMax.value, 1), minT = series[0].t, range = (series[series.length - 1].t || minT + 1) - minT || 1
  return 'M' + series.map(p => `${(((p.t - minT) / range) * 1000).toFixed(1)},${(200 - (p.v / max) * 200).toFixed(1)}`).join('L')
}
const buildAreaPath = (series) => {
  if (!series.length) return ''
  const line = buildChartPath(series), minT = series[0].t, range = (series[series.length - 1].t || minT + 1) - minT || 1
  const lastX = (((series[series.length - 1].t) - minT) / range * 1000).toFixed(1)
  return `${line}L${lastX},200 L0.0,200 Z`
}

const svgDlPath     = computed(() => buildChartPath(downloadSeries.value))
const svgUlPath     = computed(() => buildChartPath(uploadSeries.value))
const svgDlAreaPath = computed(() => buildAreaPath(downloadSeries.value))
const svgUlAreaPath = computed(() => buildAreaPath(uploadSeries.value))

const onChartHover = (event) => {
  const data = downloadSeries.value
  if (!data.length || !chartContainer.value) return
  const rect = chartContainer.value.getBoundingClientRect()
  let idx = Math.round(((event.clientX - rect.left) / rect.width) * (data.length - 1))
  idx = Math.max(0, Math.min(idx, data.length - 1))
  chartHoverIdx.value  = idx
  chartHoverData.value = { t: data[idx].t, dl: data[idx].v, ul: uploadSeries.value[idx]?.v ?? 0 }
}
const onChartLeave = () => { chartHoverIdx.value = -1; chartHoverData.value = null }
const chartFormatTime = (ts) => ts ? new Date(ts * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''

const fetchUserChart = async () => {
  const username = panelUser.value?.username
  if (!username) return
  trafficLoading.value = true
  downloadSeries.value = []; uploadSeries.value = []
  try {
    const step = chartRange.value === '7d' ? '1h' : chartRange.value === '24h' ? '5m' : chartRange.value === '6h' ? '2m' : '30s'
    const resp = await axios.get(`hotspot/metrics/user/${encodeURIComponent(username)}`, { params: { range: chartRange.value, step } })
    const dl = resp.data?.download?.data?.result?.[0]?.values ?? []
    const ul = resp.data?.upload?.data?.result?.[0]?.values   ?? []
    downloadSeries.value = dl.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
    uploadSeries.value   = ul.map(([t, v]) => ({ t: Number(t), v: parseFloat(v) || 0 }))
  } catch { /**/ }
  finally { trafficLoading.value = false }
}

const loadTrafficMetrics = (user) => { if (user?.username) fetchUserChart() }

// ── Password ───────────────────────────────────────────────────────────────────
const showPasswordValue = ref(false)
const userPassword      = ref('')
const loadingPassword   = ref(false)

const handleViewPassword = async () => {
  if (!panelUser.value) return
  loadingPassword.value = true
  try {
    const res = await axios.get(`/hotspot/users/${panelUser.value.id}/password`)
    userPassword.value = res.data?.password || res.data?.data?.password || ''
    showPasswordValue.value = true
  } catch { /**/ } finally { loadingPassword.value = false }
}

const hidePassword = () => { showPasswordValue.value = false; userPassword.value = '' }

const handleResetPassword = async () => {
  if (!panelUser.value) return
  const ok = await confirmStore.open({ title: 'Reset Password', message: `Reset password for ${panelUser.value.username}?`, confirmText: 'Reset', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    const res = await axios.post(`/hotspot/users/${panelUser.value.id}/reset-password`)
    const pw = res.data?.generated_password || res.data?.password || ''
    userPassword.value = pw
    showPasswordValue.value = true
  } catch { /**/ }
}

// ── Edit ───────────────────────────────────────────────────────────────────────
const editSubmitting  = ref(false)
const editFormError   = ref('')
const editFieldErrors = reactive({ package_id: '', phone_number: '', mac_address: '', simultaneous_use: '', status: '' })
const editForm = reactive({ package_id: '', phone_number: '', mac_address: '', simultaneous_use: 1, status: 'active' })

const resetEditErrors = () => { editFormError.value = ''; Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = '') }

const handleUpdateUser = async () => {
  if (!panelUser.value) return
  resetEditErrors()
  editSubmitting.value = true
  try {
    const res = await updateUser(panelUser.value.id, {
      package_id:       editForm.package_id,
      phone_number:     editForm.phone_number,
      mac_address:      editForm.mac_address,
      simultaneous_use: Number(editForm.simultaneous_use || 1),
      status:           String(editForm.status || 'active'),
    })
    const updated = res?.data || res
    panelUser.value = { ...panelUser.value, ...(updated || {}) }
    editExpanded.value = false
    resetEditErrors()
    // No need to call fetchUsers - WebSocket event will update the list
  } catch (err) {
    const message = err.response?.data?.message || 'Failed to update user'
    if (err.response?.status === 422) {
      const errs = err.response?.data?.errors || {}
      Object.keys(editFieldErrors).forEach(k => editFieldErrors[k] = errs[k]?.[0] || '')
    }
    editFormError.value = message
  } finally { editSubmitting.value = false }
}

// ── Block / Disconnect ─────────────────────────────────────────────────────────
const handleToggleBlock = async (user) => {
  if (!user) return
  const action = user.status === 'blocked' ? 'unblock' : 'block'
  const ok = await confirmStore.open({
    title: 'Confirm Action',
    message: `${action === 'block' ? 'Block' : 'Unblock'} ${user.name || user.username}?`,
    confirmText: 'Confirm', cancelText: 'Cancel',
    variant: user.status === 'blocked' ? 'success' : 'warning',
  })
  if (!ok) return
  try {
    await revokeAccess(user.id)
    if (panelUser.value?.id === user.id) {
      panelUser.value = { ...panelUser.value, status: action === 'block' ? 'blocked' : 'active' }
    }
    // No need to call fetchUsers - WebSocket event will update the list
  } catch { /**/ }
}

const handleDisconnect = async (user) => {
  if (!user) return
  closeMenu()
  const ok = await confirmStore.open({ title: 'Disconnect User', message: `Disconnect "${user.username}"?`, confirmText: 'Disconnect', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    await disconnectUserAction(user.id)
  } catch { /**/ }
}

const handleDeleteUser = async (user) => {
  closeMenu()
  if (!user) return
  const confirmed = await confirmStore.open({
    title: 'Delete User',
    message: `Permanently delete ${user.username}? This action cannot be undone.`,
    confirmText: 'Delete',
    cancelText: 'Cancel',
    variant: 'danger',
  })
  if (!confirmed) return
  try {
    await deleteUser(user.id)
    // Close panel if open for this user
    if (showUserPanel.value && panelUser.value?.id === user.id) {
      showUserPanel.value = false
    }
  } catch { /**/ }
}

const handleDisconnectSession = async (session) => {
  const ok = await confirmStore.open({ title: 'Disconnect Session', message: 'Force-disconnect this session?', confirmText: 'Disconnect', cancelText: 'Cancel', variant: 'warning' })
  if (!ok) return
  try {
    await axios.post('/hotspot/sessions/disconnect', { username: panelUser.value?.username, session_id: session.radacctid || session.id })
    currentSession.value = null
  } catch { /**/ }
}

const handleGrantAccess = async (user) => {
  if (!user) return
  closeMenu()
  try { await grantAccess(user.id) } catch { /**/ }
}

const handleRevokeAccess = async (user) => {
  if (!user) return
  const ok = await confirmStore.open({ title: 'Revoke Access', message: `Revoke access for "${user.username}"?`, confirmText: 'Revoke', cancelText: 'Cancel', variant: 'danger' })
  if (!ok) return
  try { await revokeAccess(user.id) } catch { /**/ }
}

// ── Toggle edit with auto-scroll ───────────────────────────────────────────────
const toggleEdit = async () => {
  editExpanded.value = !editExpanded.value
  if (editExpanded.value) {
    await nextTick()
    const container = panelScrollRef.value
    if (container) container.scrollTo({ top: container.scrollHeight, behavior: 'smooth' })
  }
}

// ── Three-dot menu ─────────────────────────────────────────────────────────────
const activeMenu   = ref(null)
const menuPosition = ref({})

const toggleMenu = (userId, event) => {
  event.stopPropagation()
  if (activeMenu.value === userId) { closeMenu(); return }
  activeMenu.value = userId
  const rect = event.currentTarget.getBoundingClientRect()
  const menuWidth = 208, menuHeight = 280
  let top  = rect.bottom + 4
  let left = rect.right - menuWidth
  if (rect.bottom + menuHeight > window.innerHeight) top  = rect.top - menuHeight - 4
  if (left < 8) left = rect.left
  if (left + menuWidth > window.innerWidth - 8) left = window.innerWidth - menuWidth - 8
  menuPosition.value = { top: `${top}px`, left: `${left}px` }
}

const closeMenu = () => { activeMenu.value = null; menuPosition.value = {} }

const handleClickOutside = (e) => {
  const menu   = document.querySelector('[data-dropdown-menu]')
  const button = document.querySelector('[data-menu-button]')
  if (menu && !menu.contains(e.target) && button && !button.contains(e.target)) closeMenu()
}
const handleKeydown = (e) => { if (e.key === 'Escape') closeMenu() }

const openEditFromMenu = (user) => {
  closeMenu()
  if (!user) return
  openUserPanel(user)
  setTimeout(() => { activeTab.value = 'details'; editExpanded.value = true }, 50)
}

// ── Helpers ────────────────────────────────────────────────────────────────────
const getUserInitials = (user) => {
  const n = user?.name || user?.username || 'U'
  return n.split(/[\s._-]+/).map(x => x[0]).join('').toUpperCase().slice(0, 2)
}

const formatDate = (d) => {
  if (!d) return '—'
  const ts = typeof d === 'number' || /^\d+$/.test(String(d)) ? new Date(Number(d) * 1000) : new Date(d)
  return ts.toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const formatDateShort = (d) => {
  if (!d) return '—'
  const ts = typeof d === 'number' || /^\d+$/.test(String(d)) ? new Date(Number(d) * 1000) : new Date(d)
  return ts.toLocaleDateString('en-US', { year: 'numeric', month: 'short', day: 'numeric' })
}

const formatPackageSpeed = (user) => {
  const dl = user?.download_speed || user?.package?.download_speed || ''
  const ul = user?.upload_speed   || user?.package?.upload_speed   || ''
  if (dl && ul) return `${dl} / ${ul}`
  return dl || ul || '—'
}

const getDaysClass = (days) => {
  if (days === null || days === undefined) return 'text-slate-400'
  if (days < 0)  return 'text-rose-600'
  if (days <= 7) return 'text-amber-600'
  return 'text-emerald-600'
}

const formatDaysToExpiry = (days) => {
  if (days === null || days === undefined) return '—'
  const d = Math.round(Number(days))
  if (d < 0) return `Expired ${Math.abs(d)}d ago`
  if (d === 0) return 'Expires today'
  return `${d} days`
}

const formatBytes = (bytes) => {
  const b = Number(bytes) || 0
  if (b < 1024) return `${b.toFixed(2)} B`
  if (b < 1024 ** 2) return `${(b / 1024).toFixed(2)} KB`
  if (b < 1024 ** 3) return `${(b / 1024 ** 2).toFixed(2)} MB`
  return `${(b / 1024 ** 3).toFixed(2)} GB`
}

const formatDuration = (secs) => {
  const s = Number(secs) || 0
  if (!s) return '—'
  const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60)
  return h > 0 ? `${h}h ${m}m` : `${m}m`
}

const getDataUsagePercent = (usage, limit) => {
  if (!limit || limit === 0) return '0%'
  return `${Math.min((usage || 0) / limit * 100, 100)}%`
}

const getDataUsageColor = (usage, limit) => {
  if (!limit) return 'bg-slate-400'
  const p = (usage || 0) / limit
  if (p > 0.9) return 'bg-red-500'
  if (p > 0.7) return 'bg-amber-500'
  return 'bg-emerald-500'
}

const reasonBadge = (cause) => {
  if (!cause) return 'bg-slate-100 text-slate-600'
  if (cause === 'User-Request') return 'bg-blue-100 text-blue-700'
  if (cause.includes('Timeout')) return 'bg-amber-100 text-amber-700'
  if (cause.includes('Error') || cause.includes('Lost')) return 'bg-rose-100 text-rose-700'
  if (cause.includes('Admin') || cause.includes('NAS')) return 'bg-purple-100 text-purple-700'
  return 'bg-slate-100 text-slate-600'
}

// ── InfoCell component ─────────────────────────────────────────────────────────
const InfoCell = defineComponent({
  props: { label: String, value: String, mono: Boolean },
  setup(props, { slots }) {
    return () => h('div', {}, [
      h('div', { class: 'text-[10px] font-semibold text-slate-400 uppercase tracking-wider mb-1' }, props.label),
      slots.default
        ? slots.default()
        : h('div', { class: props.mono ? 'text-sm font-mono text-slate-700' : 'text-sm text-slate-700' }, props.value || '—'),
    ])
  },
})

// ── Lifecycle ──────────────────────────────────────────────────────────────────
onMounted(() => {
  void fetchUsers().catch(() => {})
  requestAnimationFrame(() => {
    void fetchPackages().catch(() => {})
  })
  subscribeToWebSocket()
  document.addEventListener('click', handleClickOutside)
  document.addEventListener('keydown', handleKeydown)
})

onUnmounted(() => {
  unsubscribeFromWebSocket()
  document.removeEventListener('click', handleClickOutside)
  document.removeEventListener('keydown', handleKeydown)
})
</script>

<style scoped>
.slide-down-enter-active, .slide-down-leave-active { transition: all 0.2s ease; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 4px; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
</style>
