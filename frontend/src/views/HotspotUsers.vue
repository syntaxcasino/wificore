<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Hotspot Users</h2>
    <div class="mb-4">
      <select v-model="filterStatus" class="p-2 border rounded">
        <option value="">All</option>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </select>
    </div>
    <table class="w-full text-left border-collapse">
      <thead>
        <tr class="bg-gray-200">
          <th class="p-2 border">Name</th>
          <th class="p-2 border">Status</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="user in filteredUsers" :key="user.id" class="border">
          <td class="p-2">{{ user.name }}</td>
          <td class="p-2">{{ user.status }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'

const filterStatus = ref('')
const users = ref([
  { id: 1, name: 'User1', status: 'active' },
  { id: 2, name: 'User2', status: 'inactive' },
  { id: 3, name: 'User3', status: 'active' },
])

const filteredUsers = computed(() => {
  if (!filterStatus.value) return users.value
  return users.value.filter((user) => user.status === filterStatus.value)
})
</script>
