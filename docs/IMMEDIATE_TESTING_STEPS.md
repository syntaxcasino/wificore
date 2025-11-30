# Immediate Testing Steps - User Management Restructure

**Status:** Ready for Testing  
**Date:** October 12, 2025

---

## 🚀 Quick Start (5 Minutes)

### Step 1: Start Development Server
```bash
cd frontend
npm run dev
```

Wait for: `Local: http://localhost:3000`

### Step 2: Run Quick Test Script
```bash
cd ..
.\tests\quick-test.ps1
```

This will verify all routes are accessible.

### Step 3: Open Browser
Navigate to: `http://localhost:3000`

---

## ✅ Critical Tests (15 Minutes)

### Test 1: Admin Users (5 min)
1. **Navigate:** Sidebar → Admin Users → All Admin Users
2. **Verify:**
   - ✅ Title says "Admin Users" (not "User Management")
   - ✅ Icon is Shield (not Users)
   - ✅ Button says "Add Admin"
   - ✅ Table has "Role" column (not "Type" or "Package")
   - ✅ Avatars are indigo/purple gradient
   - ✅ Filter by Role works (Super Admin, Admin, Staff)

3. **Test Actions:**
   - Click "Add Admin" → Modal opens
   - Click Edit → Modal opens with data
   - Click Activate/Deactivate → Confirmation shows
   - Search by name → Results filter

**Expected:** Only admin/staff users shown, no customer users

---

### Test 2: PPPoE Users (5 min)
1. **Navigate:** Sidebar → PPPoE → PPPoE Users
2. **Verify:**
   - ✅ Title says "PPPoE Users"
   - ✅ Icon is Network
   - ✅ Button says "Add PPPoE User"
   - ✅ Table shows Package, Expiry columns
   - ✅ Avatars are purple/indigo gradient
   - ✅ Filter by Package works

3. **Test Actions:**
   - Click "Add PPPoE User" → Navigates to add page
   - Click Edit → Opens edit functionality
   - Click Block/Unblock → Confirmation shows
   - Search → Results filter

**Expected:** Only PPPoE customer users shown

---

### Test 3: Hotspot Users (5 min)
1. **Navigate:** Sidebar → Hotspot → Hotspot Users
2. **Verify:**
   - ✅ Title says "Hotspot Users"
   - ✅ Icon is Wifi
   - ✅ Button says "Generate Vouchers" (not "Add User")
   - ✅ Table shows Voucher Code, Data Used columns
   - ✅ Avatars are blue/cyan gradient
   - ✅ NO edit/delete buttons (read-only)

3. **Test Actions:**
   - Click "Generate Vouchers" → Navigates to voucher page
   - Click row → Opens user details
   - Click "Disconnect" (if user active) → Confirmation shows
   - Search → Results filter

**Expected:** Only hotspot customer users shown, read-only view

---

## 🔍 Navigation Tests (5 Minutes)

### Sidebar Menu
- [ ] "Admin Users" menu exists (not just "Users")
- [ ] "Admin Users" submenu has 3 items
- [ ] "Hotspot" menu has "Hotspot Users" as first item
- [ ] "PPPoE" menu has "PPPoE Users" as first item
- [ ] Active menu is highlighted correctly

### Direct URLs
Open these URLs directly:
- `http://localhost:3000/dashboard/users/all` → Admin Users
- `http://localhost:3000/dashboard/pppoe/users` → PPPoE Users
- `http://localhost:3000/dashboard/hotspot/users` → Hotspot Users

All should load without errors.

### Breadcrumbs
- [ ] Admin Users: Dashboard → Admin Users
- [ ] PPPoE Users: Dashboard → PPPoE → Users
- [ ] Hotspot Users: Dashboard → Hotspot → Users

---

## 🎨 Visual Verification (5 Minutes)

### Avatar Colors (Quick Visual Check)
Open all three views side-by-side:
- **Admin Users:** Indigo → Purple gradient
- **PPPoE Users:** Purple → Indigo gradient
- **Hotspot Users:** Blue → Cyan gradient

They should be visually distinct.

### Badge Colors
- **Admin Roles:**
  - Super Admin: Red
  - Admin: Purple
  - Staff: Blue

- **Status:**
  - Active: Green with pulse animation
  - Inactive: Yellow
  - Blocked/Expired: Red

### Table Columns (Quick Check)
- **Admin Users:** Admin User, Contact, Role, Status, Last Login, Created, Actions
- **PPPoE Users:** User, Contact, Package, Status, Expiry, Actions
- **Hotspot Users:** User, Voucher Code, Package, Status, Expiry, Data Used, Actions

---

## 🐛 Common Issues to Check

### Issue 1: API Returns Wrong User Type
**Symptom:** Admin users view shows customer users  
**Check:** Browser console for API response  
**Fix:** Backend needs to filter by user type

### Issue 2: Routes Not Found
**Symptom:** 404 error on navigation  
**Check:** Browser console for routing errors  
**Fix:** Clear cache, restart dev server

### Issue 3: Modals Not Opening
**Symptom:** Click "Add Admin" nothing happens  
**Check:** Browser console for errors  
**Fix:** Check if modal components are imported

### Issue 4: Filters Not Working
**Symptom:** Selecting filter doesn't change results  
**Check:** Browser console for filter state  
**Fix:** Verify composable is working

### Issue 5: Pagination Not Working
**Symptom:** Can't navigate pages  
**Check:** Browser console for pagination state  
**Fix:** Verify usePagination composable

---

## 📊 Quick Checklist

Use this for rapid verification:

### Admin Users View
- [ ] Title: "Admin Users" ✓
- [ ] Icon: Shield ✓
- [ ] Button: "Add Admin" ✓
- [ ] Filter: Role (not Type) ✓
- [ ] Avatar: Indigo/Purple ✓
- [ ] Actions: Activate/Deactivate ✓

### PPPoE Users View
- [ ] Title: "PPPoE Users" ✓
- [ ] Icon: Network ✓
- [ ] Button: "Add PPPoE User" ✓
- [ ] Column: Package, Expiry ✓
- [ ] Avatar: Purple/Indigo ✓
- [ ] Actions: Block/Unblock ✓

### Hotspot Users View
- [ ] Title: "Hotspot Users" ✓
- [ ] Icon: Wifi ✓
- [ ] Button: "Generate Vouchers" ✓
- [ ] Column: Voucher, Data Used ✓
- [ ] Avatar: Blue/Cyan ✓
- [ ] Actions: Disconnect only ✓

---

## 🎯 Success Criteria

**All tests pass if:**
1. ✅ All three views load without errors
2. ✅ Navigation works correctly
3. ✅ Visual distinctions are clear
4. ✅ Filters work as expected
5. ✅ Actions trigger correctly
6. ✅ No console errors

**Partial success if:**
- Views load but some features don't work
- Navigation works but styling is off
- API returns data but wrong type

**Failure if:**
- Views don't load (404 errors)
- Critical errors in console
- Cannot navigate between views

---

## 📝 Report Issues

If you find issues, note:
1. **Which view** (Admin/PPPoE/Hotspot)
2. **What action** (click, search, filter)
3. **Expected result**
4. **Actual result**
5. **Console errors** (if any)

Example:
```
View: Admin Users
Action: Clicked "Add Admin" button
Expected: Modal opens
Actual: Nothing happens
Console: "CreateUserModal is not defined"
```

---

## 🚀 Next Steps After Testing

### If All Tests Pass ✅
1. Mark as "Ready for Backend Integration"
2. Proceed with Hotspot/PPPoE session views
3. Continue Phase 2 implementation

### If Issues Found ⚠️
1. Document all issues
2. Prioritize critical vs minor
3. Fix critical issues first
4. Re-test after fixes

### If Major Failures ❌
1. Check dev server is running
2. Clear browser cache
3. Restart dev server
4. Check for missing dependencies

---

## 📞 Need Help?

**Common Commands:**
```bash
# Restart dev server
cd frontend
npm run dev

# Clear cache and restart
rm -rf node_modules/.vite
npm run dev

# Check for errors
npm run lint

# Build for production (test)
npm run build
```

**Browser DevTools:**
- F12 → Console (check for errors)
- F12 → Network (check API calls)
- F12 → Vue DevTools (check component state)

---

**Ready to test?** Start with Step 1 above! 🚀
