# WiFi Hotspot Management System - Testing Commands (Linux/Bash)

## Overview
This document provides Linux/Bash equivalents of all testing commands for the WiFi Hotspot Management System. All commands are designed to work on Ubuntu/Debian and other Linux distributions.

---

## Table of Contents
1. [Prerequisites](#prerequisites)
2. [Container Health Checks](#container-health-checks)
3. [API Testing](#api-testing)
4. [Database Testing](#database-testing)
5. [RADIUS Testing](#radius-testing)
6. [Complete Test Scripts](#complete-test-scripts)
7. [Automated Testing](#automated-testing)

---

## Prerequisites

### Install Required Tools
```bash
# Update package list
sudo apt-get update

# Install curl for API testing
sudo apt-get install -y curl

# Install jq for JSON parsing
sudo apt-get install -y jq

# Install docker and docker-compose (if not already installed)
sudo apt-get install -y docker.io docker-compose

# Add user to docker group (to run docker without sudo)
sudo usermod -aG docker $USER
# Log out and back in for this to take effect
```

### Verify Installation
```bash
curl --version
jq --version
docker --version
docker-compose --version
```

---

## Container Health Checks

### Check All Containers
```bash
docker ps --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
```

### Check Specific Container
```bash
# Frontend
docker ps | grep traidnet-frontend

# Backend
docker ps | grep traidnet-backend

# Database
docker ps | grep traidnet-postgres

# FreeRADIUS
docker ps | grep traidnet-freeradius

# Nginx
docker ps | grep traidnet-nginx
```

### Check Container Logs
```bash
# View last 20 lines
docker logs traidnet-backend --tail 20

# Follow logs in real-time
docker logs -f traidnet-backend

# View logs with timestamps
docker logs -t traidnet-backend --tail 50

# Search logs for specific pattern
docker logs traidnet-backend | grep -i "error"
```

### Check Container Resource Usage
```bash
# All containers
docker stats --no-stream

# Specific container
docker stats traidnet-backend --no-stream
```

---

## API Testing

### Test 1: Packages Endpoint (Public)
```bash
# Simple GET request
curl -X GET http://localhost/api/packages

# With formatted JSON output
curl -s http://localhost/api/packages | jq '.'

# Check status code only
curl -s -o /dev/null -w "%{http_code}" http://localhost/api/packages

# Full response with headers
curl -i http://localhost/api/packages
```

**Expected Output:**
```json
[
  {
    "id": 1,
    "name": "Normal 1 Hour",
    "price": 1.00,
    "duration": "1 hour",
    ...
  }
]
```

---

### Test 2: Login (RADIUS Authentication)
```bash
# Login and get token
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' \
  | jq '.'

# Save response to variable
RESPONSE=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}')

# Extract token
TOKEN=$(echo $RESPONSE | jq -r '.token')
echo "Token: $TOKEN"

# Extract user info
echo $RESPONSE | jq '.user'
```

**Expected Output:**
```json
{
  "success": true,
  "message": "Login successful",
  "token": "1|G8Ggjlie5QccmY4aifx01edIARqZm8pk1rMI76L6b42a91b5",
  "user": {
    "id": 1,
    "username": "admin",
    "email": "admin@radius.local"
  }
}
```

---

### Test 3: Authenticated Request
```bash
# Login and get token
TOKEN=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' \
  | jq -r '.token')

# Use token for authenticated request
curl -X GET http://localhost/api/routers \
  -H "Authorization: Bearer $TOKEN" \
  | jq '.'

# Check if authentication is required (should return 401)
curl -s -o /dev/null -w "%{http_code}" http://localhost/api/routers
```

---

### Test 4: Logout
```bash
# Login first
TOKEN=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' \
  | jq -r '.token')

# Logout
curl -X POST http://localhost/api/logout \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  | jq '.'
```

---

### Test 5: Error Handling
```bash
# Test with wrong credentials
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"wrongpass"}' \
  | jq '.'

# Test with missing fields
curl -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin"}' \
  | jq '.'

# Get detailed error information
curl -i -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"wrongpass"}'
```

---

## Database Testing

### Connect to Database
```bash
# Interactive psql session
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

# Run single query
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "SELECT version();"
```

### Check Tables
```bash
# List all tables
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\dt"

# List RADIUS tables
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT tablename FROM pg_tables WHERE schemaname='public' AND tablename LIKE 'rad%';"

# Check table structure
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c "\d users"
```

### Check RADIUS Users
```bash
# List all RADIUS users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, username, attribute, value FROM radcheck;"

# Check specific user
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT * FROM radcheck WHERE username='admin';"

# Count users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT COUNT(*) FROM radcheck;"
```

### Check Laravel Users
```bash
# List all users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, username, name, email, created_at FROM users;"

# Check specific user
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT * FROM users WHERE username='admin';"
```

### Check Sanctum Tokens
```bash
# List all tokens
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, tokenable_id, name, abilities, created_at FROM personal_access_tokens;"

# Check tokens for specific user
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT pat.id, pat.name, u.username, pat.created_at 
   FROM personal_access_tokens pat 
   JOIN users u ON u.id = pat.tokenable_id 
   WHERE u.username='admin';"
```

### Check Packages
```bash
# List all packages
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, name, price, duration, upload_speed, download_speed FROM packages;"

# Count packages
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT COUNT(*) FROM packages;"
```

### Database Maintenance
```bash
# Check database size
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT pg_size_pretty(pg_database_size('wifi_hotspot'));"

# Check active connections
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT count(*) FROM pg_stat_activity WHERE datname='wifi_hotspot';"

# Check table sizes
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT tablename, pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size 
   FROM pg_tables WHERE schemaname='public' ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;"
```

---

## RADIUS Testing

### Check FreeRADIUS Status
```bash
# Check if FreeRADIUS is running
docker ps | grep traidnet-freeradius

# Check FreeRADIUS logs
docker logs traidnet-freeradius --tail 50

# Check if listening on correct ports
docker logs traidnet-freeradius | grep "Listening on"
```

**Expected Output:**
```
Listening on auth address * port 1812
Listening on acct address * port 1813
```

### Test RADIUS Authentication Flow
```bash
# Clear logs and test
docker-compose restart traidnet-freeradius
sleep 10

# Trigger login
curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}' > /dev/null

# Check RADIUS logs for authentication
docker logs traidnet-freeradius --tail 100 | grep -E "admin|Access-Accept|Access-Reject|Cleartext-Password"
```

**Expected in Logs:**
```
(0) sql: Cleartext-Password := "admin123"
(0) Sent Access-Accept
```

### Check RADIUS SQL Queries
```bash
# Check what SQL queries are being executed
docker logs traidnet-freeradius --tail 200 | grep -E "SELECT.*radcheck" -A 2 -B 2

# Check SQL module status
docker logs traidnet-freeradius | grep -i "sql" | head -20
```

### Check RADIUS Configuration
```bash
# Check SQL module config
docker exec traidnet-freeradius cat /opt/etc/raddb/mods-enabled/sql | head -50

# Check queries config
docker exec traidnet-freeradius cat /opt/etc/raddb/mods-config/sql/main/postgresql/queries.conf | head -20

# Check default site config
docker exec traidnet-freeradius cat /opt/etc/raddb/sites-enabled/default | grep -E "authorize|sql" -A 3 -B 3
```

### Test RADIUS SQL Query Directly
```bash
# Test the exact query FreeRADIUS uses
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "SELECT id, username, attribute, value, op FROM radcheck WHERE username = 'admin' ORDER BY id;"
```

---

## Complete Test Scripts

### Script 1: Quick Health Check
Save as `tests/health-check.sh`:

```bash
#!/bin/bash

echo "=== WiFi Hotspot System Health Check ==="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

PASSED=0
FAILED=0

# Function to run test
run_test() {
    local test_name=$1
    local test_command=$2
    
    echo -n "Testing $test_name... "
    
    if eval "$test_command" > /dev/null 2>&1; then
        echo -e "${GREEN}✓ PASS${NC}"
        ((PASSED++))
        return 0
    else
        echo -e "${RED}✗ FAIL${NC}"
        ((FAILED++))
        return 1
    fi
}

# Container health checks
echo "=== Container Health ==="
run_test "Frontend Container" "docker ps | grep traidnet-frontend | grep -q healthy"
run_test "Backend Container" "docker ps | grep traidnet-backend | grep -q healthy"
run_test "Database Container" "docker ps | grep traidnet-postgres | grep -q healthy"
run_test "FreeRADIUS Container" "docker ps | grep traidnet-freeradius | grep -q healthy"
run_test "Nginx Container" "docker ps | grep traidnet-nginx | grep -q healthy"

echo ""
echo "=== API Endpoints ==="
run_test "Packages Endpoint" "curl -s -f http://localhost/api/packages > /dev/null"
run_test "Login Endpoint" "curl -s -X POST http://localhost/api/login -H 'Content-Type: application/json' -d '{\"username\":\"admin\",\"password\":\"admin123\"}' | grep -q '\"success\":true'"

echo ""
echo "=== Database ==="
run_test "Database Connection" "docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c 'SELECT 1;' > /dev/null"
run_test "RADIUS User Exists" "docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \"SELECT COUNT(*) FROM radcheck WHERE username='admin';\" | grep -q '1'"
run_test "Packages Exist" "docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c 'SELECT COUNT(*) FROM packages;' | grep -q '[1-9]'"

echo ""
echo "=== FreeRADIUS ==="
run_test "FreeRADIUS Listening" "docker logs traidnet-freeradius 2>&1 | grep -q 'Listening on auth address.*port 1812'"
run_test "SQL Module Loaded" "docker logs traidnet-freeradius 2>&1 | grep -q 'Instantiating module.*sql'"

echo ""
echo "=== Summary ==="
echo -e "Passed: ${GREEN}$PASSED${NC}"
echo -e "Failed: ${RED}$FAILED${NC}"
echo "Total: $((PASSED + FAILED))"

if [ $FAILED -eq 0 ]; then
    echo -e "\n${GREEN}🎉 All tests passed!${NC}"
    exit 0
else
    echo -e "\n${RED}❌ Some tests failed${NC}"
    exit 1
fi
```

**Make executable and run:**
```bash
chmod +x tests/health-check.sh
./tests/health-check.sh
```

---

### Script 2: End-to-End Authentication Test
Save as `tests/e2e-auth-test.sh`:

```bash
#!/bin/bash

echo "=== End-to-End Authentication Test ==="
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# Step 1: Clear test data
echo -e "${CYAN}[1/6] Clearing test data...${NC}"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "DELETE FROM users WHERE username='testuser';" > /dev/null 2>&1
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "DELETE FROM personal_access_tokens WHERE tokenable_id NOT IN (SELECT id FROM users);" > /dev/null 2>&1

# Step 2: Create RADIUS user
echo -e "${CYAN}[2/6] Creating RADIUS user...${NC}"
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -c \
  "INSERT INTO radcheck (username, attribute, op, value) VALUES ('testuser', 'Cleartext-Password', ':=', 'testpass123') ON CONFLICT DO NOTHING;" > /dev/null

# Step 3: Test login
echo -e "${CYAN}[3/6] Testing login...${NC}"
RESPONSE=$(curl -s -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"testuser","password":"testpass123"}')

SUCCESS=$(echo $RESPONSE | jq -r '.success')
TOKEN=$(echo $RESPONSE | jq -r '.token')

if [ "$SUCCESS" = "true" ] && [ ! -z "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
    echo -e "${GREEN}✓ Login successful${NC}"
    echo "  Token: ${TOKEN:0:50}..."
else
    echo -e "${RED}✗ Login failed${NC}"
    echo "  Response: $RESPONSE"
    exit 1
fi

# Step 4: Verify user created
echo -e "${CYAN}[4/6] Verifying user created...${NC}"
USER_COUNT=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c \
  "SELECT COUNT(*) FROM users WHERE username='testuser';" | tr -d ' ')

if [ "$USER_COUNT" = "1" ]; then
    echo -e "${GREEN}✓ User created in database${NC}"
else
    echo -e "${RED}✗ User not found${NC}"
    exit 1
fi

# Step 5: Test authenticated request
echo -e "${CYAN}[5/6] Testing authenticated request...${NC}"
AUTH_RESPONSE=$(curl -s -o /dev/null -w "%{http_code}" \
  -H "Authorization: Bearer $TOKEN" \
  http://localhost/api/routers)

if [ "$AUTH_RESPONSE" = "200" ]; then
    echo -e "${GREEN}✓ Authenticated request successful${NC}"
else
    echo -e "${RED}✗ Authenticated request failed (Status: $AUTH_RESPONSE)${NC}"
    exit 1
fi

# Step 6: Test logout
echo -e "${CYAN}[6/6] Testing logout...${NC}"
LOGOUT_RESPONSE=$(curl -s -X POST http://localhost/api/logout \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json")

LOGOUT_SUCCESS=$(echo $LOGOUT_RESPONSE | jq -r '.success')

if [ "$LOGOUT_SUCCESS" = "true" ]; then
    echo -e "${GREEN}✓ Logout successful${NC}"
else
    echo -e "${RED}✗ Logout failed${NC}"
    exit 1
fi

echo ""
echo -e "${GREEN}🎉 E2E Test PASSED!${NC}"
```

**Make executable and run:**
```bash
chmod +x tests/e2e-auth-test.sh
./tests/e2e-auth-test.sh
```

---

### Script 3: Performance Test
Save as `tests/performance-test.sh`:

```bash
#!/bin/bash

echo "=== Performance Test ==="
echo ""

# Test 1: Response time for packages endpoint
echo "Testing packages endpoint response time..."
RESPONSE_TIME=$(curl -s -o /dev/null -w "%{time_total}" http://localhost/api/packages)
echo "Response time: ${RESPONSE_TIME}s"

if (( $(echo "$RESPONSE_TIME < 0.5" | bc -l) )); then
    echo "✓ Response time acceptable"
else
    echo "⚠ Response time slower than expected"
fi

# Test 2: Response time for login
echo ""
echo "Testing login endpoint response time..."
LOGIN_TIME=$(curl -s -o /dev/null -w "%{time_total}" \
  -X POST http://localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"username":"admin","password":"admin123"}')
echo "Response time: ${LOGIN_TIME}s"

if (( $(echo "$LOGIN_TIME < 2.0" | bc -l) )); then
    echo "✓ Response time acceptable"
else
    echo "⚠ Response time slower than expected"
fi

# Test 3: Concurrent requests
echo ""
echo "Testing concurrent requests (10 simultaneous)..."
START_TIME=$(date +%s)

for i in {1..10}; do
    curl -s http://localhost/api/packages > /dev/null &
done

wait

END_TIME=$(date +%s)
DURATION=$((END_TIME - START_TIME))

echo "Completed 10 concurrent requests in ${DURATION}s"

if [ $DURATION -lt 5 ]; then
    echo "✓ Concurrent request handling acceptable"
else
    echo "⚠ Concurrent request handling slower than expected"
fi

# Test 4: Database connections
echo ""
echo "Checking database connection pool..."
CONNECTIONS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c \
  "SELECT count(*) FROM pg_stat_activity WHERE datname='wifi_hotspot';" | tr -d ' ')
MAX_CONNECTIONS=$(docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c \
  "SHOW max_connections;" | tr -d ' ')

echo "Active connections: $CONNECTIONS / $MAX_CONNECTIONS"

if [ $CONNECTIONS -lt $((MAX_CONNECTIONS / 2)) ]; then
    echo "✓ Connection pool healthy"
else
    echo "⚠ Connection pool usage high"
fi
```

**Make executable and run:**
```bash
chmod +x tests/performance-test.sh
./tests/performance-test.sh
```

---

### Script 4: Complete Test Suite
Save as `tests/run-all-tests.sh`:

```bash
#!/bin/bash

echo "╔════════════════════════════════════════════════════════╗"
echo "║  WiFi Hotspot Management System - Test Suite Runner   ║"
echo "╚════════════════════════════════════════════════════════╝"
echo ""

# Colors
GREEN='\033[0;32m'
RED='\033[0;31m'
CYAN='\033[0;36m'
NC='\033[0m'

PASSED=0
FAILED=0

# Run health check
echo -e "${CYAN}=== Running Health Check ===${NC}"
if ./tests/health-check.sh; then
    ((PASSED++))
else
    ((FAILED++))
fi

echo ""

# Run E2E test
echo -e "${CYAN}=== Running E2E Authentication Test ===${NC}"
if ./tests/e2e-auth-test.sh; then
    ((PASSED++))
else
    ((FAILED++))
fi

echo ""

# Run performance test
echo -e "${CYAN}=== Running Performance Test ===${NC}"
if ./tests/performance-test.sh; then
    ((PASSED++))
else
    ((FAILED++))
fi

echo ""
echo "╔════════════════════════════════════════╗"
echo "║          TEST RESULTS SUMMARY          ║"
echo "╚════════════════════════════════════════╝"
echo -e "Test Suites Passed: ${GREEN}$PASSED${NC}"
echo -e "Test Suites Failed: ${RED}$FAILED${NC}"
echo "Total Test Suites: $((PASSED + FAILED))"

if [ $FAILED -eq 0 ]; then
    echo -e "\n${GREEN}🎉 ALL TEST SUITES PASSED!${NC}"
    exit 0
else
    echo -e "\n${RED}❌ SOME TEST SUITES FAILED${NC}"
    exit 1
fi
```

**Make executable and run:**
```bash
chmod +x tests/run-all-tests.sh
./tests/run-all-tests.sh
```

---

## Automated Testing

### Cron Job for Regular Testing
```bash
# Edit crontab
crontab -e

# Add line to run tests every hour
0 * * * * cd /path/to/wifi-hotspot && ./tests/health-check.sh >> /var/log/hotspot-tests.log 2>&1

# Run tests every day at 2 AM
0 2 * * * cd /path/to/wifi-hotspot && ./tests/run-all-tests.sh >> /var/log/hotspot-tests.log 2>&1
```

### Systemd Service for Monitoring
Save as `/etc/systemd/system/hotspot-monitor.service`:

```ini
[Unit]
Description=WiFi Hotspot Health Monitor
After=docker.service

[Service]
Type=oneshot
WorkingDirectory=/path/to/wifi-hotspot
ExecStart=/path/to/wifi-hotspot/tests/health-check.sh
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

Save as `/etc/systemd/system/hotspot-monitor.timer`:

```ini
[Unit]
Description=Run WiFi Hotspot Health Check every 15 minutes

[Timer]
OnBootSec=5min
OnUnitActiveSec=15min

[Install]
WantedBy=timers.target
```

**Enable and start:**
```bash
sudo systemctl daemon-reload
sudo systemctl enable hotspot-monitor.timer
sudo systemctl start hotspot-monitor.timer

# Check status
sudo systemctl status hotspot-monitor.timer

# View logs
sudo journalctl -u hotspot-monitor.service
```

---

## Docker Management Commands

### Start/Stop Services
```bash
# Start all services
docker-compose up -d

# Stop all services
docker-compose down

# Restart specific service
docker-compose restart traidnet-backend

# View service status
docker-compose ps
```

### Rebuild Services
```bash
# Rebuild specific service
docker-compose build traidnet-backend

# Rebuild without cache
docker-compose build --no-cache traidnet-backend

# Rebuild and restart
docker-compose up -d --build traidnet-backend
```

### Clean Up
```bash
# Stop and remove containers
docker-compose down

# Stop and remove containers with volumes (⚠️ DELETES DATA)
docker-compose down -v

# Remove unused images
docker image prune -a

# Remove all stopped containers
docker container prune

# Complete cleanup
docker system prune -a --volumes
```

---

## Debugging Commands

### View Real-time Logs
```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f traidnet-backend

# Multiple services
docker-compose logs -f traidnet-backend traidnet-freeradius

# Last 100 lines
docker-compose logs --tail=100 traidnet-backend
```

### Execute Commands in Containers
```bash
# Backend shell
docker exec -it traidnet-backend bash

# Database shell
docker exec -it traidnet-postgres psql -U admin -d wifi_hotspot

# FreeRADIUS shell
docker exec -it traidnet-freeradius sh

# Run Laravel artisan command
docker exec traidnet-backend php artisan route:list
```

### Network Debugging
```bash
# Check container network
docker network inspect traidnet-network

# Check container IP
docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' traidnet-backend

# Test connectivity between containers
docker exec traidnet-backend ping traidnet-postgres

# Check open ports
docker exec traidnet-backend netstat -tulpn
```

---

## Useful One-Liners

### Quick Status Check
```bash
# Check if all containers are healthy
docker ps --filter "name=traidnet" --format "{{.Names}}: {{.Status}}" | grep -v healthy && echo "Some containers unhealthy" || echo "All containers healthy"
```

### Quick API Test
```bash
# Test login and extract token in one line
TOKEN=$(curl -s -X POST http://localhost/api/login -H "Content-Type: application/json" -d '{"username":"admin","password":"admin123"}' | jq -r '.token') && echo "Token: $TOKEN"
```

### Quick Database Query
```bash
# Count all users
docker exec traidnet-postgres psql -U admin -d wifi_hotspot -t -c "SELECT COUNT(*) FROM users;" | tr -d ' '
```

### Quick Log Search
```bash
# Find errors in backend logs
docker logs traidnet-backend 2>&1 | grep -i error | tail -20

# Find RADIUS authentication attempts
docker logs traidnet-freeradius 2>&1 | grep -E "Access-Accept|Access-Reject" | tail -10
```

### Quick Restart
```bash
# Restart all services
docker-compose restart && sleep 30 && ./tests/health-check.sh
```

---

## Environment Variables

### Set Testing Variables
```bash
# Add to ~/.bashrc or ~/.zshrc
export HOTSPOT_URL="http://localhost"
export HOTSPOT_API_URL="http://localhost/api"
export HOTSPOT_TEST_USER="admin"
export HOTSPOT_TEST_PASS="admin123"

# Reload shell
source ~/.bashrc
```

### Use in Scripts
```bash
# Login using environment variables
curl -X POST ${HOTSPOT_API_URL}/login \
  -H "Content-Type: application/json" \
  -d "{\"username\":\"${HOTSPOT_TEST_USER}\",\"password\":\"${HOTSPOT_TEST_PASS}\"}"
```

---

## CI/CD Integration

### GitLab CI Example
`.gitlab-ci.yml`:

```yaml
test:
  stage: test
  image: docker:latest
  services:
    - docker:dind
  before_script:
    - apk add --no-cache curl jq bash
    - docker-compose up -d
    - sleep 30
  script:
    - chmod +x tests/*.sh
    - ./tests/run-all-tests.sh
  after_script:
    - docker-compose down
```

### GitHub Actions Example
`.github/workflows/test.yml`:

```yaml
name: Test Suite

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    
    steps:
      - uses: actions/checkout@v2
      
      - name: Start services
        run: docker-compose up -d
      
      - name: Wait for services
        run: sleep 30
      
      - name: Run tests
        run: |
          chmod +x tests/*.sh
          ./tests/run-all-tests.sh
      
      - name: Cleanup
        run: docker-compose down
```

---

## Quick Reference

### Default Credentials
```bash
RADIUS_USER="admin"
RADIUS_PASS="admin123"
DB_USER="admin"
DB_PASS="secret"
DB_NAME="wifi_hotspot"
```

### Important URLs
```bash
FRONTEND="http://localhost"
API="http://localhost/api"
WEBSOCKET="ws://localhost:6001"
```

### Important Ports
```bash
HTTP=80
RADIUS_AUTH=1812
RADIUS_ACCT=1813
POSTGRES=5432
SOKETI=6001
PHP_FPM=9000
```

---

## Troubleshooting

### Common Issues

#### Issue: curl command not found
```bash
sudo apt-get update && sudo apt-get install -y curl
```

#### Issue: jq command not found
```bash
sudo apt-get update && sudo apt-get install -y jq
```

#### Issue: Permission denied for docker
```bash
sudo usermod -aG docker $USER
# Log out and back in
```

#### Issue: Port already in use
```bash
# Find process using port 80
sudo lsof -i :80

# Kill process
sudo kill -9 <PID>
```

#### Issue: Cannot connect to Docker daemon
```bash
# Start Docker service
sudo systemctl start docker

# Enable Docker on boot
sudo systemctl enable docker
```

---

**Last Updated:** 2025-10-04
**Version:** 1.0
**Platform:** Linux (Ubuntu/Debian)
