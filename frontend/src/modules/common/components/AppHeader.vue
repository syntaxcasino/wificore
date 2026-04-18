<template>
  <header class="bg-white dark:bg-slate-900 shadow px-8 py-4 mb-6">
    <div class="flex justify-between items-center max-w-[1200px] mx-auto">
      <div>
        <h1 class="text-2xl font-semibold text-slate-800 dark:text-slate-100">{{ title }}</h1>
        <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">{{ greeting }}</p>
      </div>
      <div v-if="user" class="flex items-center gap-4">
        <span class="font-medium text-slate-800 dark:text-slate-200">{{ user.name }}</span>
        <button
          @click="handleLogout"
          class="px-4 py-2 text-red-600 bg-slate-50 dark:bg-slate-800 border border-red-400 rounded hover:bg-red-600 hover:text-white transition-colors duration-200"
        >Logout</button>
      </div>
    </div>
  </header>
</template>

<script setup>
defineProps({
  title: { type: String, required: true },
  greeting: { type: String, required: true },
  user: { type: Object, default: null },
})

const emit = defineEmits(['logout'])
const handleLogout = () => emit('logout')
</script>
