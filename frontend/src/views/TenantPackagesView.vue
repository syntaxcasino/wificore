<template>
  <div class="tenant-packages-page" :style="brandingStyles">
    <!-- Loading State -->
    <div v-if="loading" class="loading-container">
      <div class="spinner"></div>
      <p>Loading packages...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="error-container">
      <svg class="error-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h2>{{ error }}</h2>
      <p>Please check the URL and try again.</p>
    </div>

    <!-- Packages Display -->
    <div v-else class="packages-container">
      <!-- Header with Tenant Branding -->
      <header class="tenant-header">
        <div class="header-content">
          <img 
            v-if="tenant?.branding?.logo_url" 
            :src="tenant.branding.logo_url" 
            :alt="tenant.branding.company_name"
            class="tenant-logo"
          />
          <div v-else class="tenant-logo-placeholder">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0" />
            </svg>
          </div>
          <div class="header-text">
            <h1>{{ tenant?.branding?.company_name || tenant?.name }}</h1>
            <p v-if="tenant?.branding?.tagline" class="tagline">{{ tenant.branding.tagline }}</p>
          </div>
        </div>
      </header>

      <!-- Packages Grid -->
      <main class="packages-main">
        <div class="packages-intro">
          <h2>Choose Your Internet Package</h2>
          <p>Select the perfect plan for your needs</p>
        </div>

        <div v-if="packages.length === 0" class="no-packages">
          <svg class="no-packages-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
          </svg>
          <h3>No Packages Available</h3>
          <p>Please check back later for available packages.</p>
        </div>

        <div v-else class="packages-grid">
          <div 
            v-for="pkg in packages" 
            :key="pkg.id"
            class="package-card"
          >
            <div class="package-header">
              <h3>{{ pkg.name }}</h3>
              <div class="package-price">
                <span class="currency">KES</span>
                <span class="amount">{{ formatPrice(pkg.price) }}</span>
              </div>
            </div>

            <div class="package-body">
              <p class="package-description">{{ pkg.description }}</p>
              
              <ul class="package-features">
                <li v-if="pkg.duration_hours">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span>{{ formatDuration(pkg.duration_hours) }}</span>
                </li>
                <li v-if="pkg.data_limit_bytes">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                  </svg>
                  <span>{{ formatDataLimit(pkg.data_limit_bytes) }}</span>
                </li>
                <li v-if="pkg.speed_limit_mbps">
                  <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  <span>Up to {{ pkg.speed_limit_mbps }} Mbps</span>
                </li>
                <li v-if="pkg.features && pkg.features.length">
                  <template v-for="(feature, index) in pkg.features" :key="index">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    <span>{{ feature }}</span>
                  </template>
                </li>
              </ul>
            </div>

            <div class="package-footer">
              <button 
                class="buy-button"
                @click="selectPackage(pkg)"
              >
                Buy Now
              </button>
            </div>
          </div>
        </div>
      </main>

      <!-- Footer with Support Info -->
      <footer class="tenant-footer">
        <div class="footer-content">
          <h3>Need Help?</h3>
          <div class="contact-info">
            <div v-if="tenant?.branding?.support_email" class="contact-item">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
              </svg>
              <a :href="`mailto:${tenant.branding.support_email}`">{{ tenant.branding.support_email }}</a>
            </div>
            <div v-if="tenant?.branding?.support_phone" class="contact-item">
              <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
              </svg>
              <a :href="`tel:${tenant.branding.support_phone}`">{{ tenant.branding.support_phone }}</a>
            </div>
          </div>
        </div>
      </footer>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()

const loading = ref(true)
const error = ref(null)
const tenant = ref(null)
const packages = ref([])

// Extract subdomain from URL
const getSubdomain = () => {
  const hostname = window.location.hostname
  const parts = hostname.split('.')
  
  // For localhost development, use a default or from route params
  if (hostname === 'localhost' || hostname === '127.0.0.1') {
    return router.currentRoute.value.params.subdomain || 'demo'
  }
  
  // For production, extract first part of domain
  if (parts.length >= 3) {
    return parts[0]
  }
  
  return null
}

// Computed branding styles
const brandingStyles = computed(() => {
  if (!tenant.value?.branding) return {}
  
  return {
    '--primary-color': tenant.value.branding.primary_color || '#3b82f6',
    '--secondary-color': tenant.value.branding.secondary_color || '#10b981',
  }
})

// Format price
const formatPrice = (price) => {
  return new Intl.NumberFormat('en-KE', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(price)
}

// Format duration
const formatDuration = (hours) => {
  if (hours < 24) {
    return `${hours} Hour${hours > 1 ? 's' : ''}`
  }
  const days = Math.floor(hours / 24)
  return `${days} Day${days > 1 ? 's' : ''}`
}

// Format data limit
const formatDataLimit = (bytes) => {
  const gb = bytes / (1024 * 1024 * 1024)
  if (gb >= 1) {
    return `${gb.toFixed(0)} GB`
  }
  const mb = bytes / (1024 * 1024)
  return `${mb.toFixed(0)} MB`
}

// Select package
const selectPackage = (pkg) => {
  // Store selected package and redirect to purchase/login
  localStorage.setItem('selectedPackage', JSON.stringify(pkg))
  localStorage.setItem('tenantId', tenant.value.id)
  
  // Redirect to login or purchase page
  router.push({
    name: 'login',
    query: { package: pkg.id, tenant: tenant.value.id }
  })
}

// Load tenant and packages
const loadTenantPackages = async () => {
  try {
    loading.value = true
    error.value = null
    
    const subdomain = getSubdomain()
    
    if (!subdomain) {
      throw new Error('Unable to identify tenant from URL')
    }
    
    // Fetch tenant and packages
    const response = await axios.get(`/public/tenant/${subdomain}/packages`)
    
    if (response.data.success) {
      tenant.value = response.data.data.tenant
      packages.value = response.data.data.packages
    } else {
      throw new Error(response.data.message || 'Failed to load packages')
    }
  } catch (err) {
    console.error('Error loading tenant packages:', err)
    error.value = err.response?.data?.message || err.message || 'Failed to load packages'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadTenantPackages()
})
</script>

<style scoped>
.tenant-packages-page {
  min-height: 100vh;
  background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
}

.loading-container,
.error-container {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 100vh;
  padding: 2rem;
  text-align: center;
}

.spinner {
  width: 3rem;
  height: 3rem;
  border: 4px solid rgba(0, 0, 0, 0.1);
  border-left-color: var(--primary-color, #3b82f6);
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

.error-icon {
  width: 4rem;
  height: 4rem;
  color: #ef4444;
  margin-bottom: 1rem;
}

.packages-container {
  max-width: 1400px;
  margin: 0 auto;
}

.tenant-header {
  background: linear-gradient(135deg, var(--primary-color, #3b82f6) 0%, var(--secondary-color, #10b981) 100%);
  color: white;
  padding: 3rem 2rem;
  text-align: center;
}

.header-content {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1.5rem;
}

.tenant-logo {
  width: 120px;
  height: 120px;
  object-fit: contain;
  background: white;
  border-radius: 1rem;
  padding: 1rem;
  box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
}

.tenant-logo-placeholder {
  width: 120px;
  height: 120px;
  background: rgba(255, 255, 255, 0.2);
  border-radius: 1rem;
  display: flex;
  align-items: center;
  justify-content: center;
}

.tenant-logo-placeholder svg {
  width: 60px;
  height: 60px;
}

.header-text h1 {
  font-size: 2.5rem;
  font-weight: 700;
  margin: 0;
}

.tagline {
  font-size: 1.25rem;
  opacity: 0.9;
  margin: 0.5rem 0 0;
}

.packages-main {
  padding: 3rem 2rem;
}

.packages-intro {
  text-align: center;
  margin-bottom: 3rem;
}

.packages-intro h2 {
  font-size: 2rem;
  font-weight: 700;
  color: #1f2937;
  margin-bottom: 0.5rem;
}

.packages-intro p {
  font-size: 1.125rem;
  color: #6b7280;
}

.no-packages {
  text-align: center;
  padding: 4rem 2rem;
}

.no-packages-icon {
  width: 5rem;
  height: 5rem;
  color: #9ca3af;
  margin: 0 auto 1.5rem;
}

.packages-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
  gap: 2rem;
}

.package-card {
  background: white;
  border-radius: 1rem;
  box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
  overflow: hidden;
  transition: transform 0.3s, box-shadow 0.3s;
  display: flex;
  flex-direction: column;
}

.package-card:hover {
  transform: translateY(-8px);
  box-shadow: 0 20px 25px rgba(0, 0, 0, 0.15);
}

.package-header {
  background: linear-gradient(135deg, var(--primary-color, #3b82f6) 0%, var(--secondary-color, #10b981) 100%);
  color: white;
  padding: 2rem;
  text-align: center;
}

.package-header h3 {
  font-size: 1.5rem;
  font-weight: 700;
  margin: 0 0 1rem;
}

.package-price {
  display: flex;
  align-items: baseline;
  justify-content: center;
  gap: 0.5rem;
}

.currency {
  font-size: 1.25rem;
  font-weight: 600;
}

.amount {
  font-size: 3rem;
  font-weight: 700;
}

.package-body {
  padding: 2rem;
  flex: 1;
}

.package-description {
  color: #6b7280;
  margin-bottom: 1.5rem;
  line-height: 1.6;
}

.package-features {
  list-style: none;
  padding: 0;
  margin: 0;
}

.package-features li {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid #f3f4f6;
}

.package-features li:last-child {
  border-bottom: none;
}

.package-features svg {
  width: 1.25rem;
  height: 1.25rem;
  color: var(--secondary-color, #10b981);
  flex-shrink: 0;
}

.package-footer {
  padding: 2rem;
  border-top: 1px solid #f3f4f6;
}

.buy-button {
  width: 100%;
  padding: 1rem;
  background: linear-gradient(135deg, var(--primary-color, #3b82f6) 0%, var(--secondary-color, #10b981) 100%);
  color: white;
  border: none;
  border-radius: 0.5rem;
  font-size: 1.125rem;
  font-weight: 600;
  cursor: pointer;
  transition: transform 0.2s, box-shadow 0.2s;
}

.buy-button:hover {
  transform: scale(1.05);
  box-shadow: 0 10px 20px rgba(0, 0, 0, 0.2);
}

.tenant-footer {
  background: #1f2937;
  color: white;
  padding: 3rem 2rem;
  text-align: center;
}

.footer-content h3 {
  font-size: 1.5rem;
  margin-bottom: 1.5rem;
}

.contact-info {
  display: flex;
  flex-direction: column;
  gap: 1rem;
  align-items: center;
}

.contact-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.contact-item svg {
  width: 1.25rem;
  height: 1.25rem;
}

.contact-item a {
  color: white;
  text-decoration: none;
  transition: color 0.2s;
}

.contact-item a:hover {
  color: var(--secondary-color, #10b981);
}

@media (max-width: 768px) {
  .header-text h1 {
    font-size: 2rem;
  }
  
  .packages-grid {
    grid-template-columns: 1fr;
  }
  
  .amount {
    font-size: 2.5rem;
  }
}
</style>
