---
description: How to run backend (Laravel Pest PHP) tests
---

## Prerequisites

The tests require:
- **Docker** installed and running
- **`wificore-postgres`** container running (the test database lives there)
- **`wificore-test-runner`** Docker image built from `backend/Dockerfile.test`
- Docker network **`wificore-network`** present

### 1. Verify the postgres container is up

```bash
docker ps --filter name=wificore-postgres
```

If it is not running, start the stack first:

```bash
docker compose -f /home/kja2aro/Projects/traidnet/wificore/docker-compose.yml up -d wificore-postgres
```

### 2. Build the test-runner image (one-time / after dependency changes)

```bash
docker build \
  -f /home/kja2aro/Projects/traidnet/wificore/backend/Dockerfile.test \
  -t wificore-test-runner \
  /home/kja2aro/Projects/traidnet/wificore/backend
```

---

## Running Tests

All commands below mount the live `backend/` source directory so you never need to rebuild the image after a code change.

### Run the entire test suite

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest --no-coverage
```

### Run only Unit tests

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest --testsuite=Unit --no-coverage
```

### Run only Feature tests

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest --testsuite=Feature --no-coverage
```

### Run a single test file

Replace the path at the end with the desired file:

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest tests/Feature/HotspotPaymentControllerTest.php --no-coverage
```

### Filter by test name

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest --filter "callback success" --no-coverage
```

### Run with code coverage

```bash
docker run --rm \
  --network wificore-network \
  -v /home/kja2aro/Projects/traidnet/wificore/backend:/app \
  -w /app \
  -e APP_ENV=testing \
  -e APP_KEY="base64:gDkER93+tzHLLvm4dE6fyyb0auL4CpO1v9FDHtesrQ0=" \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres -e DB_PORT=5432 \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e LOG_CHANNEL=stderr -e APP_BASE_DOMAIN=example.com \
  wificore-test-runner \
  php -d memory_limit=512M vendor/bin/pest --coverage
```

HTML report is written to `backend/storage/coverage-html/index.html`.

---

## Test Structure

```
backend/tests/
├── Unit/                              # Model and service unit tests
│   ├── MpesaTransactionModelTest.php
│   ├── PppoeBillingLifecycleServiceTest.php
│   ├── PppoePaymentModelTest.php
│   └── PppoeUserModelTest.php
│
├── Feature/                           # HTTP / integration tests
│   ├── BroadcastingSecurityTest.php
│   ├── HotspotPaymentControllerTest.php
│   ├── HotspotSystemTest.php
│   ├── MikrotikServiceTest.php
│   ├── MultiTenancyTest.php
│   ├── PPPoEConfigurationTest.php
│   ├── PppoePaybillBillingTest.php
│   ├── PppoePaymentControllerTest.php
│   ├── RouterBootstrapScriptTest.php
│   ├── RouterProvisioningTest.php
│   ├── ServiceDeploymentTest.php
│   ├── TenantAwareJobsTest.php
│   └── TenantPaybillCallbackTest.php
│
└── Helpers/
    ├── TenantTestHelper.php           # Trait: tenant schema setup / teardown
    └── TestDatabaseState.php          # Singleton: global migration-run flags
```

### Key test infrastructure

| Component | Purpose |
|---|---|
| `TenantTestHelper` trait | Sets up `tenant_test_payments` schema, runs public + tenant migrations once per process, creates shared tenant/router/package fixtures |
| `TestDatabaseState` singleton | Prevents duplicate schema migrations across test classes in the same PHP process |
| `DatabaseTransactions` trait | Wraps every individual test method in a DB transaction that is rolled back on teardown — no manual cleanup needed |
| `phpunit.xml` | Declares `Unit` and `Feature` test suites; sets all env vars used inside the container |

---

## Environment Variables Reference

| Variable | Value used in tests | Purpose |
|---|---|---|
| `APP_ENV` | `testing` | Disables `EnforceSubdomainTenantBinding` and other prod-only guards |
| `APP_KEY` | see command above | Required for encryption / session |
| `DB_HOST` | `wificore-postgres` | Postgres container on `wificore-network` |
| `DB_DATABASE` | `wms_testing` | Isolated test database (not production) |
| `QUEUE_CONNECTION` | `sync` | Jobs execute synchronously so assertions work without workers |
| `CACHE_STORE` | `array` | In-memory cache, never hits Redis |
| `SESSION_DRIVER` | `array` | Stateless sessions |
| `APP_BASE_DOMAIN` | `example.com` | Used by tenant subdomain logic |
| `LOG_CHANNEL` | `stderr` | Keeps test output readable |

---

## Troubleshooting

**`wificore-postgres` not found / connection refused**
Ensure the container is running: `docker ps --filter name=wificore-postgres`

**`wificore-test-runner` image not found**
Build it with the command in step 2 above.

**`Class not found` / autoload errors**
Run `composer install` inside the test container or rebuild the image.

**Migrations fail / schema already exists**
The `TenantTestHelper` trait is idempotent — it uses `CREATE SCHEMA IF NOT EXISTS` and tracks state via `TestDatabaseState`. If the `wms_testing` database is stale, drop and recreate it:
```bash
docker exec wificore-postgres psql -U admin -d postgres -c "DROP DATABASE IF EXISTS wms_testing;"
docker exec wificore-postgres psql -U admin -d postgres -c "CREATE DATABASE wms_testing;"
```

**Broadcasting / Pusher errors during tests**
Broadcast events must be listed in `Event::fake()` in the relevant test's `setUp()`. `APP_ENV=testing` alone does not disable Pusher — explicitly fake every `ShouldBroadcast` event the code-under-test dispatches.
