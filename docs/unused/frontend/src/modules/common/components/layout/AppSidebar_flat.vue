<template>
  <aside
    class="fixed top-16 left-0 w-64 h-[calc(100vh-4rem)] bg-gradient-to-b from-gray-900 via-gray-900 to-gray-950 text-gray-100 flex flex-col justify-between border-r border-gray-800/50 shadow-2xl transition-all duration-300 ease-in-out z-40"
    :class="{
      hidden: !isSidebarOpen,
      block: isSidebarOpen,
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
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path === '/dashboard/todos' ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <CheckSquare class="w-4 h-4 flex-shrink-0" :class="route.path === '/dashboard/todos' ? 'text-green-400' : ''" />
          <span class="text-sm">Todos</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- USERS SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider">Users</div>
        
        <router-link
          to="/dashboard/hotspot/users"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/hotspot/users') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Radio class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Hotspot Users</span>
        </router-link>

        <router-link
          to="/dashboard/hotspot/sessions"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/hotspot/sessions') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Activity class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Active Sessions</span>
        </router-link>

        <router-link
          to="/dashboard/pppoe/users"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/pppoe/users') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Cable class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">PPPoE Users</span>
        </router-link>

        <router-link
          to="/dashboard/users/all"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/users/') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <UserCircle class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Admin Users</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- PRODUCTS SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider">Products</div>
        
        <router-link
          to="/dashboard/packages/all"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/packages') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
          v-if="!isOnSystemAdminRoute"
        >
          <Box class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Packages</span>
        </router-link>

        <router-link
          to="/dashboard/hotspot/vouchers/generate"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/vouchers') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Ticket class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Vouchers</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- BILLING SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider">Billing</div>
        
        <router-link
          to="/dashboard/billing/invoices"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/billing/invoices') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <FileText class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Invoices</span>
        </router-link>

        <router-link
          to="/dashboard/billing/payments"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/billing/payments') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <CreditCard class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Payments</span>
        </router-link>

        <router-link
          to="/dashboard/finance/expenses"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/finance') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <DollarSign class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Finance</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2" v-if="!isOnSystemAdminRoute"></div>

        <!-- NETWORK SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider" v-if="!isOnSystemAdminRoute">Network</div>
        
        <router-link
          to="/dashboard/routers/mikrotik"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/routers') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
          v-if="!isOnSystemAdminRoute"
        >
          <Router class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Routers</span>
        </router-link>

        <router-link
          to="/dashboard/monitoring/connections"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/monitoring') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
          v-if="!isOnSystemAdminRoute"
        >
          <LineChart class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Monitoring</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- REPORTS SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider">Reports</div>
        
        <router-link
          to="/dashboard/reports/revenue"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/reports') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <TrendingUp class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Analytics</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>

        <!-- ORGANIZATION SECTION -->
        <div class="px-2 py-1 text-[9px] font-bold text-gray-500 uppercase tracking-wider">Organization</div>
        
        <router-link
          to="/dashboard/hr/employees"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/hr') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Briefcase class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">HR Management</span>
        </router-link>

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

        <!-- SUPPORT & SETTINGS -->
        <router-link
          to="/dashboard/support/all-tickets"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/support') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <LifeBuoy class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Support</span>
        </router-link>

        <router-link
          to="/dashboard/settings/profile"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
          :class="route.path.includes('/settings') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Settings class="w-4 h-4 flex-shrink-0" />
          <span class="text-sm">Settings</span>
        </router-link>

        <!-- System Admin Tools -->
        <template v-if="isSystemAdmin">
          <div class="h-px bg-gray-800/50 my-2"></div>
          <div class="px-2 py-1 text-[9px] font-bold text-red-500 uppercase tracking-wider">System Admin</div>
          
          <router-link
            to="/dashboard/admin/system-health"
            class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.includes('/admin') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-400'"
            @click="isMobile && $emit('close-sidebar')"
          >
            <Shield class="w-4 h-4 flex-shrink-0" />
            <span class="text-sm">Admin Tools</span>
          </router-link>
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
import { ref, computed } from 'vue'
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
  FileText,
  CreditCard,
  DollarSign,
  Router,
  LineChart,
  TrendingUp,
  Briefcase,
  Palette,
  LifeBuoy,
  Settings,
  Shield,
  Activity
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

defineEmits(['close-sidebar'])

const authStore = useAuthStore()
const route = useRoute()

const dashboardRoute = computed(() => authStore.dashboardRoute || '/dashboard')
const isDashboardActive = computed(() => route.path === '/dashboard' || route.path === '/dashboard/home')
const isOnSystemAdminRoute = computed(() => route.path.startsWith('/system-admin'))
const isSystemAdmin = computed(() => authStore.user?.role === 'system_admin')
</script>
