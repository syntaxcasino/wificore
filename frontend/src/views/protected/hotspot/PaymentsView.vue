<template>
  <div class="protected-view">
    <Sidebar />

    <div class="main-content">
      <AppHeader @logout="handleLogout" />

      <div class="content">
        <h1>Payment Records</h1>

        <div class="payment-filters">
          <div class="filter-group">
            <label>Date Range:</label>
            <select v-model="dateRange">
              <option value="7">Last 7 Days</option>
              <option value="30">Last 30 Days</option>
              <option value="90">Last 90 Days</option>
              <option value="all">All Time</option>
            </select>
          </div>

          <div class="filter-group">
            <label>Status:</label>
            <select v-model="statusFilter">
              <option value="all">All</option>
              <option value="paid">Paid</option>
              <option value="pending">Pending</option>
              <option value="failed">Failed</option>
            </select>
          </div>
        </div>

        <div class="payment-list">
          <div class="payment-card" v-for="payment in filteredPayments" :key="payment.id">
            <div class="payment-info">
              <div class="client">{{ payment.client }}</div>
              <div class="amount">Ksh {{ payment.amount.toLocaleString() }}</div>
              <div class="date">{{ payment.date }}</div>
              <div class="status" :class="payment.status">{{ payment.status }}</div>
            </div>
            <div class="payment-actions">
              <button class="view-btn">View Details</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import AppHeader from '@/components/AppHeader.vue'
import { useAuth } from '@/composables/useAuth'

const { logout } = useAuth()
const dateRange = ref('30')
const statusFilter = ref('all')

// Mock data - replace with API call
const payments = ref([
  { id: 1, client: 'John Doe', amount: 5000, date: '15 Aug 2023', status: 'paid' },
  { id: 2, client: 'Jane Smith', amount: 7500, date: '14 Aug 2023', status: 'pending' },
  { id: 3, client: 'Acme Corp', amount: 12000, date: '10 Aug 2023', status: 'paid' },
])

const filteredPayments = computed(() => {
  return payments.value.filter((payment) => {
    return statusFilter.value === 'all' || payment.status === statusFilter.value
  })
})

const handleLogout = () => {
  logout()
}
</script>

<style scoped>
.payment-filters {
  display: flex;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.filter-group {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.filter-group label {
  font-weight: 500;
}

select {
  padding: 0.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.payment-list {
  display: grid;
  gap: 1rem;
}

.payment-card {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  padding: 1rem;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.payment-info {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 1rem;
  flex: 1;
}

.client {
  font-weight: 500;
}

.amount {
  text-align: right;
}

.status {
  text-transform: capitalize;
  font-weight: 500;
}

.status.paid {
  color: #5cb85c;
}

.status.pending {
  color: #f0ad4e;
}

.status.failed {
  color: #d9534f;
}

.view-btn {
  padding: 0.5rem 1rem;
  background-color: #f5f7fa;
  border: 1px solid #ddd;
  border-radius: 4px;
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
