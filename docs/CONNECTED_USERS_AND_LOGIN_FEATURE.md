# Connected Users & Login Feature

## Features Added

### 1. **Connected Users Column** 👥
Displays the number of active connections on each router in real-time.

### 2. **Login to Router Button** 🔐
Allows direct login to router's WebFig interface with one click.

## Implementation Details

### Connected Users Column

#### Frontend Display (RouterManagement.vue)
**Table Header (Line 191):**
```vue
<div class="w-[90px] text-xs font-semibold text-slate-700 uppercase tracking-wider hidden xl:block">
  Users
</div>
```

**Table Body (Lines 283-290):**
```vue
<!-- Connected Users -->
<div class="flex items-center gap-1 text-xs w-[90px] hidden xl:flex">
  <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
  </svg>
  <span v-if="getConnectedUsers(router) !== null" class="font-medium text-slate-700">
    {{ getConnectedUsers(router) }}
  </span>
  <span v-else class="text-slate-400">—</span>
</div>
```

#### Helper Function (Lines 752-761)
```javascript
const getConnectedUsers = (router) => {
  // Check live data for active connections
  if (router.live?.active_connections !== undefined) {
    return router.live.active_connections
  }
  if (router.live_data?.active_connections !== undefined) {
    return router.live_data.active_connections
  }
  return null
}
```

#### Backend Data Source
The data comes from `MikrotikProvisioningService.php` (Line 345):
```php
'active_connections' => count($connections),
```

This is fetched from MikroTik router via API:
```php
$connections = $client->query('/ip/firewall/connection/print')->read();
```

### Login to Router Feature

#### Login Button (Lines 307-314)
```vue
<button @click="loginToRouter(router)" :disabled="router.status !== 'online'"
  class="px-2 py-1 text-xs font-medium text-emerald-700 bg-emerald-50 rounded hover:bg-emerald-100 transition-colors flex items-center gap-1 disabled:opacity-50 disabled:cursor-not-allowed"
  :title="router.status !== 'online' ? 'Router must be online to login' : 'Login to router via Winbox/WebFig'">
  <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
    <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
  </svg>
  Login
</button>
```

#### Login Function (Lines 861-878)
```javascript
const loginToRouter = (router) => {
  if (router.status !== 'online') {
    alert('Router must be online to login')
    return
  }

  // Extract IP address (remove subnet mask if present)
  const ipAddress = router.ip_address?.split('/')[0] || router.ip_address

  if (!ipAddress) {
    alert('Router IP address not available')
    return
  }

  // Open WebFig (MikroTik web interface) in new tab
  const webfigUrl = `http://${ipAddress}`
  window.open(webfigUrl, '_blank', 'noopener,noreferrer')
}
```

## How It Works

### Connected Users Display

1. **Backend Fetches Data** (every 30 seconds via scheduler):
   ```
   FetchRouterLiveData job → MikroTik API → /ip/firewall/connection/print
   ```

2. **Data Structure**:
   ```json
   {
     "router_id": 1,
     "live_data": {
       "cpu_load": "2",
       "free_memory": "841555968",
       "total_memory": "1073741824",
       "active_connections": 5,  // ← Number of connected users
       "dhcp_leases": 3,
       "interfaces": [...]
     }
   }
   ```

3. **WebSocket Broadcast**:
   ```javascript
   broadcast(new RouterLiveDataUpdated($router->id, $liveData))
   ```

4. **Frontend Updates**:
   ```javascript
   .listen('.RouterLiveDataUpdated', (e) => {
     const idx = routers.value.findIndex((r) => r.id === e.router_id)
     if (idx !== -1) {
       routers.value[idx].live_data = e.data
     }
   })
   ```

5. **Display**:
   ```javascript
   getConnectedUsers(router) → router.live_data.active_connections → Display "5"
   ```

### Login to Router

1. **User clicks "Login" button**
2. **Function checks**:
   - Router status is 'online' ✅
   - IP address exists ✅
3. **Extracts clean IP**:
   ```javascript
   "192.168.56.244/24" → "192.168.56.244"
   ```
4. **Opens WebFig**:
   ```javascript
   window.open("http://192.168.56.244", '_blank')
   ```
5. **User sees MikroTik WebFig login page** in new tab

## UI/UX Features

### Connected Users Column
- **Icon**: Users icon (👥) for visual clarity
- **Display**: Shows number (e.g., "5")
- **Empty State**: Shows "—" if no data
- **Visibility**: Hidden on screens < 1280px (xl breakpoint)
- **Real-time**: Updates every 30 seconds via WebSocket

### Login Button
- **Color**: Emerald green (login action)
- **Icon**: Login arrow icon
- **Disabled State**: 
  - Grayed out when router is offline
  - Tooltip: "Router must be online to login"
- **Enabled State**:
  - Tooltip: "Login to router via Winbox/WebFig"
  - Opens in new tab
- **Security**: Uses `noopener,noreferrer` for security

## Column Layout

Updated table header spacing:

| Column | Width | Visibility | Data |
|--------|-------|------------|------|
| Icon | 28px | md+ | Router icon |
| Router Name | 200px | Always | Name + status dot |
| IP Address | 140px | lg+ | IP with icon |
| Status | 100px | Always | Badge (online/offline) |
| CPU | 110px | xl+ | Usage bar + % |
| Memory | 110px | xl+ | Usage bar + % |
| Disk | 110px | xl+ | Usage bar + % |
| **Users** | **90px** | **xl+** | **👥 Count** |
| Model | 120px | lg+ | Formatted model name |
| Last Seen | 100px | lg+ | Time ago |
| Actions | 180px | Always | Login + View + Menu |

## Testing

### Test Connected Users Display

1. **Check data is fetched**:
   ```bash
   # Check backend logs
   docker exec traidnet-backend tail -f /var/www/html/storage/logs/router-data-queue.log
   
   # Should see:
   # Fetched live data for router
   # active_connections: 5
   ```

2. **Check WebSocket broadcast**:
   ```javascript
   // Browser console should show:
   RouterLiveDataUpdated: {
     router_id: 1,
     data: {
       active_connections: 5,
       ...
     }
   }
   ```

3. **Verify display**:
   - Open RouterManagement page
   - Look for "Users" column (on xl screens)
   - Should show number next to users icon

### Test Login Button

1. **Online Router**:
   - Click "Login" button
   - New tab opens with `http://<router-ip>`
   - MikroTik WebFig login page appears

2. **Offline Router**:
   - Login button is grayed out
   - Hover shows "Router must be online to login"
   - Click shows alert

3. **IP Address Handling**:
   - Test with IP: `192.168.1.1` → Opens `http://192.168.1.1`
   - Test with CIDR: `192.168.1.1/24` → Opens `http://192.168.1.1`

## Browser Console Logs (Removed)

Previously had console.log statements - all removed for production:
```javascript
// ❌ Removed:
console.log('📊 RouterLiveDataUpdated:', e)
console.log('🚀 RouterManagement mounted')
console.log('Memory calc for router:', {...})

// ✅ Kept only errors:
console.error('fetchRouters error:', err)
```

## Security Considerations

### Login Feature
- ✅ Opens in new tab with `noopener,noreferrer`
- ✅ Prevents tab-napping attacks
- ✅ Only works for online routers
- ✅ Uses HTTP (MikroTik default)
- ⚠️ Consider HTTPS if routers support it

### Connected Users Data
- ✅ Read-only display
- ✅ No sensitive information exposed
- ✅ Real-time via WebSocket (encrypted if using WSS)

## Future Enhancements

### Connected Users
- [ ] Click to view list of connected users
- [ ] Show user details (IP, MAC, username)
- [ ] Disconnect user action
- [ ] User activity graph

### Login Feature
- [ ] Support HTTPS for routers with SSL
- [ ] Support Winbox protocol (winbox://)
- [ ] Remember login credentials (secure vault)
- [ ] SSH terminal access
- [ ] VNC/Remote desktop option

## Summary

✅ **Connected Users Column**:
- Shows real-time active connections
- Updates every 30 seconds
- Data from MikroTik firewall connections
- Clean UI with icon and number

✅ **Login to Router**:
- One-click access to WebFig
- Opens in new tab
- Disabled for offline routers
- Secure with noopener/noreferrer

✅ **Integration**:
- Uses existing live data pipeline
- No new backend endpoints needed
- Leverages WebSocket for real-time updates
- Responsive design (hidden on small screens)

**The RouterManagement page now shows connected users and provides quick router login access!** 🎉
