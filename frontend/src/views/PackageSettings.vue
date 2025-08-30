<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Package Settings</h2>
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-2">Add New Package</h3>
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <div>
          <label class="block text-gray-700">Package Name</label>
          <input v-model="newPackage.name" type="text" class="w-full p-2 border rounded" required />
        </div>
        <div>
          <label class="block text-gray-700">Price ($)</label>
          <input
            v-model.number="newPackage.price"
            type="number"
            step="0.01"
            class="w-full p-2 border rounded"
            required
          />
        </div>
        <div>
          <label class="block text-gray-700">Data Limit (GB)</label>
          <input
            v-model.number="newPackage.dataLimit"
            type="number"
            step="1"
            class="w-full p-2 border rounded"
            required
          />
        </div>
        <button type="submit" class="bg-blue-500 text-white p-2 rounded hover:bg-blue-600">
          Add Package
        </button>
      </form>
    </div>
    <div>
      <h3 class="text-lg font-semibold mb-2">Existing Packages</h3>
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-200">
            <th class="p-2 border">Name</th>
            <th class="p-2 border">Price ($)</th>
            <th class="p-2 border">Data Limit (GB)</th>
            <th class="p-2 border">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="pkg in packages" :key="pkg.id" class="border">
            <td class="p-2">{{ pkg.name }}</td>
            <td class="p-2">${{ pkg.price.toFixed(2) }}</td>
            <td class="p-2">{{ pkg.dataLimit }}</td>
            <td class="p-2">
              <button @click="editPackage(pkg)" class="text-blue-500 hover:underline mr-2">
                Edit
              </button>
              <button @click="deletePackage(pkg.id)" class="text-red-500 hover:underline">
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="packages.length === 0" class="mt-4 text-gray-500">No packages available.</p>
      <p v-if="error" class="mt-4 text-red-500">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { usePackages } from '@/composables/usePackages'

const { packages, loading, error, fetchPackages, addPackage, editPackage, deletePackage } =
  usePackages()
const newPackage = ref({ id: Date.now(), name: '', price: 0, dataLimit: 0 })

onMounted(() => {
  fetchPackages()
})

const handleSubmit = async () => {
  if (newPackage.value.name && newPackage.value.price >= 0 && newPackage.value.dataLimit >= 0) {
    await addPackage({ ...newPackage.value, id: Date.now() }) // Adjust id generation if backend handles it
    newPackage.value = { id: Date.now(), name: '', price: 0, dataLimit: 0 }
  }
}
</script>

<style scoped>
/* Add custom styles if needed */
</style>
