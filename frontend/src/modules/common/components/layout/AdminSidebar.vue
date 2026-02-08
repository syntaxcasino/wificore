<template>
  <aside
    class="fixed left-0 w-64 bg-gradient-to-b from-gray-900 via-gray-900 to-gray-950 text-gray-100 flex flex-col justify-between border-r border-gray-800/50 shadow-2xl transition-all duration-300 ease-in-out"
    :class="{
      'sidebar-open': isSidebarOpen,
      'sidebar-closed': !isSidebarOpen,
      'sidebar-mobile': isMobile,
      'sidebar-desktop': !isMobile,
      'top-0 h-screen': isMobile,
      'top-16 h-[calc(100vh-4rem)]': !isMobile,
    }"
  >
    <div
      class="overflow-y-auto scrollbar-thin scrollbar-thumb-gray-700 scrollbar-track-transparent hover:scrollbar-thumb-gray-600"
    >
      <nav class="p-3 space-y-1">
        <!-- Dashboard — /api/system/dashboard/stats -->
        <router-link
          to="/dashboard"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="isDashboardActive ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <LayoutDashboard class="w-4 h-4 flex-shrink-0" :class="isDashboardActive ? 'text-blue-400' : ''" />
          <span class="text-sm">Dashboard</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>
        <div class="px-2 py-1 text-[9px] font-bold text-gray-600 uppercase tracking-wider">Platform</div>

        <!-- Tenants — /api/system/tenants/* -->
        <router-link
          to="/system/tenants"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/tenants') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Building2 class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/tenants') ? 'text-cyan-400' : ''" />
          <span class="text-sm">Tenants</span>
        </router-link>

        <!-- System Admins — /api/system/admins/* -->
        <router-link
          to="/system/admins"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/admins') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <UserCircle class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/admins') ? 'text-purple-400' : ''" />
          <span class="text-sm">System Admins</span>
        </router-link>

        <!-- Landlord Billing — /api/system/landlord/* -->
        <div>
          <button
            @click="toggleMenu('billing')"
            class="w-full flex items-center justify-between py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150"
            :class="route.path.startsWith('/system/billing') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          >
            <span class="flex items-center gap-2.5">
              <Wallet class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/billing') ? 'text-green-400' : ''" />
              <span class="text-sm">Landlord Billing</span>
            </span>
            <ChevronDown class="w-3 h-3 transition-transform" :class="activeMenu === 'billing' ? 'rotate-180' : ''" />
          </button>
          <div v-show="activeMenu === 'billing'" class="ml-6 mt-1 space-y-0.5">
            <router-link
              to="/system/billing/configuration"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/system/billing/configuration' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Configuration
            </router-link>
            <router-link
              to="/system/billing/metrics"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/system/billing/metrics' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Metrics
            </router-link>
            <router-link
              to="/system/billing/overrides"
              class="block py-1.5 px-3 text-xs rounded hover:bg-gray-800/40 transition-all"
              :class="route.path === '/system/billing/overrides' ? 'text-white font-medium' : 'text-gray-500'"
              @click="isMobile && $emit('close-sidebar')"
            >
              Overrides
            </router-link>
          </div>
        </div>

        <!-- IP Pools — /api/system/tenant/ip-pools/* -->
        <router-link
          to="/system/ip-pools"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/ip-pools') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <Network class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/ip-pools') ? 'text-orange-400' : ''" />
          <span class="text-sm">IP Pools</span>
        </router-link>

        <!-- Divider -->
        <div class="h-px bg-gray-800/50 my-2"></div>
        <div class="px-2 py-1 text-[9px] font-bold text-gray-600 uppercase tracking-wider">Monitoring</div>

        <!-- System Health — /api/system/health/* -->
        <router-link
          to="/system/health"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/health') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <HeartPulse class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/health') ? 'text-red-400' : ''" />
          <span class="text-sm">System Health</span>
        </router-link>

        <!-- Metrics — /api/system/metrics, /api/system/queue/* -->
        <router-link
          to="/system/metrics"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/metrics') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <TrendingUp class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/metrics') ? 'text-blue-400' : ''" />
          <span class="text-sm">Metrics & Queues</span>
        </router-link>

        <!-- Activity Logs — /api/system/activity-logs -->
        <router-link
          to="/system/activity-logs"
          class="w-full flex items-center gap-2.5 py-2 px-3 rounded-md hover:bg-gray-800/60 transition-all duration-150 group"
          :class="route.path.startsWith('/system/activity-logs') ? 'bg-gray-800/80 text-white font-medium' : 'text-gray-300'"
          @click="isMobile && $emit('close-sidebar')"
        >
          <ScrollText class="w-4 h-4 flex-shrink-0" :class="route.path.startsWith('/system/activity-logs') ? 'text-yellow-400' : ''" />
          <span class="text-sm">Activity Logs</span>
        </router-link>
      </nav>
    </div>

    <!-- Sidebar Footer -->
    <div class="p-3 text-xs text-gray-600 border-t border-gray-800/50 bg-gray-950/50">
      <div class="font-semibold">{{ new Date().getFullYear() }} TraidNet</div>
      <div class="mt-0.5 text-gray-700">All rights reserved</div>
    </div>
  </aside>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute } from 'vue-router'
import {
  LayoutDashboard,
  Building2,
  UserCircle,
  Wallet,
  Network,
  HeartPulse,
  TrendingUp,
  ScrollText,
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

const route = useRoute()

const activeMenu = ref('')

const toggleMenu = (menu) => {
  if (activeMenu.value === menu) {
    activeMenu.value = ''
  } else {
    activeMenu.value = menu
  }
}

const isDashboardActive = computed(() => route.path === '/dashboard' || route.path === '/dashboard/home')
</script>

<style scoped>
.sidebar-desktop.sidebar-open {
  transform: translateX(0);
  z-index: 60;
}

.sidebar-desktop.sidebar-closed {
  transform: translateX(-100%);
  z-index: 60;
}

.sidebar-mobile {
  z-index: 100;
}

.sidebar-mobile.sidebar-open {
  transform: translateX(0);
}

.sidebar-mobile.sidebar-closed {
  transform: translateX(-100%);
}

@media (max-width: 768px) {
  aside {
    box-shadow: 2px 0 10px rgba(0, 0, 0, 0.3);
  }
}
</style>
