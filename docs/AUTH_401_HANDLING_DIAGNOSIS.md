# 401 Unauthorized Error Handling - Diagnosis & Fix

**Date:** October 9, 2025  
**Issue:** Users getting 401 errors but not automatically redirected to login  
**Status:** 🔴 CRITICAL - Auto-logout not implemented

---

## Executive Summary

When users receive **401 Unauthorized** errors (expired/invalid tokens), the system **does NOT**:
- ❌ Clear the expired token from localStorage
- ❌ Clear authentication state
- ❌ Redirect to login page
- ❌ Show user-friendly error message

**Result:** Users see error messages in console but stay on the page with broken functionality.

---

## Error Analysis

### Frontend Console Errors
```
GET http://localhost/api/routers 401 (Unauthorized)
API Error: AxiosError {message: 'Request failed with status code 401', ...}
POST http://localhost/api/broadcasting/auth 401 (Unauthorized)
```

### What's Happening
1. User has expired/invalid token in localStorage ❌
2. Router guard checks `localStorage.getItem('authToken')` ✅ (token exists)
3. User allowed to access protected routes ❌
4. API requests sent with invalid token ❌
5. Backend returns 401 Unauthorized ✅
6. Axios interceptor logs error but does nothing ❌
7. User stuck on page with broken functionality ❌

---

## Complete Stack Analysis

### 1. ❌ Axios Response Interceptor (INCOMPLETE)

**File:** `frontend/src/main.js` (Lines 35-50)

**Current Implementation:**
```javascript
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    console.error('API Error:', error)
    
    // If 401 on a public endpoint, clear any stale token and retry
    const isPublicEndpoint = publicEndpoints.some(endpoint => error.config?.url?.includes(endpoint))
    if (error.response?.status === 401 && isPublicEndpoint) {
      console.warn('Received 401 on public endpoint, clearing stale token')
      delete axios.defaults.headers.common['Authorization']
    }
    
    return Promise.reject(error)
  },
)
```

**Problems:**
1. ❌ Only handles 401 on **public endpoints**
2. ❌ Does NOT handle 401 on **protected endpoints** (like `/api/routers`)
3. ❌ Does NOT clear localStorage
4. ❌ Does NOT redirect to login
5. ❌ Does NOT update auth state

---

### 2. ✅ Router Navigation Guard (WORKING BUT LIMITED)

**File:** `frontend/src/router/index.js` (Lines 296-309)

**Current Implementation:**
```javascript
router.beforeEach((to, from, next) => {
  const token = localStorage.getItem('authToken')
  const requiresAuth = to.matched.some(record => record.meta.requiresAuth)

  if (requiresAuth && !token) {
    // Redirect to login if trying to access protected route without token
    next({ name: 'login', query: { redirect: to.fullPath } })
  } else if (to.name === 'login' && token) {
    // Redirect to dashboard if already logged in and trying to access login page
    next({ name: 'overview' })
  } else {
    next()
  }
})
```

**What It Does:**
- ✅ Checks if token exists in localStorage
- ✅ Redirects to login if no token

**What It DOESN'T Do:**
- ❌ Doesn't validate if token is **valid**
- ❌ Doesn't check if token is **expired**
- ❌ Only runs on **navigation**, not on API errors

**Result:** User with expired token can stay on protected pages until they navigate.

---

### 3. ✅ Auth Composable (WORKING)

**File:** `frontend/src/composables/auth/useAuth.js`

**Logout Function (Lines 151-174):**
```javascript
const logout = async () => {
  try {
    // Call backend to revoke token
    await axios.post('logout')
  } catch (error) {
    console.error('Logout error:', error)
  } finally {
    // Clear local storage and state
    localStorage.removeItem('authToken')
    localStorage.removeItem('user')
    delete axios.defaults.headers.common['Authorization']
    
    user.value = null
    token.value = null
    isAuthenticated.value = false
    
    // Disconnect Echo
    if (window.Echo) {
      window.Echo.disconnect()
    }
    
    router.push('/login')
  }
}
```

**✅ This function does everything correctly!**
- Clears localStorage
- Clears auth state
- Disconnects WebSocket
- Redirects to login

**Problem:** It's never called automatically on 401 errors!

---

### 4. ✅ Backend Token Validation (WORKING)

**File:** `backend/config/sanctum.php`

**Token Expiration:**
```php
'expiration' => null,  // Tokens don't expire by time
```

**Sanctum Middleware:**
- ✅ Validates token on each request
- ✅ Returns 401 if token invalid/revoked
- ✅ Returns 401 if user not found

**Backend is working correctly** - it properly rejects invalid tokens.

---

## Root Cause

**The axios response interceptor only handles 401 errors on PUBLIC endpoints, not PROTECTED endpoints.**

When a 401 error occurs on a protected endpoint (like `/api/routers`):
1. Error is logged to console ✅
2. Error is rejected and propagates ✅
3. **No cleanup happens** ❌
4. **No redirect happens** ❌
5. **User stays on broken page** ❌

---

## Solution

### Fix: Enhanced Axios Response Interceptor

**File:** `frontend/src/main.js`

**Add proper 401 handling for ALL endpoints:**

```javascript
// Response interceptor
axios.interceptors.response.use(
  (response) => response,
  (error) => {
    console.error('API Error:', error)
    
    // Handle 401 Unauthorized errors
    if (error.response?.status === 401) {
      const isPublicEndpoint = publicEndpoints.some(endpoint => 
        error.config?.url?.includes(endpoint)
      )
      
      if (isPublicEndpoint) {
        // Public endpoint - just clear stale token
        console.warn('Received 401 on public endpoint, clearing stale token')
        delete axios.defaults.headers.common['Authorization']
      } else {
        // Protected endpoint - token is invalid/expired
        console.warn('Authentication failed - token expired or invalid')
        
        // Clear all auth data
        localStorage.removeItem('authToken')
        localStorage.removeItem('user')
        delete axios.defaults.headers.common['Authorization']
        
        // Disconnect WebSocket
        if (window.Echo) {
          window.Echo.disconnect()
        }
        
        // Redirect to login with return URL
        const currentPath = window.location.pathname
        window.location.href = `/login?redirect=${encodeURIComponent(currentPath)}`
        
        // Prevent error propagation after redirect
        return Promise.reject(error)
      }
    }
    
    return Promise.reject(error)
  },
)
```

---

## Alternative Solution (Using Router)

If we want to use Vue Router instead of `window.location.href`:

```javascript
import router from './router'

// Response interceptor
axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    console.error('API Error:', error)
    
    // Handle 401 Unauthorized errors
    if (error.response?.status === 401) {
      const isPublicEndpoint = publicEndpoints.some(endpoint => 
        error.config?.url?.includes(endpoint)
      )
      
      if (!isPublicEndpoint) {
        // Protected endpoint - token is invalid/expired
        console.warn('Authentication failed - clearing session and redirecting to login')
        
        // Clear all auth data
        localStorage.removeItem('authToken')
        localStorage.removeItem('user')
        delete axios.defaults.headers.common['Authorization']
        
        // Disconnect WebSocket
        if (window.Echo) {
          window.Echo.disconnect()
        }
        
        // Redirect to login (preserve current path for redirect after login)
        await router.push({
          name: 'login',
          query: { redirect: router.currentRoute.value.fullPath }
        })
      }
    }
    
    return Promise.reject(error)
  },
)
```

---

## Implementation Steps

### Step 1: Update Axios Interceptor
**File:** `frontend/src/main.js` (Lines 35-50)

Replace the current response interceptor with the enhanced version.

### Step 2: Test Scenarios

1. **Expired Token Test:**
   - Manually edit token in localStorage to invalid value
   - Navigate to `/dashboard/routers`
   - Should auto-redirect to `/login?redirect=/dashboard/routers`

2. **Deleted User Test:**
   - Have backend admin delete a user
   - User tries to access protected route
   - Should auto-redirect to login

3. **Revoked Token Test:**
   - Logout from another device/session
   - Try to use app on first device
   - Should auto-redirect to login

4. **Public Endpoint Test:**
   - Access `/` (public packages page)
   - Should work without redirect

---

## Additional Improvements

### 1. User-Friendly Error Message

Add a toast/notification before redirect:

```javascript
// Show user-friendly message
if (window.showToast) {
  window.showToast('Your session has expired. Please login again.', 'warning')
}

// Or use a simple alert as fallback
alert('Your session has expired. Please login again.')
```

### 2. Prevent Multiple Redirects

Add a flag to prevent multiple simultaneous redirects:

```javascript
let isRedirecting = false

axios.interceptors.response.use(
  (response) => response,
  async (error) => {
    if (error.response?.status === 401 && !isRedirecting) {
      const isPublicEndpoint = publicEndpoints.some(...)
      
      if (!isPublicEndpoint) {
        isRedirecting = true
        
        // Clear auth and redirect
        // ...
        
        setTimeout(() => { isRedirecting = false }, 1000)
      }
    }
    
    return Promise.reject(error)
  },
)
```

### 3. Preserve Redirect Path

The current router guard already supports this:

```javascript
if (requiresAuth && !token) {
  next({ name: 'login', query: { redirect: to.fullPath } })
}
```

After login, redirect user back to where they were:

```javascript
// In login component after successful login
const redirect = route.query.redirect || '/dashboard'
router.push(redirect)
```

---

## Testing Checklist

- [ ] 401 on protected endpoint clears token
- [ ] 401 on protected endpoint redirects to login
- [ ] Redirect URL preserved in query param
- [ ] WebSocket disconnected on 401
- [ ] localStorage cleared on 401
- [ ] After login, user redirected to original page
- [ ] Public endpoints still work
- [ ] No infinite redirect loops
- [ ] Multiple simultaneous 401s handled gracefully

---

## Files Requiring Changes

1. **`frontend/src/main.js`** - Enhanced axios response interceptor

That's it! One file change fixes the entire issue.

---

## Impact

### Before Fix
- ❌ 401 errors logged but ignored
- ❌ Users stuck on broken pages
- ❌ Invalid tokens persist in localStorage
- ❌ WebSocket connections fail silently
- ❌ Poor user experience

### After Fix
- ✅ 401 errors trigger automatic logout
- ✅ Users redirected to login immediately
- ✅ All auth data cleared properly
- ✅ WebSocket disconnected cleanly
- ✅ Smooth, professional UX

---

## Conclusion

The authentication system is **well-implemented** but missing **one critical piece**: automatic handling of 401 errors on protected endpoints.

**Root Cause:** Axios response interceptor only handles 401 on public endpoints.

**Solution:** Add 401 handling for protected endpoints that:
1. Clears localStorage
2. Disconnects WebSocket
3. Redirects to login
4. Preserves redirect path

**Impact:** One function enhancement in `main.js` provides complete session management.
