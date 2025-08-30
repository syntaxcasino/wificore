<template>
  <aside
    :class="[
      isOpen ? 'translate-x-0' : '-translate-x-full',
      'fixed sm:relative top-0 left-0 z-50 h-full w-64 bg-white text-gray-900 border-r border-gray-200 transition-transform duration-300 ease-in-out',
    ]"
  >
    <div class="p-4">
      <button @click="$emit('toggle')" class="text-gray-900 mb-4 hidden sm:block">
        <LucideMenu class="w-6 h-6" />
      </button>
      <nav>
        <ul>
          <li class="mb-2">
            <router-link
              to="/dashboard"
              class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded"
            >
              <LucideLayoutDashboard class="w-5 h-5" /> Dashboard
            </router-link>
          </li>
          <li class="mb-2" v-if="userRole === 'admin'">
            <details class="group">
              <summary class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded cursor-pointer">
                <LucideUsers class="w-5 h-5" /> User Management
              </summary>
              <ul class="pl-6">
                <li>
                  <router-link
                    to="/dashboard/hotspot-users"
                    class="block p-2 hover:bg-gray-100 rounded"
                  >
                    Hotspot Users
                  </router-link>
                </li>
              </ul>
            </details>
          </li>
          <li class="mb-2">
            <router-link
              to="/dashboard/payments"
              class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded"
            >
              <LucideCreditCard class="w-5 h-5" /> Payments
            </router-link>
          </li>
          <li class="mb-2">
            <details class="group">
              <summary class="flex items-center gap-2 p-2 hover:bg-gray-100 rounded cursor-pointer">
                <LucideSettings class="w-5 h-5" /> Settings
              </summary>
              <ul class="pl-6">
                <li>
                  <router-link
                    to="/dashboard/settings/package"
                    class="block p-2 hover:bg-gray-100 rounded"
                    >Package</router-link
                  >
                </li>
                <li>
                  <router-link
                    to="/dashboard/settings/device-creation"
                    class="block p-2 hover:bg-gray-100 rounded"
                    >Device Creation</router-link
                  >
                </li>
              </ul>
            </details>
          </li>
        </ul>
      </nav>
    </div>
  </aside>
</template>

<script setup>
import {
  LucideLayoutDashboard,
  LucideUsers,
  LucideSettings,
  LucideCreditCard,
  Menu as LucideMenu,
} from 'lucide-vue-next'

defineProps({
  isOpen: Boolean,
  userRole: String,
})
</script>
