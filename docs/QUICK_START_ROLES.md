# Quick Start Guide - User Roles System

## 🚀 Getting Started

### Step 1: Restart the System

```bash
# Stop all services
docker-compose down

# Start all services (database schema will auto-update)
docker-compose up -d

# Wait for services to be ready
sleep 30

# Check all containers are healthy
docker ps
```

---

### Step 2: Create Admin User

**Linux:**
```bash
cd scripts
chmod +x create-radius-user.sh
./create-radius-user.sh -u admin -p admin123
```

**Windows:**
```powershell
cd scripts
.\create-radius-user.ps1 -Username admin -Password admin123
```

---

### Step 3: Test Admin Login

```bash
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' | jq '.'
```

**Expected Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "admin",
    "username": "admin",
    "email": "admin@radius.local",
    "role": "admin",
    "account_balance": "0.00",
    "phone_number": null
  }
}
```

---

### Step 4: Test Admin Access

```bash
# Save the token from login response
TOKEN="1|abc123..."

# Test admin endpoint
curl -X GET http://localhost/api/routers \
  -H "Authorization: Bearer $TOKEN" | jq '.'

# Test user management
curl -X GET http://localhost/api/users \
  -H "Authorization: Bearer $TOKEN" | jq '.'
```

---

### Step 5: Simulate Hotspot User Purchase

```bash
# 1. View available packages (public)
curl -X GET http://localhost/api/packages | jq '.'

# 2. Initiate payment
curl -X POST http://localhost/api/payments/initiate \
  -H "Content-Type: application/json" \
  -d '{
    "package_id": 1,
    "phone_number": "+254712345678",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }' | jq '.'

# 3. Simulate M-Pesa callback (for testing)
# Note: Replace CheckoutRequestID with actual value from step 2
curl -X POST http://localhost/api/mpesa/callback \
  -H "Content-Type: application/json" \
  -d '{
    "Body": {
      "stkCallback": {
        "CheckoutRequestID": "ws_CO_04102025...",
        "ResultCode": 0,
        "ResultDesc": "The service request is processed successfully.",
        "CallbackMetadata": {
          "Item": [
            {"Name": "Amount", "Value": 100},
            {"Name": "MpesaReceiptNumber", "Value": "ABC123"},
            {"Name": "PhoneNumber", "Value": 254712345678}
          ]
        }
      }
    }
  }' | jq '.'
```

---

### Step 6: Verify User Creation

```bash
# Check if hotspot user was created
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, username, role, phone_number FROM users WHERE role='hotspot_user';"

# Check subscription was created
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, user_id, package_id, status, mikrotik_username FROM user_subscriptions;"

# Check RADIUS entry was created
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT username, value FROM radcheck WHERE username LIKE 'user_%';"
```

---

## 🔐 User Types

### Admin User
- **Created via:** RADIUS (radcheck table)
- **Login:** Username/Password
- **Access:** Full system (routers, packages, users, logs)
- **Token Abilities:** `['*']` (all)

### Hotspot User
- **Created via:** Payment callback
- **Username:** Generated from phone (e.g., `user_254712345678`)
- **Access:** Own subscriptions, usage, packages
- **Token Abilities:** `['read-packages', 'purchase-package', 'view-subscription']`

---

## 📊 Common Tasks

### View All Users
```bash
curl -X GET http://localhost/api/users \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '.'
```

### Deactivate User
```bash
curl -X PUT http://localhost/api/users/2/deactivate \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '.'
```

### View All Payments
```bash
curl -X GET http://localhost/api/payments \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '.'
```

### View All Subscriptions
```bash
curl -X GET http://localhost/api/subscriptions \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '.'
```

---

## 🐛 Troubleshooting

### Issue: Login returns 401
```bash
# Check RADIUS user exists
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT * FROM radcheck WHERE username='admin';"

# Check FreeRADIUS logs
docker logs traidnet-freeradius --tail 50
```

### Issue: Permission Denied (403)
```bash
# Check user role
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT username, role, is_active FROM users WHERE username='admin';"

# Ensure user is active
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "UPDATE users SET is_active = TRUE WHERE username='admin';"
```

### Issue: User not provisioned after payment
```bash
# Check payment status
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, status, phone_number, amount FROM payments ORDER BY created_at DESC LIMIT 5;"

# Check Laravel logs
docker exec traidnet-backend tail -50 /var/www/html/storage/logs/laravel.log
```

---

## 📱 Frontend Integration

### Login Component
```javascript
// Check user role after login
const login = async (username, password) => {
  const response = await axios.post('/api/login', { username, password });
  const { token, user } = response.data;
  
  // Store token
  localStorage.setItem('authToken', token);
  localStorage.setItem('userRole', user.role);
  
  // Redirect based on role
  if (user.role === 'admin') {
    router.push('/admin/dashboard');
  } else {
    router.push('/portal/packages');
  }
};
```

### Protected Routes
```javascript
// Admin route guard
router.beforeEach((to, from, next) => {
  const userRole = localStorage.getItem('userRole');
  
  if (to.meta.requiresAdmin && userRole !== 'admin') {
    next('/unauthorized');
  } else {
    next();
  }
});
```

---

## 🔄 Complete Test Flow

```bash
#!/bin/bash

echo "=== WiFi Hotspot User Roles Test ==="

# 1. Create admin
echo "1. Creating admin user..."
./scripts/create-radius-user.sh -u admin -p admin123

# 2. Admin login
echo "2. Testing admin login..."
ADMIN_RESPONSE=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}')

ADMIN_TOKEN=$(echo $ADMIN_RESPONSE | jq -r '.token')
echo "Admin token: ${ADMIN_TOKEN:0:50}..."

# 3. Test admin access
echo "3. Testing admin access to routers..."
curl -s -X GET http://localhost/api/routers \
  -H "Authorization: Bearer $ADMIN_TOKEN" | jq '.success'

# 4. Initiate hotspot user payment
echo "4. Initiating hotspot user payment..."
PAYMENT_RESPONSE=$(curl -s -X POST http://localhost/api/payments/initiate \
  -H "Content-Type: application/json" \
  -d '{
    "package_id": 1,
    "phone_number": "+254712345678",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }')

CHECKOUT_ID=$(echo $PAYMENT_RESPONSE | jq -r '.checkout_request_id')
echo "Checkout ID: $CHECKOUT_ID"

# 5. Simulate callback
echo "5. Simulating M-Pesa callback..."
curl -s -X POST http://localhost/api/mpesa/callback \
  -H "Content-Type: application/json" \
  -d "{
    \"Body\": {
      \"stkCallback\": {
        \"CheckoutRequestID\": \"$CHECKOUT_ID\",
        \"ResultCode\": 0,
        \"ResultDesc\": \"Success\"
      }
    }
  }" | jq '.success'

# 6. Verify user created
echo "6. Verifying hotspot user created..."
sleep 2
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c \
  "SELECT COUNT(*) FROM users WHERE role='hotspot_user';"

# 7. Verify subscription created
echo "7. Verifying subscription created..."
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c \
  "SELECT COUNT(*) FROM user_subscriptions;"

echo "=== Test Complete ==="
```

---

## 📚 Documentation

- **Full Documentation:** `docs/USER_ROLES_AND_FLOW.md`
- **Implementation Summary:** `docs/IMPLEMENTATION_SUMMARY.md`
- **Troubleshooting:** `docs/TROUBLESHOOTING_GUIDE.md`
- **Testing Strategy:** `docs/TESTING_STRATEGY.md`

---

## ✅ Checklist

- [ ] System restarted with new schema
- [ ] Admin user created
- [ ] Admin login successful
- [ ] Admin can access protected routes
- [ ] Hotspot user payment flow tested
- [ ] User provisioning verified
- [ ] RADIUS entry created
- [ ] Frontend updated (if applicable)

---

**Ready to Deploy!** 🚀
