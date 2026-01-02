# Create Hotspot Test User Script

**File:** `create-hotspot-test-user.sh`  
**Location:** `scripts/create-hotspot-test-user.sh`

---

## 📋 Overview

Comprehensive bash script to create a complete hotspot test user with:
- Application database user account
- RADIUS authentication entry
- Optional active subscription
- Optional email verification bypass
- Custom MAC address support

---

## 🚀 Usage

### **Basic Usage (Quick Test User)**
```bash
./scripts/create-hotspot-test-user.sh
```

**Creates:**
- Username: `testuser`
- Password: `Test@123`
- Email: `testuser@example.com`
- Phone: `+254700000000`
- MAC: Random

---

### **Custom Credentials**
```bash
./scripts/create-hotspot-test-user.sh \
  -u john \
  -p Secret123 \
  -e john@test.com \
  -n +254712345678
```

---

### **With Active Subscription**
```bash
./scripts/create-hotspot-test-user.sh \
  -u premium \
  -p Test@123 \
  -k 1 \
  -s \
  -v
```

**Flags:**
- `-k 1` - Use package ID 1
- `-s` - Create active subscription
- `-v` - Skip email verification

---

### **Complete Example**
```bash
./scripts/create-hotspot-test-user.sh \
  --username testuser1 \
  --password MyPass@123 \
  --email test1@example.com \
  --phone +254700111222 \
  --mac AA:BB:CC:DD:EE:FF \
  --package 2 \
  --subscription \
  --verify
```

---

## 📖 Options

| Option | Short | Description | Default |
|--------|-------|-------------|---------|
| `--username` | `-u` | Username for hotspot login | `testuser` |
| `--password` | `-p` | Password | `Test@123` |
| `--email` | `-e` | Email address | `testuser@example.com` |
| `--phone` | `-n` | Phone number | `+254700000000` |
| `--mac` | `-m` | MAC address | Random |
| `--package` | `-k` | Package ID for subscription | None |
| `--subscription` | `-s` | Auto-create active subscription | `false` |
| `--verify` | `-v` | Skip email verification | `false` |
| `--help` | `-h` | Display help message | - |

---

## 🔍 What It Does

### **Step 1: Validation**
- Checks if user already exists in application DB
- Checks if username exists in RADIUS
- Prevents duplicate users

### **Step 2: Create Application User**
- Generates UUID for user ID
- Hashes password using Laravel bcrypt
- Creates user with role `hotspot_user`
- Optionally marks email as verified

### **Step 3: Create RADIUS User**
- Inserts user into `radcheck` table
- Sets `Cleartext-Password` attribute
- Enables RADIUS authentication

### **Step 4: Create Subscription (Optional)**
- Finds package by ID or uses first available
- Calculates end time based on package duration
- Creates active subscription
- Links MAC address to subscription

### **Step 5: Display Summary**
- Shows all credentials
- Provides access URLs
- Shows testing commands
- Displays verification status

---

## 📊 Output Example

```
╔════════════════════════════════════════════════════════════════╗
║         Creating Hotspot Test User                            ║
╚════════════════════════════════════════════════════════════════╝

Configuration:
  Username:        testuser
  Password:        Test@123
  Email:           testuser@example.com
  Phone:           +254700000000
  MAC Address:     AA:BB:CC:DD:EE:FF
  Create Sub:      true
  Skip Verify:     true

[1/5] Checking if user exists...
✅ User does not exist

[2/5] Checking RADIUS...
✅ RADIUS user does not exist

[3/5] Creating application user...
✅ Application user created successfully!

[4/5] Creating RADIUS user...
✅ RADIUS user created successfully!

[5/5] Creating active subscription...
✅ Active subscription created!

╔════════════════════════════════════════════════════════════════╗
║         Test User Created Successfully!                       ║
╚════════════════════════════════════════════════════════════════╝

✅ Hotspot test user created successfully!

═══════════════════════════════════════════════════════════════
Login Credentials:
═══════════════════════════════════════════════════════════════
  Username:        testuser
  Password:        Test@123
  Email:           testuser@example.com
  Phone:           +254700000000
  MAC Address:     AA:BB:CC:DD:EE:FF
  User ID:         550e8400-e29b-41d4-a716-446655440000

═══════════════════════════════════════════════════════════════
Access URLs:
═══════════════════════════════════════════════════════════════
  Hotspot Login:   http://localhost/login
  Admin Panel:     http://localhost/admin

✅ Active subscription created - user can login immediately!

═══════════════════════════════════════════════════════════════
Testing:
═══════════════════════════════════════════════════════════════
  Test RADIUS:
    radtest testuser Test@123 localhost 0 testing123

  View User:
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM users WHERE email='testuser@example.com';"

  View RADIUS:
    docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT * FROM radcheck WHERE username='testuser';"

Done! 🎉
```

---

## 🧪 Testing Commands

### **Test RADIUS Authentication**
```bash
radtest testuser Test@123 localhost 0 testing123
```

### **View Created User**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot \
  -c "SELECT id, name, email, role, email_verified_at FROM users WHERE email='testuser@example.com';"
```

### **View RADIUS Entry**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot \
  -c "SELECT * FROM radcheck WHERE username='testuser';"
```

### **View Subscription**
```bash
docker exec traidnet-postgres psql -U admin -d wifi_hotspot \
  -c "SELECT * FROM user_subscriptions WHERE mikrotik_username='testuser';"
```

### **Test Login via API**
```bash
curl -X POST http://localhost/api/hotspot/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testuser",
    "password": "Test@123",
    "mac_address": "AA:BB:CC:DD:EE:FF"
  }'
```

---

## 🎯 Use Cases

### **1. Quick Testing**
```bash
# Create basic test user
./scripts/create-hotspot-test-user.sh
```

### **2. E2E Testing**
```bash
# Create user with active subscription for immediate testing
./scripts/create-hotspot-test-user.sh -s -v
```

### **3. Multiple Test Users**
```bash
# Create multiple users with different credentials
./scripts/create-hotspot-test-user.sh -u user1 -e user1@test.com
./scripts/create-hotspot-test-user.sh -u user2 -e user2@test.com
./scripts/create-hotspot-test-user.sh -u user3 -e user3@test.com
```

### **4. Package Testing**
```bash
# Test different packages
./scripts/create-hotspot-test-user.sh -u basic -k 1 -s -v
./scripts/create-hotspot-test-user.sh -u premium -k 2 -s -v
./scripts/create-hotspot-test-user.sh -u unlimited -k 3 -s -v
```

### **5. MAC Address Testing**
```bash
# Test specific MAC addresses
./scripts/create-hotspot-test-user.sh -u device1 -m AA:BB:CC:DD:EE:01 -s -v
./scripts/create-hotspot-test-user.sh -u device2 -m AA:BB:CC:DD:EE:02 -s -v
```

---

## 🔧 Troubleshooting

### **User Already Exists**
```
❌ Error: User with email 'testuser@example.com' or username 'testuser' already exists!
```

**Solution:** Use different username/email or delete existing user:
```bash
./scripts/delete-radius-user.sh -u testuser
docker exec traidnet-postgres psql -U admin -d wifi_hotspot \
  -c "DELETE FROM users WHERE email='testuser@example.com';"
```

### **No Packages Found**
```
⚠️  No packages found. Skipping subscription creation.
```

**Solution:** Create packages first or don't use `-s` flag

### **Email Verification Required**
```
⚠️  Email verification required!
```

**Solution:** Use `-v` flag or run:
```bash
./scripts/bypass-email-verification.sh -e testuser@example.com
```

---

## 📝 Notes

- Script requires Docker containers to be running
- Uses `traidnet-postgres` and `traidnet-backend` containers
- Generates secure UUIDs for user IDs
- Hashes passwords using Laravel's bcrypt
- Creates users with `hotspot_user` role
- MAC addresses are validated format: `XX:XX:XX:XX:XX:XX`

---

## 🚀 Integration with CI/CD

### **In Test Scripts**
```bash
#!/bin/bash
# Setup test environment
./scripts/create-hotspot-test-user.sh -u testuser -s -v

# Run tests
npm run test:e2e

# Cleanup
docker exec traidnet-postgres psql -U admin -d wifi_hotspot \
  -c "DELETE FROM users WHERE email='testuser@example.com';"
```

---

## 📊 Related Scripts

- `create-radius-user.sh` - Create RADIUS user only
- `delete-radius-user.sh` - Delete RADIUS user
- `list-radius-users.sh` - List all RADIUS users
- `bypass-email-verification.sh` - Bypass email verification
- `update-radius-password.sh` - Update RADIUS password

---

**Created:** 2025-10-11  
**Author:** Cascade AI  
**Version:** 1.0.0
