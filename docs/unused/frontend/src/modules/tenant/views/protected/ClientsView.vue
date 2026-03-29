<template>
  <div class="protected-view">
    <Sidebar />

    <div class="main-content">
      <AppHeader @logout="handleLogout" />

      <div class="content">
        <div class="page-header">
          <h1>Client Management</h1>
          <button class="add-button" @click="showAddClient = true">+ Add Client</button>
        </div>

        <div class="client-table">
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="client in clients" :key="client.id">
                <td>{{ client.id }}</td>
                <td>{{ client.name }}</td>
                <td>{{ client.email }}</td>
                <td>{{ client.phone }}</td>
                <td class="actions">
                  <button class="edit-btn">Edit</button>
                  <button class="delete-btn">Delete</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Sidebar from '@/modules/common/components/layout/AppSidebar.vue'
import AppHeader from '@/modules/common/components/AppHeader.vue'
import { useAuth } from '@/modules/common/composables/auth/useAuth'

const { logout } = useAuth()
const showAddClient = ref(false)

// Mock data - replace with API call
const clients = ref([
  { id: 1, name: 'John Doe', email: 'john@example.com', phone: '254712345678' },
  { id: 2, name: 'Jane Smith', email: 'jane@example.com', phone: '254712345679' },
  { id: 3, name: 'Acme Corp', email: 'acme@example.com', phone: '254712345680' },
])

const handleLogout = () => {
  logout()
}
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.add-button {
  padding: 0.5rem 1rem;
  background-color: #4a90e2;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.client-table {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  overflow: hidden;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 1rem;
  text-align: left;
  border-bottom: 1px solid #eee;
}

th {
  background-color: #f5f7fa;
  font-weight: 500;
}

.actions {
  display: flex;
  gap: 0.5rem;
}

.edit-btn {
  padding: 0.25rem 0.5rem;
  background-color: #f0ad4e;
  color: white;
  border: none;
  border-radius: 3px;
  cursor: pointer;
}

.delete-btn {
  padding: 0.25rem 0.5rem;
  background-color: #d9534f;
  color: white;
  border: none;
  border-radius: 3px;
  cursor: pointer;
}

/* Shared protected view styles */
.protected-view {
  display: flex;
  min-height: 100vh;
}

.main-content {
  flex: 1;
  margin-left: 250px;
}

.content {
  padding: 20px;
}
</style>
