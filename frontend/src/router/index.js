import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    component: () => import('@/views/PackagesView.vue'),
    meta: { title: 'WiFi Packages' },
  },
  {
    path: '/logs',
    component: () => import('@/views/LogsView.vue'),
    meta: { title: 'System Logs' },
  },
  {
    path: '/payment-success',
    component: () => import('@/views/PaymentSuccess.vue'),
    meta: { title: 'Payment Successful' },
  },
  {
    path: '/:pathMatch(.*)*',
    component: () => import('@/views/NotFound.vue'),
    meta: { title: 'Page Not Found' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior(to, from, savedPosition) {
    return savedPosition || { top: 0 }
  },
})

// Update document title
router.beforeEach((to) => {
  document.title = to.meta.title || 'WiFi Hotspot'
})

export default router
