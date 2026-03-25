# API Documentation

**Version:** 1.0  
**Base URL:** `https://yourdomain.com/api`  
**Authentication:** Bearer Token (Sanctum)

---

## Authentication

### Login
```http
POST /api/login
Content-Type: application/json

{
  "username": "user@example.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "user": {
      "id": "uuid",
      "name": "John Doe",
      "email": "user@example.com",
      "role": "admin"
    },
    "token": "1|bearer_token_here",
    "dashboard_route": "/dashboard"
  }
}
```

### Logout
```http
POST /api/logout
Authorization: Bearer {token}
```

---

## Rate Limiting

All endpoints are rate-limited:
- Authentication: 5 requests per minute
- General API: 60 requests per minute
- Payment: 10 requests per minute

**Rate Limit Headers:**
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 59
Retry-After: 60
```

---

## Error Responses

### Standard Error Format
```json
{
  "success": false,
  "message": "Error description",
  "error_code": "ERROR_CODE",
  "errors": {}
}
```

### Error Codes
- `UNAUTHENTICATED` - 401
- `ACCESS_DENIED` - 403
- `NOT_FOUND` - 404
- `VALIDATION_ERROR` - 422
- `RATE_LIMIT_EXCEEDED` - 429
- `SERVER_ERROR` - 500

---

## Routers

### List Routers
```http
GET /api/routers
Authorization: Bearer {token}
```

### Create Router
```http
POST /api/routers
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Router 1",
  "ip_address": "192.168.1.1",
  "username": "admin",
  "password": "secure_password",
  "port": 8728
}
```

### Update Router
```http
PUT /api/routers/{id}
Authorization: Bearer {token}
```

### Delete Router
```http
DELETE /api/routers/{id}
Authorization: Bearer {token}
```

---

## Packages

### List Packages
```http
GET /api/packages
Authorization: Bearer {token}
```

### Create Package
```http
POST /api/packages
Authorization: Bearer {token}
Content-Type: application/json

{
  "name": "Basic Plan",
  "price": 500,
  "duration_hours": 24,
  "bandwidth_limit": "10M"
}
```

---

## Payments

### Initiate Payment
```http
POST /api/payments/initiate
Content-Type: application/json

{
  "phone_number": "+254712345678",
  "package_id": "uuid",
  "mac_address": "AA:BB:CC:DD:EE:FF"
}
```

### Check Payment Status
```http
GET /api/payments/{payment_id}/status
```

---

## WebSocket Events

### Connect
```javascript
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const echo = new Echo({
  broadcaster: 'pusher',
  key: 'app-key',
  wsHost: 'yourdomain.com',
  wsPort: 6071,
  forceTLS: false,
  disableStats: true,
  authorizer: (channel) => ({
    authorize: (socketId, callback) => {
      axios.post('/api/broadcasting/auth', {
        socket_id: socketId,
        channel_name: channel.name
      }).then(response => {
        callback(false, response.data);
      }).catch(error => {
        callback(true, error);
      });
    }
  })
});
```

### Subscribe to Channels
```javascript
// Private channel
echo.private(`tenant.${tenantId}`)
  .listen('PaymentCompleted', (e) => {
    console.log('Payment completed:', e);
  });
```

---

## Pagination

All list endpoints support pagination:

```http
GET /api/routers?page=1&per_page=20
```

**Response:**
```json
{
  "data": [],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "per_page": 20,
    "total": 100
  }
}
```

---

## Filtering & Sorting

```http
GET /api/routers?status=active&sort=name&order=asc
```

---

## Testing

Use Postman collection: `docs/postman/WiFiCore.postman_collection.json`

---

**For detailed endpoint documentation, see individual controller files.**
