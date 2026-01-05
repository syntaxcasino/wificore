<template>
  <aside
    class="fixed top-16 left-0 w-64 h-[calc(100vh-4rem)] bg-gradient-to-b from-gray-900 via-gray-900 to-gray-950 text-gray-100 flex flex-col justify-between border-r border-gray-800/50 shadow-2xl transition-all duration-300 ease-in-out"
    :class="{
      'sidebar-open': isSidebarOpen,
      'sidebar-closed': !isSidebarOpen,
      'sidebar-mobile': isMobile,
      'sidebar-desktop': !isMobile,
    }"
  >
    <div
      class="overflow-y-auto scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent hover:scrollbar-thumb-gray-600"
    >
      <nav class="p-3 space-y-1">
        <!-- Dashboard -->
        <router-link
          :to="dashboardRoute"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="isDashboardActive ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <LayoutDashboard class="w-4 h-4 flex-shrink-0" :class="isDashboardActive ? 'text-blue-400' : ''" />
          <span class="text-sm">Dashboard</span>
        </router-link>

        <!-- Todos -->
        <router-link
          to="/dashboard/todos"
          class="w-full flex items-center justify-between gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path === '/dashboard/todos' ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <div class="flex items-center gap-2.5">
            <CheckSquare class="w-4 h-4 flex-shrink-0" :class="route.path === '/dashboard/todos' ? 'text-green-400' : ''" />
            <span class="text-sm">Todos</span>
          </div>
          <span v-if="todosCount > 0" class="px-1.5 py-0.5 text-[10px] font-bold rounded-full bg-green-500 text-white min-w-[18px] text-center">{{ todosCount }}</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Hotspot -->
        <div>
          <button
            @click="toggleMenu('hotspot')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/hotspot') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Radio class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Hotspot</span>
            </span>
            <div class="flex items-center gap-2">
              <span v-if="hotspotUsersCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-blue-500 text-white min-w-[16px] text-center">{{ hotspotUsersCount }}</span>
              <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'hotspot' ? 'rotate-180' : ''" />
            </div>
          </button>
          <div v-show="activeMenu === 'hotspot'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/hotspot/users"
              class="flex items-center justify-between py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hotspot/users' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              <span>Users</span>
              <span v-if="hotspotUsersCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-blue-500 text-white min-w-[16px] text-center">{{ hotspotUsersCount }}</span>
            </router-link>
            <router-link
              to="/dashboard/hotspot/sessions"
              class="flex items-center justify-between py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hotspot/sessions' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              <span>Active Sessions</span>
              <span v-if="hotspotSessionsCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-green-500 text-white min-w-[16px] text-center">{{ hotspotSessionsCount }}</span>
            </router-link>
            <router-link
              to="/dashboard/hotspot/vouchers/generate"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hotspot/vouchers/generate' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Generate Vouchers
            </router-link>
            <router-link
              to="/dashboard/hotspot/vouchers/bulk"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hotspot/vouchers/bulk' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Bulk Vouchers
            </router-link>
            <router-link
              to="/dashboard/hotspot/voucher-templates"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hotspot/voucher-templates' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Voucher Templates
            </router-link>
          </div>
        </div>

        <!-- PPPoE -->
        <div>
          <button
            @click="toggleMenu('pppoe')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/pppoe') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Cable class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">PPPoE</span>
            </span>
            <div class="flex items-center gap-2">
              <span v-if="pppoeUsersCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-purple-500 text-white min-w-[16px] text-center">{{ pppoeUsersCount }}</span>
              <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'pppoe' ? 'rotate-180' : ''" />
            </div>
          </button>
          <div v-show="activeMenu === 'pppoe'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/pppoe/users"
              class="flex items-center justify-between py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/pppoe/users' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              <span>Users</span>
              <span v-if="pppoeUsersCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-purple-500 text-white min-w-[16px] text-center">{{ pppoeUsersCount }}</span>
            </router-link>
            <router-link
              to="/dashboard/pppoe/sessions"
              class="flex items-center justify-between py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/pppoe/sessions' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              <span>Active Sessions</span>
              <span v-if="pppoeSessionsCount > 0" class="px-1.5 py-0.5 text-[9px] font-bold rounded-full bg-green-500 text-white min-w-[16px] text-center">{{ pppoeSessionsCount }}</span>
            </router-link>
          </div>
        </div>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Packages -->
        <div v-if="!isOnSystemAdminRoute">
          <button
            @click="toggleMenu('packages')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/packages') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Box class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Packages</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'packages' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'packages'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/packages/all"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/packages/all' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              All Packages
            </router-link>
            <router-link
              to="/dashboard/packages/groups"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/packages/groups' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Package Groups
            </router-link>
          </div>
        </div>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Billing -->
        <div>
          <button
            @click="toggleMenu('billing')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/billing') || route.path.includes('/finance') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Wallet class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Billing</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'billing' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'billing'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/billing/invoices"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/billing/invoices' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Invoices
            </router-link>
            <router-link
              to="/dashboard/billing/payments"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/billing/payments' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Payments
            </router-link>
            <router-link
              to="/dashboard/finance/revenues"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/finance/revenues' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Revenues
            </router-link>
            <router-link
              to="/dashboard/finance/expenses"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/finance/expenses' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Expenses
            </router-link>
          </div>
        </div>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2" v-if="!isOnSystemAdminRoute"></div>

        <!-- Network -->
        <div v-if="!isOnSystemAdminRoute">
          <button
            @click="toggleMenu('network')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/routers') || route.path.includes('/monitoring') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Router class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Network</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'network' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'network'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/routers/mikrotik"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/routers/mikrotik' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Routers
            </router-link>
            <router-link
              to="/dashboard/routers/access-points"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/routers/access-points' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Access Points
            </router-link>
            <router-link
              to="/dashboard/monitoring/connections"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/monitoring/connections' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Live Connections
            </router-link>
            <router-link
              to="/dashboard/monitoring/traffic-graphs"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/monitoring/traffic-graphs' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Traffic Monitoring
            </router-link>
          </div>
        </div>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Reports -->
        <div>
          <button
            @click="toggleMenu('reports')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/reports') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <TrendingUp class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Reports</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'reports' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'reports'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/reports/payments"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/reports/payments' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Payment Reports
            </router-link>
            <router-link
              to="/dashboard/reports/session-history"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/reports/session-history' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Session History
            </router-link>
            <router-link
              to="/dashboard/reports/bandwidth-usage"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/reports/bandwidth-usage' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Bandwidth Usage
            </router-link>
          </div>
        </div>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Team Management (Consolidated Staff & HR) -->
        <div>
          <button
            @click="toggleMenu('team')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/users/') || route.path.includes('/hr/') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <UserCircle class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Team Management</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'team' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'team'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/users/all"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/users/all' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              All Staff
            </router-link>
            <router-link
              to="/dashboard/hr/employees"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hr/employees' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Employees
            </router-link>
            <router-link
              to="/dashboard/hr/departments"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hr/departments' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Departments
            </router-link>
            <router-link
              to="/dashboard/hr/positions"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/hr/positions' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Positions
            </router-link>
            <router-link
              to="/dashboard/users/roles"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/users/roles' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Roles & Permissions
            </router-link>
          </div>
        </div>

        <!-- Branding -->
        <router-link
          to="/dashboard/hotspot/login-page"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/hotspot/login-page') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Palette class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Branding</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- Support -->
        <div>
          <button
            @click="toggleMenu('support')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/support') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <LifeBuoy class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Support</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'support' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'support'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/support/create-ticket"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/support/create-ticket' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Create Ticket
            </router-link>
            <router-link
              to="/dashboard/support/all-tickets"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/support/all-tickets' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              All Tickets
            </router-link>
          </div>
        </div>

        <!-- Settings -->
        <div>
          <button
            @click="toggleMenu('settings')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/settings') || route.path.includes('/admin/system-updates') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          >
            <span class="flex items-center gap-2.5">
              <Settings class="w-4 h-4 flex-shrink-0" />
              <span class="text-sm">Settings</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'settings' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'settings'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/dashboard/settings/general"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/settings/general' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Organization
            </router-link>
            <router-link
              to="/dashboard/admin/system-updates"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/admin/system-updates' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              System Updates
            </router-link>
            <router-link
              to="/dashboard/settings/email-sms"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/settings/email-sms' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Email & SMS
            </router-link>
            <router-link
              to="/dashboard/settings/mpesa-api"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/settings/mpesa-api' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Payment Gateway
            </router-link>
            <router-link
              to="/dashboard/settings/timezone-locale"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/dashboard/settings/timezone-locale' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Timezone & Locale
            </router-link>
          </div>
        </div>

        <!-- System Admin Tools -->
        <template v-if="isSystemAdmin">
          <div class="h-px bg-gray-800/50 my-2"></div>
          <div class="px-2 py-1 text-[9px] font-bold text-red-500 uppercase tracking-wider">System Admin</div>
          
          <div>
            <button
              @click="toggleMenu('admin')"
              class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
              :class="route.path.includes('/admin') && !route.path.includes('/admin/system-updates') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
            >
              <span class="flex items-center gap-2.5">
                <Shield class="w-4 h-4 flex-shrink-0" />
                <span class="text-sm">Admin Tools</span>
              </span>
              <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'admin' ? 'rotate-180' : ''" />
            </button>
            <div v-show="activeMenu === 'admin'" class="ml-6 mt-1 space-y-0.5">
              <router-link
                to="/dashboard/admin/activity-logs"
                class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
                :class="route.path === '/dashboard/admin/activity-logs' ? 'text-white font-medium' : 'text-gray-500'"
                @click="isMobile && $emit('close-sidebar')"
              >
                Activity Logs
              </router-link>
              <router-link
                to="/dashboard/admin/backup-restore"
                class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
                :class="route.path === '/dashboard/admin/backup-restore' ? 'text-white font-medium' : 'text-gray-500'"
                @click="isMobile && $emit('close-sidebar')"
              >
                Backup & Restore
              </router-link>
            </div>
          </div>
        </template>
      </nav>
    </div>

    <!-- Sidebar Footer -->
    <div class="p-3 text-xs text-gray-600 border-t border-gray-800/50 bg-gray-950/50">
      <div class="font-semibold">© {{ new Date().getFullYear() }} TraidNet</div>
      <div class="mt-0.5 text-gray-700">All rights reserved</div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  LayoutDashboard,
  CheckSquare,
  Radio,
  Cable,
  UserCircle,
  Box,
  Ticket,
  Wallet,
  DollarSign,
  Router,
  LineChart,
  TrendingUp,
  Briefcase,
  Palette,
  LifeBuoy,
  Settings,
  Shield,
  ChevronDown
} from 'lucide-vue-next'

const props = defineProps({
  isSidebarOpen: {
    type: Boolean,
    required: true
  },
  isMobile: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['close-sidebar', 'toggle-sidebar'])

const authStore = useAuthStore()
const route = useRoute()

// Active menu state for dropdowns
const activeMenu = ref('')

// Badge counts
const todosCount = ref(0)
const hotspotUsersCount = ref(0)
const hotspotSessionsCount = ref(0)
const pppoeUsersCount = ref(0)
const pppoeSessionsCount = ref(0)

const toggleMenu = (menu) => {
  if (activeMenu.value === menu) {
    activeMenu.value = ''
  } else {
    activeMenu.value = menu
  }
}

// Fetch badge counts
const fetchBadgeCounts = async () => {
  try {
    todosCount.value = authStore.user?.pending_todos || 0
    hotspotUsersCount.value = authStore.stats?.hotspot_users || 0
    hotspotSessionsCount.value = authStore.stats?.hotspot_sessions || 0
    pppoeUsersCount.value = authStore.stats?.pppoe_users || 0
    pppoeSessionsCount.value = authStore.stats?.pppoe_sessions || 0
  } catch (error) {
    console.error('Error fetching badge counts:', error)
  }
}

// Fetch counts on mount
onMounted(() => {
  fetchBadgeCounts()
})

const dashboardRoute = computed(() => authStore.dashboardRoute || '/dashboard')
const isDashboardActive = computed(() => route.path === '/dashboard' || route.path === '/dashboard/home')
const isOnSystemAdminRoute = computed(() => route.path.startsWith('/system-admin'))
const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
</script>

<style scoped>
/* Desktop sidebar - always visible when open */
.sidebar-desktop.sidebar-open {
  transform: translateX(0);
  z-index: 60;
}

.sidebar-desktop.sidebar-closed {
  transform: translateX(-100%);
  z-index: 60;
}

/* Mobile sidebar - slides over content */
.sidebar-mobile {
  z-index: 70;
}

.sidebar-mobile.sidebar-open {
  transform: translateX(0);
}

.sidebar-mobile.sidebar-closed {
  transform: translateX(-100%);
}

/* Mobile responsive */
@media (max-width: 768px) {
  aside {
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
  }
}
</style>
