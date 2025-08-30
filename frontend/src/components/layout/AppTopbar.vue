<template>
  <header
    class="fixed top-0 left-0 right-0 h-16 bg-white shadow z-50 flex items-center justify-between px-4"
  >
    <div class="flex items-center gap-3">
      <button
        @click="$emit('toggle-sidebar')"
        class="p-2 rounded-md hover:bg-gray-100 active:bg-gray-200 transition-colors duration-150"
      >
        <Menu class="w-6 h-6 text-gray-800" />
      </button>
      <h1 class="text-xl font-bold text-gray-800">TraidNet Solutions</h1>
    </div>
  </header>
</template>

<script setup>
import { Menu } from 'lucide-vue-next'

// Emit toggle-sidebar event
defineEmits(['toggle-sidebar'])
</script>
