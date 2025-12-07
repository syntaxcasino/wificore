# API Test Instructions

## Test the Router Creation Endpoint Directly

Open your browser console and run this:

```javascript
// Test 1: Check if axios is configured correctly
console.log('Axios baseURL:', axios.defaults.baseURL)

// Test 2: Try creating a router directly
fetch('http://localhost:8000/api/routers', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Authorization': 'Bearer ' + localStorage.getItem('token')
  },
  body: JSON.stringify({
    name: 'test-router-01'
  })
})
.then(r => r.json())
.then(data => {
  console.log('SUCCESS:', data)
  console.log('connectivity_script:', data.connectivity_script ? 'EXISTS' : 'MISSING')
  console.log('vpn_script:', data.vpn_script ? 'EXISTS' : 'MISSING')
})
.catch(err => console.error('ERROR:', err))
```

## Expected Response

You should see:
```json
{
  "id": "...",
  "name": "test-router-01",
  "connectivity_script": "/ip address add...",
  "vpn_script": "# VPN CONFIGURATION..."
}
```

## If You See Errors

1. **401 Unauthorized** → Token issue
2. **404 Not Found** → Wrong endpoint
3. **500 Server Error** → Backend issue
4. **CORS Error** → Backend CORS config

Please run this test and tell me what error you see.
