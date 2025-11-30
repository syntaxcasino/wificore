# End-to-End Test Suite

## Overview
Comprehensive end-to-end tests for the WiFi Hotspot Management System covering both admin and hotspot user workflows.

## Test Files

### Windows (PowerShell)
1. **run-all-e2e-tests.ps1** - Master test runner (runs all tests)
2. **e2e-admin-test.ps1** - Admin user workflow tests
3. **e2e-hotspot-user-test.ps1** - Hotspot user workflow tests

### Linux (Bash)
1. **run-all-e2e-tests.sh** - Master test runner (runs all tests)
2. **e2e-admin-test.sh** - Admin user workflow tests
3. **e2e-hotspot-user-test.sh** - Hotspot user workflow tests

## Prerequisites

1. **Docker containers running:**
   ```bash
   # Linux/macOS
   docker-compose up -d
   
   # Windows
   docker-compose up -d
   ```

2. **Wait for services to be ready:**
   ```bash
   # Linux/macOS
   sleep 30
   
   # Windows
   Start-Sleep -Seconds 30
   ```

3. **Verify containers are healthy:**
   ```bash
   docker ps
   ```

## Running Tests

### Linux/macOS

**Run All Tests (Recommended):**
```bash
# Make scripts executable
chmod +x tests/*.sh

# Run all tests
./tests/run-all-e2e-tests.sh
```

**Run Individual Tests:**
```bash
# Admin tests only
./tests/e2e-admin-test.sh

# Hotspot user tests only
./tests/e2e-hotspot-user-test.sh
```

### Windows

**Run All Tests (Recommended):**
```powershell
powershell -ExecutionPolicy Bypass -File .\tests\run-all-e2e-tests.ps1
```

**Run Individual Tests:**
```powershell
# Admin tests only
powershell -ExecutionPolicy Bypass -File .\tests\e2e-admin-test.ps1

# Hotspot user tests only
powershell -ExecutionPolicy Bypass -File .\tests\e2e-hotspot-user-test.ps1
```

## What Gets Tested

### Admin User Tests (12 tests)

1. ✅ Admin user exists in RADIUS
2. ✅ Admin login via RADIUS
3. ✅ Access admin-only endpoint (routers)
4. ✅ View all users
5. ✅ View all payments
6. ✅ View all subscriptions
7. ✅ View packages
8. ✅ Queue workers are running
9. ✅ Check queue system
10. ✅ View admin profile
11. ✅ Admin logout
12. ✅ Verify token is revoked after logout

### Hotspot User Tests (12 tests)

1. ✅ View available packages (public)
2. ✅ Initiate M-Pesa payment
3. ✅ Create payment record
4. ✅ Simulate M-Pesa callback
5. ✅ Wait for queue to process payment
6. ✅ Verify hotspot user was created
7. ✅ Verify subscription was created
8. ✅ Verify RADIUS entry was created
9. ✅ Verify queue jobs were processed
10. ✅ Test returning user (second purchase)
11. ✅ Verify returning user wasn't duplicated
12. ✅ Check queue worker logs for errors

## Expected Output

### Successful Run

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
  [OK] Table 'users' exists
  [OK] Table 'packages' exists
  ...

[OK] All pre-flight checks passed!

╔════════════════════════════════════════════════════════╗
║              RUNNING ADMIN USER TESTS                  ║
╚════════════════════════════════════════════════════════╝

[1] Testing: Admin user exists in RADIUS
    ✅ PASSED

[2] Testing: Admin login via RADIUS
    Token: 1|abc123...
    Role: admin
    ✅ PASSED

...

╔════════════════════════════════════════════════════════╗
║                  TEST RESULTS SUMMARY                  ║
╚════════════════════════════════════════════════════════╝

Total Tests:  12
Passed:       12
Failed:       0
Success Rate: 100%

🎉 ALL ADMIN TESTS PASSED! 🎉

╔════════════════════════════════════════════════════════╗
║           RUNNING HOTSPOT USER TESTS                   ║
╚════════════════════════════════════════════════════════╝

...

╔════════════════════════════════════════════════════════╗
║              OVERALL TEST RESULTS                      ║
╚════════════════════════════════════════════════════════╝

Test Suite Results:
  Admin User Tests:     [PASSED]
  Hotspot User Tests:   [PASSED]

Execution Time: 45.23 seconds

╔════════════════════════════════════════════════════════╗
║                                                        ║
║           ALL TESTS PASSED!                            ║
║                                                        ║
║     System is ready for production deployment!        ║
║                                                        ║
╚════════════════════════════════════════════════════════╝
```

## Troubleshooting

### Tests Fail to Run

**Issue:** PowerShell execution policy
```powershell
# Solution: Run with bypass flag
powershell -ExecutionPolicy Bypass -File .\tests\run-all-e2e-tests.ps1
```

### Containers Not Running

```powershell
# Check container status
docker ps

# Start containers
docker-compose up -d

# Wait for services
Start-Sleep -Seconds 30
```

### Database Connection Fails

```powershell
# Check database logs
docker logs traidnet-postgres --tail 50

# Restart database
docker-compose restart traidnet-postgres
```

### API Not Responding

```powershell
# Check backend logs
docker logs traidnet-backend --tail 50

# Check nginx logs
docker logs traidnet-nginx --tail 50

# Restart services
docker-compose restart
```

### Queue Workers Not Running

```powershell
# Check supervisor status
docker exec traidnet-backend supervisorctl status

# Restart workers
docker exec traidnet-backend supervisorctl restart laravel-queues:*
```

## Test Data Cleanup

The hotspot user test automatically cleans up test data after completion. If cleanup fails:

```powershell
# Manual cleanup
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "
DELETE FROM user_subscriptions WHERE user_id IN (SELECT id FROM users WHERE phone_number LIKE '+25471234%');
DELETE FROM payments WHERE phone_number LIKE '+25471234%';
DELETE FROM radcheck WHERE username LIKE 'user_25471234%';
DELETE FROM users WHERE phone_number LIKE '+25471234%';
"
```

## Continuous Integration

### GitHub Actions Example

```yaml
name: E2E Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: windows-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Start services
        run: docker-compose up -d
      
      - name: Wait for services
        run: Start-Sleep -Seconds 30
      
      - name: Run E2E tests
        run: powershell -ExecutionPolicy Bypass -File .\tests\run-all-e2e-tests.ps1
      
      - name: Cleanup
        if: always()
        run: docker-compose down
```

## Performance Benchmarks

**Expected execution times:**
- Pre-flight checks: ~5 seconds
- Admin tests: ~15-20 seconds
- Hotspot user tests: ~20-30 seconds
- **Total: ~40-55 seconds**

## Notes

- Tests use unique timestamps to avoid conflicts
- Test data is automatically cleaned up
- Tests can be run multiple times safely
- Queue processing adds ~10 seconds to hotspot user tests
- M-Pesa integration is mocked in test environment

---

**Last Updated:** 2025-10-04  
**Version:** 1.0
