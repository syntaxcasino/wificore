<template>
  <form @submit.prevent="handleSubmit" class="space-y-6">
    <div>
      <label for="username" class="block mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">Username</label>
      <input
        id="username"
        v-model="form.username"
        type="text"
        required
        placeholder="Enter your username"
        class="w-full px-3 py-3 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
      />
    </div>

    <div>
      <label for="password" class="block mb-1.5 text-sm font-medium text-slate-700 dark:text-slate-300">Password</label>
      <input
        id="password"
        v-model="form.password"
        type="password"
        required
        placeholder="Enter your password"
        class="w-full px-3 py-3 border border-slate-300 dark:border-slate-600 rounded bg-white dark:bg-slate-700 text-slate-900 dark:text-slate-100 placeholder-slate-400 dark:placeholder-slate-500 text-base focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
      />
    </div>

    <button
      type="submit"
      class="w-full py-3 bg-blue-500 hover:bg-blue-600 text-white font-medium text-base rounded cursor-pointer transition-colors duration-200"
    >Login</button>
  </form>
</template>

<script setup>
import { reactive } from 'vue'

const emit = defineEmits(['submit'])
const form = reactive({ username: '', password: '' })
const handleSubmit = () => emit('submit', { username: form.username, password: form.password })
</script>
