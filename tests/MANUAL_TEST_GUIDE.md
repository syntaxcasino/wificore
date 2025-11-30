# Manual Testing Guide - User Management Restructure

**Date:** October 12, 2025  
**Tester:** _________________  
**Environment:** Development

---

## 🚀 Pre-Test Setup

### 1. Start Development Server
```bash
cd frontend
npm run dev
```

### 2. Login to Dashboard
- Navigate to: `http://localhost:3000/login`
- Login with admin credentials
- Verify you reach the dashboard

---

## ✅ Test 1: Admin Users View

### Access the View
- **URL:** `http://localhost:3000/dashboard/users/all`
- **Navigation:** Sidebar → Admin Users → All Admin Users

### Visual Checks
- [ ] Page title shows "Admin Users"
- [ ] Subtitle shows "Manage system administrators and staff accounts"
- [ ] Icon is Shield (not Users)
- [ ] Breadcrumbs show: Dashboard → Admin Users
- [ ] Search placeholder says "Search admin users..."
- [ ] Button says "Add Admin" (not "Add User")

### Header Actions
- [ ] Search input is visible
- [ ] "Add Admin" button is visible and styled (blue gradient)
- [ ] Click "Add Admin" opens create modal

### Filters Bar
- [ ] Status filter shows: All Status, Active, Inactive
- [ ] Role filter shows: All Roles, Super Admin, Admin, Staff
- [ ] Badges show: Total count, Active count (green with pulse), Inactive count (yellow)
- [ ] "Clear Filters" button appears when filters are active

### Table Structure
- [ ] Table has 7 columns: Admin User, Contact, Role, Status, Last Login, Created, Actions
- [ ] Table does NOT have "Package" or "Type" columns
- [ ] Avatars have indigo-to-purple gradient
- [ ] Username shows with @ prefix (e.g., @admin)

### Table Data
- [ ] Only admin/staff users are displayed (no customer users)
- [ ] Role badges show correct colors:
  - Super Admin: Red
  - Admin: Purple
  - Staff: Blue
- [ ] Status badges show:
  - Active: Green with pulse animation
  - Inactive: Yellow

### Actions
- [ ] Edit button (pencil icon) is visible
- [ ] Status toggle button shows "Activate" or "Deactivate"
- [ ] Delete button (trash icon) is visible in red
- [ ] Click row opens user details modal

### Filtering
- [ ] Search by name works
- [ ] Search by email works
- [ ] Search by username works
- [ ] Filter by status works (Active/Inactive)
- [ ] Filter by role works (Super Admin/Admin/Staff)
- [ ] Clear filters resets all filters

### Pagination
- [ ] Pagination controls are visible
- [ ] Items per page selector works (10, 25, 50, 100)
- [ ] Page navigation works (First, Previous, Next, Last)
- [ ] "Showing X to Y of Z users" displays correctly

### Loading States
- [ ] Skeleton loader shows while fetching data
- [ ] Loading state has 5 skeleton rows

### Empty State
- [ ] If no users, shows "No admin users yet" message
- [ ] Shows "Get started by creating your first admin account"
- [ ] Shield icon is displayed
- [ ] "Add Admin" button is shown

### Error State
- [ ] If API error, shows error alert (red)
- [ ] Error message is displayed
- [ ] "Retry" button is shown

---

## ✅ Test 2: PPPoE Users View

### Access the View
- **URL:** `http://localhost:3000/dashboard/pppoe/users`
- **Navigation:** Sidebar → PPPoE → PPPoE Users

### Visual Checks
- [ ] Page title shows "PPPoE Users"
- [ ] Subtitle shows "Manage PPPoE customer accounts"
- [ ] Icon is Network
- [ ] Breadcrumbs show: Dashboard → PPPoE → Users
- [ ] Search placeholder says "Search PPPoE users..."
- [ ] Button says "Add PPPoE User"

### Header Actions
- [ ] Search input is visible
- [ ] "Add PPPoE User" button is visible
- [ ] Click button navigates to `/dashboard/pppoe/add-user`

### Filters Bar
- [ ] Status filter shows: All Status, Active, Inactive, Blocked, Expired
- [ ] Package filter shows: All Packages + list of packages
- [ ] Badges show: Total, Active (green with pulse), Inactive (yellow)

### Table Structure
- [ ] Table has 6 columns: User, Contact, Package, Status, Expiry, Actions
- [ ] Avatars have purple-to-indigo gradient
- [ ] Username is displayed below name

### Table Data
- [ ] Only PPPoE customer users are displayed
- [ ] Package name and speed are shown
- [ ] Expiry date is formatted correctly
- [ ] Status badges show correct colors:
  - Active: Green with pulse
  - Inactive: Yellow
  - Blocked: Red
  - Expired: Red

### Actions
- [ ] Edit button (pencil icon) is visible
- [ ] Block/Unblock button shows correct text based on status
- [ ] Delete button (trash icon) is visible in red
- [ ] Click row opens user details

### Filtering
- [ ] Search works across all fields
- [ ] Filter by status works
- [ ] Filter by package works
- [ ] Clear filters works

### Pagination
- [ ] Pagination works correctly
- [ ] Items per page selector works

---

## ✅ Test 3: Hotspot Users View

### Access the View
- **URL:** `http://localhost:3000/dashboard/hotspot/users`
- **Navigation:** Sidebar → Hotspot → Hotspot Users

### Visual Checks
- [ ] Page title shows "Hotspot Users"
- [ ] Subtitle shows "View and manage hotspot customer accounts (auto-created on payment)"
- [ ] Icon is Wifi
- [ ] Breadcrumbs show: Dashboard → Hotspot → Users
- [ ] Search placeholder says "Search hotspot users..."
- [ ] Button says "Generate Vouchers" (not "Add User")

### Header Actions
- [ ] Search input is visible
- [ ] "Generate Vouchers" button is visible with ticket icon
- [ ] Click button navigates to `/dashboard/hotspot/vouchers/generate`

### Filters Bar
- [ ] Status filter shows: All Status, Active, Inactive, Expired (no "Blocked")
- [ ] Package filter shows: All Packages + list of packages
- [ ] Badges show: Total, Active, Inactive

### Table Structure
- [ ] Table has 7 columns: User, Voucher Code, Package, Status, Expiry, Data Used, Actions
- [ ] Avatars have blue-to-cyan gradient
- [ ] Phone number is shown below name

### Table Data
- [ ] Only hotspot customer users are displayed
- [ ] Voucher codes are shown in monospace font
- [ ] Package name and duration are shown
- [ ] Data used is formatted (B, KB, MB, GB)
- [ ] Status badges show correct colors

### Actions
- [ ] View sessions button (activity icon) is visible
- [ ] "Disconnect" button shows ONLY for active users
- [ ] NO edit or delete buttons (read-only)
- [ ] Click row opens user details

### Filtering
- [ ] Search works
- [ ] Filter by status works
- [ ] Filter by package works

### Empty State
- [ ] Shows "No hotspot users yet"
- [ ] Message says "Hotspot users are automatically created when customers make payments"
- [ ] Button says "View Vouchers" (not "Add User")

---

## ✅ Test 4: Navigation & Routing

### Sidebar Menu
- [ ] "Admin Users" menu item exists (not just "Users")
- [ ] Clicking "Admin Users" expands submenu
- [ ] Submenu shows: All Admin Users, Create Admin, Roles & Permissions
- [ ] "Hotspot" menu has "Hotspot Users" as first item
- [ ] "PPPoE" menu has "PPPoE Users" as first item

### Menu Highlighting
- [ ] Active menu item is highlighted (white text, gray background)
- [ ] Parent menu stays highlighted when on child route
- [ ] Correct menu expands based on current route

### Direct URL Access
- [ ] `/dashboard/users/all` loads Admin Users
- [ ] `/dashboard/pppoe/users` loads PPPoE Users
- [ ] `/dashboard/hotspot/users` loads Hotspot Users
- [ ] Invalid routes show 404 or redirect

### Breadcrumb Navigation
- [ ] Breadcrumbs are clickable
- [ ] Clicking breadcrumb navigates correctly
- [ ] Last breadcrumb is not clickable (current page)

---

## ✅ Test 5: Modals & Interactions

### Create Admin Modal
- [ ] Opens when clicking "Add Admin"
- [ ] Form has all required fields
- [ ] Validation works
- [ ] Submit creates user
- [ ] Cancel closes modal
- [ ] Success refreshes list

### Edit User Modal
- [ ] Opens when clicking edit button
- [ ] Form is pre-populated with user data
- [ ] Changes can be saved
- [ ] Cancel discards changes

### User Details Modal
- [ ] Opens when clicking table row
- [ ] Shows all user information
- [ ] "Edit User" button works
- [ ] Close button works

### Confirmation Dialogs
- [ ] Delete shows confirmation
- [ ] Block/Unblock shows confirmation
- [ ] Activate/Deactivate shows confirmation
- [ ] Disconnect shows confirmation

---

## ✅ Test 6: Responsive Design

### Desktop (1920x1080)
- [ ] All columns are visible
- [ ] No horizontal scroll
- [ ] Proper spacing

### Tablet (768x1024)
- [ ] Table is scrollable horizontally
- [ ] Filters wrap correctly
- [ ] Buttons are accessible

### Mobile (375x667)
- [ ] Sidebar collapses
- [ ] Table scrolls horizontally
- [ ] Search and filters are usable
- [ ] Pagination adapts

---

## ✅ Test 7: Performance

### Load Time
- [ ] Initial page load < 2 seconds
- [ ] Data fetching shows loading state
- [ ] No blank screens

### Interactions
- [ ] Search is responsive (< 300ms)
- [ ] Filter changes are instant
- [ ] Pagination is smooth
- [ ] No lag when clicking rows

### Data Handling
- [ ] Large datasets (100+ users) load correctly
- [ ] Pagination handles large datasets
- [ ] Search works with large datasets

---

## ✅ Test 8: Error Handling

### API Errors
- [ ] Network error shows error message
- [ ] 404 shows appropriate message
- [ ] 500 shows error with retry
- [ ] Timeout shows error

### Validation Errors
- [ ] Form validation shows inline errors
- [ ] Required fields are marked
- [ ] Invalid email format is caught
- [ ] Password mismatch is caught

### Edge Cases
- [ ] Empty search returns all results
- [ ] Invalid filter combinations handled
- [ ] Pagination on last page works
- [ ] Deleting last item on page works

---

## 📊 Test Results Summary

### Admin Users View
- **Status:** [ ] Pass [ ] Fail [ ] Partial
- **Issues Found:** _______________
- **Notes:** _______________

### PPPoE Users View
- **Status:** [ ] Pass [ ] Fail [ ] Partial
- **Issues Found:** _______________
- **Notes:** _______________

### Hotspot Users View
- **Status:** [ ] Pass [ ] Fail [ ] Partial
- **Issues Found:** _______________
- **Notes:** _______________

### Navigation & Routing
- **Status:** [ ] Pass [ ] Fail [ ] Partial
- **Issues Found:** _______________
- **Notes:** _______________

### Overall Assessment
- **Total Tests:** 8
- **Passed:** _____
- **Failed:** _____
- **Blocked:** _____

---

## 🐛 Issues Found

| # | Component | Issue | Severity | Status |
|---|-----------|-------|----------|--------|
| 1 |           |       |          |        |
| 2 |           |       |          |        |
| 3 |           |       |          |        |

---

## ✅ Sign-Off

- **Tester:** _________________
- **Date:** _________________
- **Approved:** [ ] Yes [ ] No
- **Ready for Production:** [ ] Yes [ ] No

---

## 📝 Notes

_Add any additional observations, suggestions, or concerns here:_

