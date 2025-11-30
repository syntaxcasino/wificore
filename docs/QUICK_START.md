# Quick Start - E2E Tests

## 🚀 Run Tests in 3 Steps

### Linux/macOS

```bash
# 1. Make scripts executable
chmod +x tests/*.sh

# 2. Ensure containers are running
docker-compose up -d && sleep 30

# 3. Run all tests
./tests/run-all-e2e-tests.sh
```

### Windows

```powershell
# 1. Ensure containers are running
docker-compose up -d
Start-Sleep -Seconds 30

# 2. Run all tests
powershell -ExecutionPolicy Bypass -File .\tests\run-all-e2e-tests.ps1
```

---

## 📊 What Gets Tested

### ✅ Admin User Flow (12 tests)
- RADIUS authentication
- Role-based access control
- Admin dashboard access
- User/payment/subscription management
- Token lifecycle & security

### ✅ Hotspot User Flow (12 tests)
- Package viewing
- Payment processing (M-Pesa simulation)
- Queue-based user provisioning
- Subscription creation
- RADIUS entry creation
- Returning user handling

---

## 🎯 Expected Output

```
╔════════════════════════════════════════════════════════╗
║   WiFi Hotspot Management System - E2E Test Suite     ║
╚════════════════════════════════════════════════════════╝

═══ PRE-FLIGHT CHECKS ═══

[1/5] Checking Docker containers...
  [OK] traidnet-backend is running
  [OK] traidnet-postgres is running
  [OK] traidnet-freeradius is running
  [OK] traidnet-nginx is running

[2/5] Checking database connectivity...
  [OK] Database is accessible

[3/5] Checking API endpoint...
  [OK] API is responding (Status: 200)

[4/5] Checking queue workers...
  [OK] Queue workers are running (15 workers)

[5/5] Checking required tables...
  [OK] All tables exist

[OK] All pre-flight checks passed!

╔════════════════════════════════════════════════════════╗
║              RUNNING ADMIN USER TESTS                  ║
╚════════════════════════════════════════════════════════╝

[1] Testing: Admin user exists in RADIUS
    [PASSED]

[2] Testing: Admin login via RADIUS
    Token: 1|abc123...
    Role: admin
    [PASSED]

... (10 more tests)

Total Tests:  12
Passed:       12
Failed:       0
Success Rate: 100.00%

[SUCCESS] ALL ADMIN TESTS PASSED!

╔════════════════════════════════════════════════════════╗
║           RUNNING HOTSPOT USER TESTS                   ║
╚════════════════════════════════════════════════════════╝

Test Data:
  Phone: +254712345123456
  MAC: AA:BB:CC:DD:EE:12

[1] Testing: View available packages (public)
    Available packages: 4
    First package: Normal 1 Hour - KES 1.00
    [PASSED]

... (11 more tests)

Total Tests:  12
Passed:       12
Failed:       0
Success Rate: 100.00%

[SUCCESS] ALL HOTSPOT USER TESTS PASSED!

╔════════════════════════════════════════════════════════╗
║              OVERALL TEST RESULTS                      ║
╚════════════════════════════════════════════════════════╝

Test Suite Results:
  Admin User Tests:     [PASSED]
  Hotspot User Tests:   [PASSED]

Execution Time: 45 seconds

╔════════════════════════════════════════════════════════╗
║                                                        ║
║           ALL TESTS PASSED!                            ║
║                                                        ║
║     System is ready for production deployment!        ║
║                                                        ║
╚════════════════════════════════════════════════════════╝
```

---

## 🐛 Quick Troubleshooting

### Containers not running
```bash
docker-compose up -d
sleep 30
```

### Database connection fails
```bash
docker logs traidnet-postgres --tail 50
docker-compose restart traidnet-postgres
```

### API not responding
```bash
docker logs traidnet-backend --tail 50
docker logs traidnet-nginx --tail 50
docker-compose restart
```

### Queue workers not running
```bash
docker exec traidnet-backend supervisorctl status
docker exec traidnet-backend supervisorctl restart laravel-queues:*
```

---

## 📝 Individual Test Commands

### Linux/macOS

```bash
# Admin tests only
./tests/e2e-admin-test.sh

# Hotspot user tests only
./tests/e2e-hotspot-user-test.sh
```

### Windows

```powershell
# Admin tests only
powershell -ExecutionPolicy Bypass -File .\tests\e2e-admin-test.ps1

# Hotspot user tests only
powershell -ExecutionPolicy Bypass -File .\tests\e2e-hotspot-user-test.ps1
```

---

## ⏱️ Execution Time

- **Pre-flight checks:** ~5 seconds
- **Admin tests:** ~15-20 seconds
- **Hotspot user tests:** ~20-30 seconds
- **Total:** ~40-55 seconds

---

## 📚 Full Documentation

See `tests/README.md` for complete documentation.
