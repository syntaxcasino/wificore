<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-100">
    <div class="bg-white p-8 rounded-lg shadow-lg w-full max-w-md">
      <h2 class="text-2xl font-bold mb-6 text-center">Login</h2>
      <div v-if="error" class="text-red-500 mb-4">{{ error }}</div>
      <form @submit.prevent="handleLogin">
        <div class="mb-4">
          <label class="block text-gray-700">Username</label>
          <input v-model="username" type="text" class="w-full p-2 border rounded" />
        </div>
        <div class="mb-6">
          <label class="block text-gray-700">Password</label>
          <input v-model="password" type="password" class="w-full p-2 border rounded" />
        </div>
        <button type="submit" class="w-full bg-blue-500 text-white p-2 rounded hover:bg-blue-600">
          Login
        </button>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useAuth } from '@/composables/useAuth'

const username = ref('')
const password = ref('')
const error = ref('')
const { login } = useAuth()

const handleLogin = () => {
  if (!login(username.value, password.value)) {
    error.value = 'Invalid credentials'
  }
}
</script>
