# Login Issue Debugging

## Issue
Getting "The username field is required" error when logging in as system admin.

## Backend Expectation
The backend expects a field called `login` (not `username`):

```php
// backend/app/Http/Controllers/Api/UnifiedAuthController.php (line 35)
$validator = Validator::make($request->all(), [
    'login' => 'required|string', // Can be username or email
    'password' => 'required|string',
    'remember' => 'boolean',
]);
```

## Frontend Auth Store
The auth store is correctly sending `login`:

```javascript
// frontend/src/stores/auth.js (line 26-30)
const response = await axios.post(`${API_URL}/login`, {
  login: credentials.username || credentials.email,  // ✅ Correct
  password: credentials.password,
  remember: credentials.remember || false,
})
```

## Test the API Directly

### Using Browser Console
Open browser console and run:

```javascript
fetch('http://localhost/api/login', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
  },
  body: JSON.stringify({
    login: 'sysadmin',
    password: 'Admin@123!'
  })
})
.then(r => r.json())
.then(console.log)
```

### Expected Response
```json
{
  "success": true,
  "data": {
    "user": {
      "id": "00000000-0000-0000-0000-000000000001",
      "username": "sysadmin",
      "role": "system_admin",
      ...
    },
    "token": "...",
    "dashboard_route": "/system/dashboard"
  }
}
```

## Possible Issues

### 1. Axios Configuration
Check if axios is transforming the request body incorrectly.

### 2. CORS/Proxy Issue
The nginx reverse proxy might be modifying the request.

### 3. Content-Type Header
Ensure the request has `Content-Type: application/json`.

## Solution

Check the browser Network tab:
1. Open DevTools (F12)
2. Go to Network tab
3. Try to login
4. Click on the `/api/login` request
5. Check the "Payload" or "Request" tab
6. Verify it shows: `{"login":"sysadmin","password":"..."}`

If it shows `{"username":"sysadmin",...}` instead, then the auth store is not being used correctly.
