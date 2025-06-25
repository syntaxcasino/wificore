import { createRouter, createWebHistory } from 'vue-router'
import PackageSelector from '../components/PackageSelector.vue'
import LogsView from '../views/LogsView.vue'

const routes = [
  { path: '/', component: PackageSelector },
  { path: '/logs', component: LogsView },
  { path: '/payment-success', component: () => import('../views/PaymentSuccess.vue') },
  // Add other routes as needed, e.g., { path: '/payment-success', component: PaymentSuccess }
]

const router = createRouter({
  history: createWebHistory(),
  routes,
})

export default router
