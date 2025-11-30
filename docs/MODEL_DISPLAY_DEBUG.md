# Model Column Not Displaying Data - Debug Guide

## Current Implementation

The Model column is correctly implemented in `RouterManagement.vue`:

### Display Code (Line 283-286)
```vue
<!-- Model -->
<div class="flex items-center gap-1 text-xs text-slate-500 w-[120px] hidden lg:flex">
  <span v-if="router.model" class="truncate">{{ router.model }}</span>
  <span v-else class="text-slate-400">—</span>
</div>
```

**This is correct** - it displays the model if it exists, otherwise shows "—"

### WebSocket Update (Line 915)
```javascript
routers.value[idx].model = updatedRouter.model || routers.value[idx].model;
```

**This is correct** - it updates the model when RouterStatusUpdated event is received

## Possible Causes

### 1. **Backend Not Sending Model Data** ⚠️ MOST LIKELY
The backend API might not be including the `model` field in the response.

**Check:**
```javascript
// In browser console, check what the API returns:
console.log('Router data:', routers.value)
console.log('First router model:', routers.value[0]?.model)
```

**Expected Response:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Router-01",
      "ip_address": "10.0.0.1",
      "model": "RB750Gr3",  // ← Should be here
      "status": "online",
      "live_data": { ... }
    }
  ]
}
```

### 2. **Model Field is NULL/Empty in Database**
The routers in the database might not have model information populated.

**Check Database:**
```sql
SELECT id, name, model FROM routers;
```

If model column is NULL or empty, the backend needs to:
- Fetch model from MikroTik API during initial connection
- Store it in the database
- Include it in API responses

### 3. **Column Hidden on Smaller Screens**
The model column has `hidden lg:flex` which means it's only visible on large screens (1024px+).

**Check:**
- Is your screen width >= 1024px?
- Try removing `hidden lg:flex` temporarily to test:
```vue
<div class="flex items-center gap-1 text-xs text-slate-500 w-[120px]">
```

### 4. **MikroTik Not Providing Model Info**
Some MikroTik devices might not expose model information via API.

**MikroTik API Check:**
```
/system/resource/print
```

Should return:
```
board-name: RB750Gr3
platform: MikroTik
```

## Debugging Steps

### Step 1: Check Browser Console
Open browser DevTools (F12) and check:

```javascript
// After page loads, in console:
console.log('All routers:', routers.value)
console.log('Models:', routers.value.map(r => ({ id: r.id, name: r.name, model: r.model })))
```

**Expected Output:**
```javascript
Models: [
  { id: 1, name: 'Router-01', model: 'RB750Gr3' },
  { id: 2, name: 'Router-02', model: 'CCR1009' },
  { id: 3, name: 'Router-03', model: 'RB4011' }
]
```

**If you see:**
```javascript
Models: [
  { id: 1, name: 'Router-01', model: null },
  { id: 2, name: 'Router-02', model: '' },
  { id: 3, name: 'Router-03', model: undefined }
]
```
→ **Backend is not sending model data**

### Step 2: Check Network Tab
1. Open DevTools → Network tab
2. Refresh the page
3. Find the `/routers` request
4. Check the Response

**Look for:**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Router-01",
      "model": "???"  // ← Is this field present?
    }
  ]
}
```

### Step 3: Check WebSocket Events
In console, watch for WebSocket updates:

```javascript
// The component already logs these:
// 📡 RouterStatusUpdated: { routers: [...] }
```

Check if the event includes model:
```javascript
{
  routers: [
    {
      id: 1,
      status: 'online',
      model: 'RB750Gr3',  // ← Should be here
      os_version: '7.11'
    }
  ]
}
```

### Step 4: Temporary Debug Display
Add this to see raw router data:

```vue
<!-- Add after Model column -->
<div class="w-[200px] text-xs">
  DEBUG: {{ JSON.stringify({ model: router.model, hasModel: !!router.model }) }}
</div>
```

## Backend Fixes Needed

If the backend is not sending model data, update:

### 1. **Database Migration** (if column doesn't exist)
```php
Schema::table('routers', function (Blueprint $table) {
    $table->string('model')->nullable();
});
```

### 2. **Fetch Model from MikroTik**
When connecting to router, fetch system resource:

```php
// In RouterService or similar
$resource = $client->query('/system/resource/print')->read();
$model = $resource[0]['board-name'] ?? null;

// Save to database
$router->update(['model' => $model]);
```

### 3. **Include in API Response**
Ensure the Router model includes model in JSON:

```php
// In Router model or API Resource
public function toArray($request)
{
    return [
        'id' => $this->id,
        'name' => $this->name,
        'ip_address' => $this->ip_address,
        'model' => $this->model,  // ← Include this
        'status' => $this->status,
        // ... other fields
    ];
}
```

### 4. **Include in WebSocket Events**
When broadcasting RouterStatusUpdated:

```php
broadcast(new RouterStatusUpdated([
    'routers' => $routers->map(fn($r) => [
        'id' => $r->id,
        'status' => $r->status,
        'model' => $r->model,  // ← Include this
        'os_version' => $r->os_version,
    ])
]));
```

## Quick Test

To verify the frontend is working, temporarily hardcode a model:

```javascript
// In browser console:
routers.value[0].model = 'TEST-MODEL'
```

If "TEST-MODEL" appears in the table, the frontend is working correctly and the issue is backend data.

## Summary

✅ **Frontend Implementation**: Correct  
✅ **WebSocket Listener**: Correct  
✅ **Display Logic**: Correct  

❌ **Most Likely Issue**: Backend not providing model data

**Next Steps:**
1. Check browser console for router data
2. Check Network tab for API response
3. Verify backend includes `model` field in responses
4. Ensure MikroTik model is fetched and stored in database
