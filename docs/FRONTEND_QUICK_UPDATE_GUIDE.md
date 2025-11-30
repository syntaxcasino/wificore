# 🚀 Frontend Quick Update Guide

## What's Been Done ✅

1. ✅ **Auth Store Updated** (`stores/auth.js`)
   - Unified authentication for all user types
   - Role-based routing
   - Token management
   - Password change functionality

2. ✅ **Tenant Registration Created** (`views/auth/TenantRegistrationView.vue`)
   - Beautiful registration form
   - Real-time availability checks
   - Form validation
   - Success/error handling

---

## What You Need to Do ⚠️

### Step 1: Update Router (5 minutes)

**File**: `frontend/src/router/index.js`

Add this route after the login route:

```javascript
{ 
  path: '/register', 
  name: 'register', 
  component: () => import('@/views/auth/TenantRegistrationView.vue')
},
```

Update the navigation guard (replace existing):

```javascript
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('authToken')
  const role = localStorage.getItem('userRole')
  const dashboardRoute = localStorage.getItem('dashboardRoute') || '/dashboard'
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)

  if (requiresAuth && !token) {
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (to.name === 'login' && token) {
    next({ path: dashboardRoute })
  } else {
    next()
  }
})
```

---

### Step 2: Update LoginView (10 minutes)

**File**: `frontend/src/views/auth/LoginView.vue`

Replace the `handleLogin` function:

```javascript
import { useAuthStore } from '@/stores/auth'

const authStore = useAuthStore()

const handleLogin = async () => {
  error.value = ''
  success.value = ''
  loading.value = true
  
  try {
    const result = await authStore.login({
      username: username.value,
      password: password.value,
      remember: false
    })
    
    if (result.success) {
      success.value = 'Login successful! Redirecting...'
      setTimeout(() => {
        router.push(result.dashboardRoute)
      }, 500)
    } else {
      error.value = result.error || 'Invalid credentials'
    }
  } catch (err) {
    error.value = 'An error occurred during login'
    console.error('Login error:', err)
  } finally {
    loading.value = false
  }
}
```

Add a link to registration (in the template):

```vue
<!-- After the login form -->
<div class="mt-6 text-center">
  <p class="text-gray-600 mb-2">Don't have an account?</p>
  <router-link 
    to="/register" 
    class="text-blue-600 hover:text-blue-800 font-medium transition-colors"
  >
    Register Your Organization
  </router-link>
</div>
```

---

### Step 3: Update Main.js (2 minutes)

**File**: `frontend/src/main.js`

Add auth initialization after creating the app:

```javascript
import { useAuthStore } from './stores/auth'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)

// Initialize auth from localStorage
const authStore = useAuthStore()
authStore.initializeAuth()

app.mount('#app')
```

---

### Step 4: Update Environment Variables (1 minute)

**File**: `frontend/.env`

Add or update:

```env
VITE_API_URL=http://localhost:8000/api
```

---

### Step 5: Test Everything (10 minutes)

```bash
# Start frontend
cd frontend
npm run dev

# Test Registration
1. Go to http://localhost:5173/register
2. Fill in the form
3. Check for validation errors
4. Submit and verify redirect to login

# Test Login
1. Go to http://localhost:5173/login
2. Login with: sysadmin / Admin@123!
3. Verify redirect to dashboard
4. Check localStorage for token

# Test Logout
1. Click logout (if available)
2. Verify redirect to login
3. Check localStorage cleared
```

---

## Optional Enhancements (Later)

### 1. Add Remember Me Checkbox to Login

```vue
<div class="flex items-center">
  <input 
    v-model="remember" 
    type="checkbox" 
    class="mr-2"
  />
  <label>Remember me</label>
</div>
```

### 2. Add Loading Spinner

```vue
<button :disabled="loading">
  <svg v-if="loading" class="animate-spin ...">...</svg>
  {{ loading ? 'Signing in...' : 'Sign In' }}
</button>
```

### 3. Add Toast Notifications

```bash
npm install vue-toastification
```

### 4. Add Form Validation

```bash
npm install vee-validate yup
```

---

## Troubleshooting

### Issue: "Cannot find module '@/stores/auth'"
**Solution**: Make sure the path alias is configured in `vite.config.js`:

```javascript
resolve: {
  alias: {
    '@': fileURLToPath(new URL('./src', import.meta.url))
  }
}
```

### Issue: "CORS error"
**Solution**: Update backend CORS config to allow frontend origin:

```php
// backend/config/cors.php
'allowed_origins' => ['http://localhost:5173'],
```

### Issue: "401 Unauthorized"
**Solution**: Check that token is being sent in headers:

```javascript
// In axios config
headers: { Authorization: `Bearer ${token}` }
```

### Issue: "Registration not working"
**Solution**: Check backend logs and verify API endpoint is correct

---

## Quick Commands

```bash
# Install dependencies
cd frontend
npm install

# Run development server
npm run dev

# Build for production
npm run build

# Run tests
npm run test:unit

# Lint and fix
npm run lint
```

---

## Summary

**Time Required**: ~30 minutes

**Steps**:
1. ✅ Auth store (already done)
2. ✅ Registration view (already done)
3. ⚠️ Update router (5 min)
4. ⚠️ Update LoginView (10 min)
5. ⚠️ Update main.js (2 min)
6. ⚠️ Update .env (1 min)
7. ⚠️ Test (10 min)

**Result**: Fully functional authentication system with tenant registration!

---

**Status**: 🟢 **READY TO IMPLEMENT**  
**Difficulty**: ⭐⭐ (Easy)  
**Time**: 30 minutes
