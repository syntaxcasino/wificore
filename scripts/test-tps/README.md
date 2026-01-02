# WiFi Hotspot TPS Testing Suite

This directory contains scripts for testing the Transactions Per Second (TPS) of the WiFi Hotspot Management System. The test suite is designed to work with the Dockerized environment.

## Prerequisites

1. Docker and Docker Compose
2. `k6` for API load testing
3. `radclient` for RADIUS authentication testing (will be installed automatically if not present)
4. `psql` for database testing

## Test Scripts

### 1. `run-tests.sh`
The main script that runs all TPS tests in sequence.

### 2. `test-api-load.sh`
Tests the API endpoints for performance and TPS.

### 3. `test-db-performance.sh`
Tests the PostgreSQL database performance with various queries and transactions.

### 4. `test-radius-load.sh`
Tests the RADIUS authentication server with concurrent connections.

## How to Run

### Run All Tests

```bash
# Make the script executable
chmod +x scripts/test-tps/run-tests.sh

# Run all tests
./scripts/test-tps/run-tests.sh
```

### Run Individual Tests

```bash
# API Load Test
./scripts/test-tps/test-api-load.sh

# Database Performance Test
./scripts/test-tps/test-db-performance.sh

# RADIUS Load Test
./scripts/test-tps/test-radius-load.sh
```

## Configuration

You can configure the tests by setting environment variables in your `.env` file or passing them directly:

```bash
# Example: Run with custom parameters
TEST_USERS=200 TEST_ITERATIONS=5 ./scripts/test-tps/run-tests.sh
```

### Environment Variables

- `API_BASE_URL`: Base URL of the API (default: http://localhost/api)
- `DB_HOST`: Database host (default: traidnet-postgres)
- `DB_PORT`: Database port (default: 5432)
- `DB_NAME`: Database name (default: wifi_hotspot)
- `DB_USER`: Database user (default: admin)
- `DB_PASSWORD`: Database password (default: secret)
- `RADIUS_HOST`: RADIUS server host (default: traidnet-freeradius)
- `RADIUS_PORT`: RADIUS server port (default: 1812)
- `RADIUS_SECRET`: RADIUS shared secret (default: testing123)
- `TEST_USERS`: Number of test users to simulate (default: 100)
- `TEST_ITERATIONS`: Number of test iterations (default: 10)

## Test Results

Test results are saved in the `test-data` directory:

- `test-data/api/`: API load test results
- `test-data/db/`: Database performance test results
- `test-data/radius/`: RADIUS load test results and logs

## Troubleshooting

1. **Permission Denied**
   ```bash
   chmod +x scripts/test-tps/*.sh
   ```

2. **Docker Not Running**
   Make sure Docker is running and all services are up:
   ```bash
   docker-compose up -d
   ```

3. **radclient Not Found**
   Install freeradius-utils:
   ```bash
   # Ubuntu/Debian
   sudo apt-get install freeradius-utils
   
   # CentOS/RHEL
   sudo yum install freeradius-utils
   ```

4. **k6 Not Found**
   Install k6:
   ```bash
   # Ubuntu/Debian
   sudo apt-get install k6
   
   # macOS
   brew install k6
   
   # Or use Docker
   docker run -i loadimpact/k6 run - <script.js
   ```

## License

This project is licensed under the MIT License - see the [LICENSE](../LICENSE) file for details.
