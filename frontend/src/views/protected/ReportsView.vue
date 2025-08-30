<template>
  <div class="protected-view">
    <Sidebar />

    <div class="main-content">
      <AppHeader @logout="handleLogout" />

      <div class="content">
        <h1>Reports</h1>

        <div class="report-types">
          <div class="report-card" @click="generateReport('financial')">
            <div class="report-icon">💰</div>
            <h3>Financial Report</h3>
            <p>Monthly revenue and expenses</p>
          </div>

          <div class="report-card" @click="generateReport('clients')">
            <div class="report-icon">👥</div>
            <h3>Client Report</h3>
            <p>Client acquisition and retention</p>
          </div>

          <div class="report-card" @click="generateReport('usage')">
            <div class="report-icon">📊</div>
            <h3>Usage Report</h3>
            <p>Service consumption metrics</p>
          </div>
        </div>

        <div class="report-preview" v-if="activeReport">
          <h2>{{ activeReport.title }}</h2>
          <div class="report-content">
            <!-- Report content would be generated here -->
            <p>This is a preview of the {{ activeReport.type }} report.</p>
            <p>Generated on: {{ new Date().toLocaleDateString() }}</p>
          </div>
          <div class="report-actions">
            <button class="download-btn">Download PDF</button>
            <button class="print-btn">Print Report</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import Sidebar from '@/components/Sidebar.vue'
import AppHeader from '@/components/AppHeader.vue'
import { useAuth } from '@/composables/useAuth'

const { logout } = useAuth()
const activeReport = ref(null)

const generateReport = (type) => {
  const reports = {
    financial: { type: 'financial', title: 'Financial Report' },
    clients: { type: 'clients', title: 'Client Report' },
    usage: { type: 'usage', title: 'Usage Report' },
  }
  activeReport.value = reports[type]
}

const handleLogout = () => {
  logout()
}
</script>

<style scoped>
.report-types {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
  gap: 1.5rem;
  margin-bottom: 2rem;
}

.report-card {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  padding: 1.5rem;
  text-align: center;
  cursor: pointer;
  transition: transform 0.2s;
}

.report-card:hover {
  transform: translateY(-5px);
}

.report-icon {
  font-size: 2rem;
  margin-bottom: 1rem;
}

.report-card h3 {
  margin: 0 0 0.5rem 0;
}

.report-card p {
  margin: 0;
  color: #666;
  font-size: 0.9rem;
}

.report-preview {
  background: white;
  border-radius: 8px;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
  padding: 1.5rem;
  margin-top: 2rem;
}

.report-content {
  margin: 1.5rem 0;
  min-height: 200px;
  border: 1px dashed #ddd;
  padding: 1rem;
}

.report-actions {
  display: flex;
  gap: 1rem;
}

.download-btn,
.print-btn {
  padding: 0.75rem 1.5rem;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.download-btn {
  background-color: #4a90e2;
  color: white;
}

.print-btn {
  background-color: #f5f7fa;
  border: 1px solid #ddd;
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
