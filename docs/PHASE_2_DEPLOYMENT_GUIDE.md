# Phase 2: Sessions & Monitoring - Deployment Guide

**Date:** October 12, 2025  
**Status:** READY TO DEPLOY  
**Module:** Sessions & Monitoring

---

## ✅ Implementation Complete

### **What's Been Done:**

1. ✅ Created 3 new session monitoring views
2. ✅ Updated router configuration
3. ✅ Added "Online Users" to sidebar menu
4. ✅ All views follow consistent UI/UX pattern
5. ✅ Mock data in place for testing
6. ✅ Real-time auto-refresh implemented

---

## 🚀 Deployment Steps

### **Step 1: Rebuild Frontend Container**

```bash
# Navigate to project root
cd d:\traidnet\wifi-hotspot

# Stop frontend
docker-compose stop traidnet-frontend

# Rebuild with no cache
docker-compose build --no-cache traidnet-frontend

# Start frontend
docker-compose up -d traidnet-frontend

# Wait for container to be healthy (~30 seconds)
docker-compose ps
```

---

### **Step 2: Verify Routes**

Once the container is running, test these URLs:

#### **Hotspot Active Sessions:**
```
http://localhost/dashboard/hotspot/sessions
```
**Expected:**
- Title: "Active Sessions"
- Subtitle: "Monitor and manage active hotspot connections in real-time"
- Icon: Activity
- Search and filters bar
- Mock session data displayed
- Auto-refresh every 5 seconds

#### **PPPoE Sessions:**
```
http://localhost/dashboard/pppoe/sessions
```
**Expected:**
- Title: "PPPoE Sessions"
- Subtitle: "Monitor and manage active PPPoE connections in real-time"
- Icon: Network
- Search and filters bar
- Mock session data displayed
- Dual speed progress bars

#### **Online Users:**
```
http://localhost/dashboard/users/online
```
**Expected:**
- Title: "Online Users"
- Subtitle: "Monitor all currently connected users across Hotspot and PPPoE"
- Icon: Users
- Combined view of Hotspot + PPPoE users
- Type badges (Hotspot/PPPoE)
- Color-coded avatars

---

### **Step 3: Navigation Testing**

#### **From Sidebar:**

1. **Hotspot → Active Sessions**
   - Click "Hotspot" menu
   - Click "Active Sessions"
   - Should navigate to `/dashboard/hotspot/sessions`

2. **PPPoE → Active Sessions**
   - Click "PPPoE" menu
   - Click "Active Sessions"
   - Should navigate to `/dashboard/pppoe/sessions`

3. **Admin Users → Online Users**
   - Click "Admin Users" menu
   - Click "Online Users" (new menu item)
   - Should navigate to `/dashboard/users/online`

---

### **Step 4: Functional Testing**

#### **For Each View, Test:**

1. **Search Functionality**
   - Type in search box
   - Results should filter in real-time
   - Clear search should reset

2. **Filter Functionality**
   - Select filter options
   - Results should update
   - "Clear" button should appear
   - Clicking "Clear" should reset filters

3. **Pagination**
   - Navigate between pages
   - Page numbers should update
   - "Showing X to Y of Z" should be accurate

4. **View Details**
   - Click eye icon on any row
   - Modal should open
   - Should show comprehensive session info
   - Close button should work

5. **Disconnect**
   - Click power icon
   - Confirmation dialog should appear
   - Confirm should remove session from list

6. **Refresh**
   - Click "Refresh" button in header
   - Loading state should show
   - Data should update

7. **Auto-Refresh**
   - Wait 5 seconds
   - Data should automatically refresh
   - No page reload

---

### **Step 5: Visual Testing**

#### **Check These Elements:**

1. **Colors & Gradients**
   - Hotspot: Blue/Cyan avatars
   - PPPoE: Purple/Indigo avatars
   - Online Users: Mixed colors based on type

2. **Icons**
   - Activity icon (Hotspot Sessions)
   - Network icon (PPPoE Sessions)
   - Users icon (Online Users)
   - Wifi icon (Hotspot type badge)
   - All icons should display correctly

3. **Progress Bars**
   - Hotspot: Single bandwidth bar
   - PPPoE: Dual speed bars (download/upload)
   - Should animate smoothly

4. **Badges**
   - Stats badges in filters bar
   - Type badges in Online Users
   - Should have correct colors and icons

5. **Layout**
   - Search box on left (flexible)
   - Filters in center (grouped)
   - Stats badges on right
   - Clean white background
   - Proper spacing

---

## 🐛 Troubleshooting

### **Issue: Routes Not Found (404)**

**Solution:**
```bash
# Check if router was updated correctly
cat frontend/src/router/index.js | grep "ActiveSessionsNew\|PPPoESessionsNew\|OnlineUsersNew"

# Should show:
# component: () => import('@/views/dashboard/hotspot/ActiveSessionsNew.vue')
# component: () => import('@/views/dashboard/pppoe/PPPoESessionsNew.vue')
# component: () => import('@/views/dashboard/users/OnlineUsersNew.vue')

# If not found, rebuild again
docker-compose build --no-cache traidnet-frontend
docker-compose up -d traidnet-frontend
```

---

### **Issue: Components Not Loading**

**Solution:**
```bash
# Check if files exist in container
docker exec traidnet-frontend ls -la src/views/dashboard/hotspot/ActiveSessionsNew.vue
docker exec traidnet-frontend ls -la src/views/dashboard/pppoe/PPPoESessionsNew.vue
docker exec traidnet-frontend ls -la src/views/dashboard/users/OnlineUsersNew.vue

# Check browser console for errors (F12)
# Look for import errors or missing components
```

---

### **Issue: Sidebar Menu Item Missing**

**Solution:**
```bash
# Check if AppSidebar was updated
cat frontend/src/components/layout/AppSidebar.vue | grep "Online Users"

# Should show the new menu item
# If not, rebuild frontend
```

---

### **Issue: Auto-Refresh Not Working**

**Check:**
- Browser console for errors
- Network tab for API calls (should see requests every 5 seconds)
- Component lifecycle hooks (onMounted, onUnmounted)

---

## 📊 Testing Checklist

### **Pre-Deployment:**
- [x] All 3 views created
- [x] Router updated
- [x] Sidebar updated
- [x] Mock data in place
- [x] Auto-refresh implemented

### **Post-Deployment:**
- [ ] Frontend container rebuilt
- [ ] All routes accessible
- [ ] Sidebar navigation works
- [ ] Search works in all views
- [ ] Filters work in all views
- [ ] Pagination works
- [ ] Modals open/close
- [ ] Disconnect confirms
- [ ] Auto-refresh active
- [ ] No console errors
- [ ] Visual elements correct

---

## 🔌 Next Steps: API Integration

### **After Testing Mock Data:**

1. **Create API Endpoints**
   ```php
   // backend/routes/api.php
   
   // Hotspot Sessions
   Route::get('/hotspot/sessions', [HotspotController::class, 'getSessions']);
   Route::post('/hotspot/sessions/{id}/disconnect', [HotspotController::class, 'disconnect']);
   Route::post('/hotspot/sessions/disconnect-all', [HotspotController::class, 'disconnectAll']);
   
   // PPPoE Sessions
   Route::get('/pppoe/sessions', [PPPoEController::class, 'getSessions']);
   Route::post('/pppoe/sessions/{id}/disconnect', [PPPoEController::class, 'disconnect']);
   Route::post('/pppoe/sessions/disconnect-all', [PPPoEController::class, 'disconnectAll']);
   
   // Online Users
   Route::get('/users/online', [UserController::class, 'getOnlineUsers']);
   Route::post('/users/{id}/disconnect', [UserController::class, 'disconnect']);
   Route::get('/users/online/export', [UserController::class, 'exportOnlineUsers']);
   ```

2. **Update Frontend Components**
   
   Replace mock data in each component:
   
   ```javascript
   // In ActiveSessionsNew.vue, PPPoESessionsNew.vue, OnlineUsersNew.vue
   
   const fetchSessions = async () => {
     loading.value = true
     error.value = null
     
     try {
       const response = await fetch('/api/hotspot/sessions') // or appropriate endpoint
       if (!response.ok) throw new Error('Failed to fetch sessions')
       sessions.value = await response.json()
     } catch (err) {
       error.value = 'Failed to load sessions. Please try again.'
       console.error('Error fetching sessions:', err)
     } finally {
       loading.value = false
     }
   }
   ```

3. **Add WebSocket for Real-Time Updates (Optional)**
   
   ```javascript
   // Use Laravel Echo for real-time updates
   import Echo from '@/plugins/echo'
   
   onMounted(() => {
     // Subscribe to session updates
     Echo.channel('sessions')
       .listen('SessionStarted', (e) => {
         sessions.value.push(e.session)
       })
       .listen('SessionEnded', (e) => {
         sessions.value = sessions.value.filter(s => s.id !== e.sessionId)
       })
       .listen('SessionUpdated', (e) => {
         const index = sessions.value.findIndex(s => s.id === e.session.id)
         if (index !== -1) {
           sessions.value[index] = e.session
         }
       })
   })
   
   onUnmounted(() => {
     Echo.leave('sessions')
   })
   ```

---

## 📝 Files Modified

### **Router:**
- ✅ `frontend/src/router/index.js`
  - Updated Hotspot sessions route
  - Updated PPPoE sessions route
  - Added Online Users route

### **Sidebar:**
- ✅ `frontend/src/components/layout/AppSidebar.vue`
  - Added "Online Users" menu item under Admin Users

### **New Components:**
- ✅ `frontend/src/views/dashboard/hotspot/ActiveSessionsNew.vue`
- ✅ `frontend/src/views/dashboard/pppoe/PPPoESessionsNew.vue`
- ✅ `frontend/src/views/dashboard/users/OnlineUsersNew.vue`

---

## 🎯 Success Criteria

### **Deployment is successful when:**

1. ✅ All 3 routes are accessible
2. ✅ Sidebar navigation works
3. ✅ Mock data displays correctly
4. ✅ Search and filters function
5. ✅ Pagination works
6. ✅ Modals open/close
7. ✅ Auto-refresh active (every 5 seconds)
8. ✅ No console errors
9. ✅ Visual elements match design
10. ✅ Responsive on different screen sizes

---

## 🚀 Ready to Deploy!

**Run these commands:**

```bash
# Rebuild frontend
docker-compose stop traidnet-frontend
docker-compose build --no-cache traidnet-frontend
docker-compose up -d traidnet-frontend

# Wait 30 seconds, then test
# Open: http://localhost/dashboard/hotspot/sessions
# Open: http://localhost/dashboard/pppoe/sessions
# Open: http://localhost/dashboard/users/online
```

---

**Status:** ✅ READY FOR DEPLOYMENT

**Estimated Time:** 5 minutes (rebuild + testing)

**Risk Level:** Low (mock data, no breaking changes)

Let me know when you're ready to deploy! 🚀
