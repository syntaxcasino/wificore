<template>
  <div class="app-layout">
    <!-- Main Content Area -->
    <main class="content">
      <h2>System Logs</h2>
      <table v-if="logs.length">
        <thead>
          <tr>
            <th>ID</th>
            <th>Action</th>
            <th>Details</th>
            <th>Created At</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="log in logs" :key="log.id">
            <td>{{ log.id }}</td>
            <td>{{ log.action }}</td>
            <td>
              <pre>{{ formatMessage(log.details) }}</pre>
            </td>
            <td>{{ new Date(log.created_at).toLocaleString() }}</td>
          </tr>
        </tbody>
      </table>
      <p v-else>No logs available.</p>
      <p v-if="error" class="error">{{ error }}</p>
      <div class="pagination">
        <button :disabled="!prevPageUrl" @click="fetchLogs(prevPageUrl)">Previous</button>
        <span>Page {{ currentPage }} of {{ lastPage }}</span>
        <button :disabled="!nextPageUrl" @click="fetchLogs(nextPageUrl)">Next</button>
      </div>
    </main>

    <!-- Sticky Footer -->
    <footer class="footer">
      © {{ new Date().getFullYear() }} System Logs Viewer. All rights reserved.
    </footer>
  </div>
</template>

<script>
import axios from 'axios'

export default {
  data() {
    return {
      logs: [],
      currentPage: 1,
      lastPage: 1,
      prevPageUrl: null,
      nextPageUrl: null,
      error: null,
    }
  },
  mounted() {
    this.fetchLogs()
  },
  methods: {
    async fetchLogs(url = '/api/logs') {
      try {
        this.error = null
        const response = await axios.get(url, {
          headers: {
            Accept: 'application/json',
          },
        })
        console.log('Logs API Response:', response.data) // Debug logging
        const { data, current_page, last_page, prev_page_url, next_page_url } = response.data
        this.logs = data || []
        this.currentPage = current_page || 1
        this.lastPage = last_page || 1
        this.prevPageUrl = prev_page_url
        this.nextPageUrl = next_page_url
      } catch (error) {
        console.error('Failed to fetch logs:', error.response?.data || error.message)
        this.error = error.response?.data?.message || 'Failed to load logs. Please try again.'
        this.logs = []
        this.currentPage = 1
        this.lastPage = 1
        this.prevPageUrl = null
        this.nextPageUrl = null
      }
    },
    formatMessage(message) {
      try {
        const parsed = JSON.parse(message)
        return JSON.stringify(parsed, null, 2)
      } catch {
        return message
      }
    },
  },
}
</script>

<style scoped>
/* Layout Styling */
.app-layout {
  display: flex;
  flex-direction: column;
  min-height: 100vh;
}

.content {
  flex: 1;
  padding: 1rem;
  overflow-y: auto;
}

.footer {
  background-color: #f2f2f2;
  text-align: center;
  padding: 1rem;
  font-size: 14px;
  color: #555;
}

/* Table Styling */
table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 20px;
}
th,
td {
  border: 1px solid #ddd;
  padding: 8px;
  text-align: left;
}
th {
  background-color: #f9f9f9;
}
pre {
  white-space: pre-wrap;
  margin: 0;
}

/* Pagination Styling */
.pagination {
  display: flex;
  align-items: center;
  gap: 10px;
}
button {
  padding: 5px 10px;
}
button:disabled {
  opacity: 0.5;
}

/* Error Styling */
.error {
  color: #e3342f;
  text-align: center;
  margin-top: 1rem;
}
</style>
