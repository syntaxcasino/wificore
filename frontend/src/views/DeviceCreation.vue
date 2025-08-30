<template>
  <div>
    <h2 class="text-2xl font-bold mb-4">Device Creation</h2>
    <div class="mb-6">
      <h3 class="text-lg font-semibold mb-2">Add New Device</h3>
      <form @submit.prevent="handleSubmit" class="space-y-4">
        <div>
          <label class="block text-gray-700">Device Name</label>
          <input v-model="newDevice.name" type="text" class="w-full p-2 border rounded" required />
        </div>
        <div>
          <label class="block text-gray-700">MAC Address</label>
          <input
            v-model="newDevice.macAddress"
            type="text"
            placeholder="XX:XX:XX:XX:XX:XX"
            class="w-full p-2 border rounded"
            pattern="[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}"
            required
          />
        </div>
        <div>
          <label class="block text-gray-700">Status</label>
          <select v-model="newDevice.status" class="w-full p-2 border rounded" required>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
        <button type="submit" class="bg-blue-500 text-white p-2 rounded hover:bg-blue-600">
          Add Device
        </button>
      </form>
    </div>
    <div>
      <h3 class="text-lg font-semibold mb-2">Existing Devices</h3>
      <table class="w-full text-left border-collapse">
        <thead>
          <tr class="bg-gray-200">
            <th class="p-2 border">Name</th>
            <th class="p-2 border">MAC Address</th>
            <th class="p-2 border">Status</th>
            <th class="p-2 border">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="device in devices" :key="device.id" class="border">
            <td class="p-2">{{ device.name }}</td>
            <td class="p-2">{{ device.macAddress }}</td>
            <td
              class="p-2"
              :class="{
                'text-green-600': device.status === 'active',
                'text-red-600': device.status === 'inactive',
              }"
            >
              {{ device.status }}
            </td>
            <td class="p-2">
              <button @click="editDevice(device)" class="text-blue-500 hover:underline mr-2">
                Edit
              </button>
              <button @click="deleteDevice(device.id)" class="text-red-500 hover:underline">
                Delete
              </button>
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="devices.length === 0" class="mt-4 text-gray-500">No devices available.</p>
      <p v-if="error" class="mt-4 text-red-500">{{ error }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import axios from 'axios'

const devices = ref([])
const loading = ref(false)
const error = ref(null)
const newDevice = ref({ id: Date.now(), name: '', macAddress: '', status: 'active' })

const fetchDevices = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await axios.get('/api/devices')
    devices.value = response.data || []
  } catch (err) {
    console.error('Device fetch error:', err)
    error.value = err.response?.data?.message || 'Failed to load devices'
    devices.value = []
  } finally {
    loading.value = false
  }
}

const addDevice = async () => {
  try {
    loading.value = true
    error.value = null
    const response = await axios.post('/api/devices', newDevice.value)
    devices.value = [...devices.value, response.data]
    newDevice.value = { id: Date.now(), name: '', macAddress: '', status: 'active' }
  } catch (err) {
    console.error('Device add error:', err)
    error.value = err.response?.data?.message || 'Failed to add device'
  } finally {
    loading.value = false
  }
}

const editDevice = async (device) => {
  const updatedData = {
    name: prompt('New name:', device.name),
    macAddress: prompt('New MAC address:', device.macAddress),
    status: prompt('New status (active/inactive):', device.status),
  }
  if (
    updatedData.name &&
    updatedData.macAddress.match(
      /[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}/,
    ) &&
    ['active', 'inactive'].includes(updatedData.status)
  ) {
    try {
      loading.value = true
      error.value = null
      const response = await axios.put(`/api/devices/${device.id}`, updatedData)
      devices.value = devices.value.map((d) => (d.id === device.id ? response.data : d))
    } catch (err) {
      console.error('Device edit error:', err)
      error.value = err.response?.data?.message || 'Failed to edit device'
    } finally {
      loading.value = false
    }
  }
}

const deleteDevice = async (deviceId) => {
  try {
    loading.value = true
    error.value = null
    await axios.delete(`/api/devices/${deviceId}`)
    devices.value = devices.value.filter((d) => d.id !== deviceId)
  } catch (err) {
    console.error('Device delete error:', err)
    error.value = err.response?.data?.message || 'Failed to delete device'
  } finally {
    loading.value = false
  }
}

const handleSubmit = async () => {
  if (
    newDevice.value.name &&
    newDevice.value.macAddress.match(
      /[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}:[0-9A-Fa-f]{2}/,
    )
  ) {
    await addDevice()
  }
}

onMounted(() => {
  fetchDevices()
  // Mock data for initial load (remove after backend integration)
  if (devices.value.length === 0) {
    devices.value = [
      { id: 1, name: 'Device1', macAddress: '00:1A:2B:3C:4D:5E', status: 'active' },
      { id: 2, name: 'Device2', macAddress: '00:1A:2B:3C:4D:5F', status: 'inactive' },
    ]
  }
})
</script>

<style scoped>
/* Add custom styles if needed */
</style>
