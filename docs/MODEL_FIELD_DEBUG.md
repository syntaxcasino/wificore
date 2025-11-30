# Model Field Debugging - Backend Parameter Check

## Changes Made

### 1. Enhanced Console Logging (`useRouters.js` - Lines 94-101)
Added detailed logging to see exactly what the backend sends:

```javascript
console.log('Router models:', routers.value.map(r => ({ 
  id: r.id, 
  name: r.name, 
  model: r.model,
  board_name: r.board_name,
  'board-name': r['board-name'],
  allKeys: Object.keys(r)
})))
```

This will show:
- What field names exist in the router object
- Whether model data is under `model`, `board_name`, `board-name`, etc.
- All available keys in the router object

### 2. Multi-Field Model Getter (`RouterManagement.vue` - Lines 711-720)
Created `getRouterModel()` function that checks multiple possible field names:

```javascript
const getRouterModel = (router) => {
  // Check multiple possible field names that backend might use
  return router.model || 
         router.board_name || 
         router['board-name'] || 
         router.boardName ||
         router.device_model ||
         router['device-model'] ||
         null
}
```

**Checks for:**
- `model` - Standard field name
- `board_name` - Snake case variant
- `board-name` - Hyphenated variant (MikroTik API format)
- `boardName` - Camel case variant
- `device_model` - Alternative naming
- `device-model` - Alternative hyphenated naming

### 3. Enhanced Tooltip Debug (Line 285)
Updated empty state tooltip to show available keys:

```vue
:title="`No model data for router ID: ${router.id}. Keys: ${Object.keys(router).join(', ')}`"
```

When you hover over "—", it will show all available field names in that router object.

## How to Debug

### Step 1: Open Browser Console
1. Press `F12` to open DevTools
2. Go to Console tab
3. Refresh the page

### Step 2: Check Console Output
Look for the log output:

```javascript
Router models: [
  {
    id: 1,
    name: "Router-01",
    model: null,              // ← Is this null?
    board_name: "CHR...",     // ← Or is data here?
    "board-name": "CHR...",   // ← Or here?
    allKeys: ["id", "name", "board_name", "ip_address", ...]  // ← All available fields
  }
]
```

### Step 3: Identify the Correct Field Name

**Scenario A: Model is in `model` field**
```javascript
model: "CHR innotek GmbH VirtualBox"  // ✅ Working correctly
```
→ No backend changes needed

**Scenario B: Model is in `board_name` field**
```javascript
model: null,
board_name: "CHR innotek GmbH VirtualBox"  // ← Backend using wrong field name
```
→ Frontend now handles this automatically with `getRouterModel()`

**Scenario C: Model is in `board-name` field**
```javascript
model: null,
"board-name": "CHR innotek GmbH VirtualBox"  // ← MikroTik API format
```
→ Frontend now handles this automatically with `getRouterModel()`

**Scenario D: Model data doesn't exist**
```javascript
model: null,
board_name: null,
allKeys: ["id", "name", "ip_address", "status", ...]  // ← No model field at all
```
→ Backend needs to fetch and include model data

### Step 4: Check Tooltip
Hover over the "—" symbol in the Model column. The tooltip will show:
```
No model data for router ID: 1. Keys: id, name, ip_address, status, live_data, ...
```

This tells you exactly what fields are available.

## Expected Backend Response

### Correct Format
```json
{
  "data": [
    {
      "id": 1,
      "name": "Router-01",
      "ip_address": "10.0.0.1",
      "model": "CHR innotek GmbH VirtualBox",  // ← Should be here
      "status": "online",
      "live_data": { ... }
    }
  ]
}
```

### Alternative Formats (Now Supported)
```json
{
  "data": [
    {
      "id": 1,
      "board_name": "CHR innotek GmbH VirtualBox"  // ✅ Now works
    }
  ]
}
```

```json
{
  "data": [
    {
      "id": 1,
      "board-name": "CHR innotek GmbH VirtualBox"  // ✅ Now works
    }
  ]
}
```

## Backend Fix (If Needed)

If the console shows model data doesn't exist at all, the backend needs to:

### 1. Fetch Model from MikroTik
```php
// When connecting to router
$resource = $client->query('/system/resource/print')->read();
$boardName = $resource[0]['board-name'] ?? null;

// Save to database
$router->update(['model' => $boardName]);
```

### 2. Include in API Response
```php
// In Router API Resource or Controller
return [
    'id' => $router->id,
    'name' => $router->name,
    'ip_address' => $router->ip_address,
    'model' => $router->model,  // ← Make sure this is included
    'status' => $router->status,
    // ... other fields
];
```

### 3. Include in WebSocket Events
```php
// When broadcasting RouterStatusUpdated
broadcast(new RouterStatusUpdated([
    'routers' => $routers->map(fn($r) => [
        'id' => $r->id,
        'status' => $r->status,
        'model' => $r->model,  // ← Include this
        'os_version' => $r->os_version,
    ])
]));
```

## Testing

### Test 1: Check Console Logs
After refreshing the page, you should see in console:
```
fetchRouters response: { data: [...] }
Routers sorted by ID: [...]
Router models: [
  { id: 1, name: "Router-01", model: "...", board_name: "...", allKeys: [...] }
]
```

### Test 2: Check Tooltip
Hover over "—" in Model column:
- If model exists: Shows full model name
- If no model: Shows available field names

### Test 3: Manual Test
In browser console, try:
```javascript
// Check what's in the first router
console.log('First router:', routers.value[0])

// Check all possible model fields
console.log('Model fields:', {
  model: routers.value[0]?.model,
  board_name: routers.value[0]?.board_name,
  'board-name': routers.value[0]?.['board-name']
})
```

## Common Issues & Solutions

### Issue 1: Field Name Mismatch
**Symptom:** Console shows data in `board_name` but not in `model`

**Solution:** ✅ Already fixed! `getRouterModel()` checks multiple field names

### Issue 2: Data Not Fetched
**Symptom:** Console shows `model: null` and no alternative fields

**Solution:** Backend needs to fetch model from MikroTik and store in database

### Issue 3: Data Not Included in Response
**Symptom:** Database has model data but API doesn't return it

**Solution:** Backend needs to include `model` field in API response

### Issue 4: WebSocket Not Updating
**Symptom:** Initial load shows model, but WebSocket updates lose it

**Solution:** Backend needs to include `model` in WebSocket event payload

## Summary

✅ **Frontend now checks multiple field names:**
- `model`
- `board_name`
- `board-name`
- `boardName`
- `device_model`
- `device-model`

✅ **Enhanced debugging:**
- Console logs show all available fields
- Tooltip shows missing field info
- Easy to identify backend field name

✅ **Next Steps:**
1. Refresh page and check browser console
2. Look for "Router models:" log output
3. Identify which field contains the model data
4. Share the console output if still not working

The frontend is now robust enough to handle various backend field naming conventions!
