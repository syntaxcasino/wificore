# Payment System — Test Cases Documentation

> **Scope**: End-to-end payment flows for PPPoE users (C2B Paybill) and Hotspot users (STK Push).  
> **Coverage target**: 100% of payment-related controllers, services, and models.  
> **Database**: Real PostgreSQL `wms_testing` — no mocks for DB/model calls; external Safaricom HTTP is intercepted at the service boundary.

---

## 1. Bug Fixes Applied Before Testing

| # | File | Bug | Fix |
|---|------|-----|-----|
| 1 | `app/Models/PppoePayment.php` | Applied `App\Scopes\TenantScope` which calls `getQualifiedTenantColumn()` — method doesn't exist on schema-isolated model → `BadMethodCallException` | Removed `TenantScope` and its `booted()` block; schema isolation is sufficient |
| 2 | `app/Http/Controllers/Api/PppoePaymentController.php` | `activateUserAfterPayment()` used global `request()->user()->tenant_id` instead of the already-resolved `$tenantId` → breaks when called from `verify()` path | `activateUserAfterPayment()` now accepts explicit `string $tenantId` parameter |
| 3 | `database/factories/PackageFactory.php` | Used `duration_hours` which is not in `Package::$fillable`; model uses `duration` | Renamed to `duration` |

---

## 2. Test Infrastructure

### Base Class — `Tests\TestCase`
Overrides `createApplication()` to force PostgreSQL `wms_testing` database connection before the Laravel bootstrap runs, preventing PgBouncer env-var bleed-through.

### Shared Trait — `Tests\Helpers\TenantTestHelper`
Provides per-test-class tenant schema lifecycle:
- `setUpTestTenant()` — creates tenant row + PostgreSQL schema `tenant_test_payments` + runs `database/migrations/tenant` once per process.
- `createAdminUser()`, `createPackage()`, `createRouter()` — factory helpers.
- `tearDownTenantContext()` — resets search_path to `public` after each test.

All tests use `DatabaseTransactions` so DML is rolled back between tests.

---

## 3. Backend Unit Tests

### 3.1 `PppoePaymentModelTest` — `tests/Unit/PppoePaymentModelTest.php`

| Test | What it verifies |
|------|-----------------|
| `test_mark_as_completed_sets_status_and_verified_fields` | `markAsCompleted($userId)` sets `status=completed`, `verified_by`, `verified_at` |
| `test_mark_as_completed_persists_to_database` | DB row reflects `status=completed` after call |
| `test_mark_as_failed_sets_status_to_failed` | `markAsFailed()` sets `status=failed` |
| `test_mark_as_failed_persists_to_database` | DB row reflects `status=failed` |
| `test_scope_pending_returns_only_pending_payments` | `PppoePayment::pending()` scope filters correctly |
| `test_scope_completed_returns_only_completed_payments` | `PppoePayment::completed()` scope filters correctly |
| `test_scope_failed_returns_only_failed_payments` | `PppoePayment::failed()` scope filters correctly |
| `test_pppoe_user_relationship_returns_correct_user` | `pppoeUser()` relation loads matching `PppoeUser` |
| `test_verified_by_relationship_returns_correct_user` | `verifiedBy()` relation loads admin `User` |
| `test_soft_delete_does_not_appear_in_default_query` | Deleted payment invisible to `find()` |
| `test_soft_deleted_payment_visible_with_withTrashed` | `withTrashed()` exposes soft-deleted row |

### 3.2 `PppoeUserModelTest` — `tests/Unit/PppoeUserModelTest.php`

| Test | What it verifies |
|------|-----------------|
| `test_activate_after_payment_sets_status_active_and_paid` | `activateAfterPayment()` → `status=active`, `payment_status=paid`, `is_active=true` |
| `test_activate_after_payment_clears_grace_period` | Grace period flag and timestamp cleared |
| `test_suspend_for_non_payment_sets_status_and_reason` | `suspendForNonPayment()` → `status=suspended`, `is_active=false`, `suspended_at` set |
| `test_is_paid_returns_true_when_payment_status_is_paid` | `isPaid()` true for `paid` |
| `test_is_paid_returns_false_when_payment_status_is_unpaid` | `isPaid()` false for `unpaid` |
| `test_is_paid_returns_false_when_payment_status_is_overdue` | `isPaid()` false for `overdue` |
| `test_is_suspended_returns_true_when_suspended_at_is_set` | `isSuspended()` true when `suspended_at` present |
| `test_is_suspended_returns_false_when_suspended_at_is_null` | `isSuspended()` false when not suspended |
| `test_is_in_grace_period_returns_true_within_window` | `isInGracePeriod()` true when flag set and not expired |
| `test_is_in_grace_period_returns_false_when_flag_is_off` | `isInGracePeriod()` false when flag off |
| `test_can_connect_returns_true_for_active_paid_user` | `canConnect()` true for active+paid |
| `test_can_connect_returns_false_for_suspended_user` | `canConnect()` false for suspended |
| `test_scope_overdue_returns_users_past_next_payment_due` | `PppoeUser::overdue()` scope filters correctly |

### 3.3 `MpesaTransactionModelTest` — `tests/Unit/MpesaTransactionModelTest.php`

| Test | What it verifies |
|------|-----------------|
| `test_mark_as_matched_updates_fields_correctly` | `markAsMatched()` sets `is_matched=true`, `pppoe_user_id`, `match_method`, `status=processing`, `matched_at` |
| `test_mark_as_completed_sets_payment_id_and_status` | `markAsCompleted($paymentId)` sets `status=completed` and `pppoe_payment_id` |
| `test_mark_as_failed_sets_status_and_increments_retry` | `markAsFailed()` sets `status=failed`, increments `retry_count`, sets `failure_reason` and `last_retry_at` |
| `test_mark_as_failed_accumulates_retry_count` | Successive calls accumulate retry count |
| `test_can_retry_returns_true_when_failed_and_retries_below_3` | `canRetry()` true when `retry_count < 3` and `status=failed` |
| `test_can_retry_returns_false_when_retry_count_equals_3` | `canRetry()` false at max retries |
| `test_can_retry_returns_false_when_status_is_not_failed` | `canRetry()` false for completed |
| `test_scope_unmatched_excludes_matched_transactions` | `unmatched()` scope filters correctly |
| `test_scope_unmatched_includes_failed_retryable_transactions` | Failed+unmatched visible in scope |
| `test_scope_by_shortcode_filters_correctly` | `byShortcode()` scope filters by business_shortcode |
| `test_scope_recent_excludes_old_transactions` | `recent(24)` scope excludes transactions older than 24h |

### 3.4 `PppoeBillingLifecycleServiceTest` — `tests/Unit/PppoeBillingLifecycleServiceTest.php`

| Test | What it verifies |
|------|-----------------|
| `test_successful_payment_for_active_user_updates_billing_fields` | `handleSuccessfulPayment()` updates `last_payment_date`, `next_payment_due`, `payment_method` |
| `test_renewal_does_not_dispatch_reconnect_job` | Active user renewal does NOT dispatch `ReconnectPppoeUserJob` |
| `test_renewal_fires_payment_received_event` | `PaymentReceived` event fired with correct tenant/user/payment IDs |
| `test_renewal_fires_payment_status_changed_event_with_renewed` | `PppoeUserPaymentStatusChanged` fired with `action=renewed` |
| `test_payment_for_suspended_user_activates_and_dispatches_reconnect_job` | Suspended user → activated + `ReconnectPppoeUserJob` dispatched |
| `test_payment_for_expired_user_dispatches_reconnect_job` | Expired user → `ReconnectPppoeUserJob` dispatched |
| `test_reconnect_fires_payment_status_changed_event_with_reconnected` | `PppoeUserPaymentStatusChanged` fired with `action=reconnected` |
| `test_reject_entry_removed_from_radcheck_on_payment` | RADIUS `radcheck` Reject entry deleted after payment |

---

## 4. Backend Feature Tests

### 4.1 `PppoePaymentControllerTest` — `tests/Feature/PppoePaymentControllerTest.php`

#### `GET /api/pppoe/payments`
| Test | Expected |
|------|----------|
| `test_index_requires_authentication` | 401 without token |
| `test_index_returns_paginated_payments` | 200 + `{success, data}` structure |

#### `POST /api/pppoe/payments`
| Test | Expected |
|------|----------|
| `test_store_requires_authentication` | 401 |
| `test_store_validates_required_fields` | 422 + errors for `pppoe_user_id`, `amount`, `payment_method`, `payment_date` |
| `test_store_validates_payment_method_enum` | 422 for `bitcoin` |
| `test_store_validates_amount_is_numeric` | 422 for non-numeric amount |
| `test_store_mpesa_payment_auto_verifies_and_activates_user` | 201, DB status=completed, user status=active |
| `test_store_paybill_payment_auto_verifies` | 201, DB status=completed |
| `test_store_bank_payment_auto_verifies` | 201, DB status=completed |
| `test_store_cash_payment_stays_pending` | 201, DB status=pending |
| `test_store_fires_payment_received_event_for_auto_verified` | `PaymentReceived` event dispatched |
| `test_store_does_not_fire_event_for_cash_payment` | `PaymentReceived` NOT dispatched |
| `test_store_returns_payment_with_relationships_loaded` | Response includes `pppoe_user` relation |

#### `POST /api/pppoe/payments/{id}/verify`
| Test | Expected |
|------|----------|
| `test_verify_transitions_pending_to_completed` | 200, DB status=completed |
| `test_verify_rejects_already_completed_payment` | 422 |
| `test_verify_activates_user_after_cash_payment` | User status=active, is_active=true |

#### `GET /api/pppoe/payments/pending`
| Test | Expected |
|------|----------|
| `test_get_pending_payments_returns_only_pending` | All items have status=pending |

#### `GET /api/pppoe/payments/user/{userId}`
| Test | Expected |
|------|----------|
| `test_get_user_payments_returns_only_that_users_payments` | All items belong to the queried user |

### 4.2 `TenantPaybillCallbackTest` — `tests/Feature/TenantPaybillCallbackTest.php`

#### `POST /api/mpesa/paybill/validation/{tenantId}`
| Test | Expected |
|------|----------|
| `test_validation_returns_accepted_for_known_account` | `ResultCode=0` |
| `test_validation_rejects_unknown_account_number` | `ResultCode=C2B00013` |
| `test_validation_rejects_zero_amount` | `ResultCode=C2B00014` |
| `test_validation_accepts_username_as_bill_ref` | `ResultCode=0` |
| `test_validation_rejects_inactive_tenant` | `ResultCode=C2B00011` |
| `test_validation_rejects_non_existent_tenant` | `ResultCode=C2B00011` |

#### `POST /api/mpesa/paybill/confirmation/{tenantId}`
| Test | Expected |
|------|----------|
| `test_confirmation_creates_mpesa_transaction_record` | `mpesa_transactions` row with matching TransID |
| `test_confirmation_creates_pppoe_payment_for_known_user` | `ResultCode=0`, `pppoe_payments` row with status=completed |
| `test_confirmation_activates_suspended_user` | User status=active, payment_status=paid |
| `test_confirmation_stores_unmatched_transaction_for_unknown_account` | `mpesa_transactions` row with is_matched=false |
| `test_confirmation_fires_payment_received_event` | `PaymentReceived` event dispatched |
| `test_confirmation_ignores_duplicate_transaction_id` | Only 1 row in `mpesa_transactions` for same TransID |

#### `GET/POST /api/billing/paybill/*`
| Test | Expected |
|------|----------|
| `test_get_settings_requires_auth` | 401 |
| `test_get_settings_returns_structure_when_no_settings_exist` | 200 + required structure |
| `test_get_settings_returns_masked_credentials_when_settings_exist` | Consumer key masked in response |
| `test_save_settings_validates_required_environment` | 422 |
| `test_save_settings_creates_new_settings_record` | 200, DB row created |
| `test_get_transactions_returns_paginated_list` | 200 + `{success, data}` |
| `test_trigger_payment_check_queues_job` | 200 + `{success: true}` |

### 4.3 `HotspotPaymentControllerTest` — `tests/Feature/HotspotPaymentControllerTest.php`

#### `POST /api/payments/initiate`
| Test | Expected |
|------|----------|
| `test_initiate_validates_phone_number_required` | 422 |
| `test_initiate_validates_package_id_required` | 422 |
| `test_initiate_creates_payment_record` | 200/201, payment row created |
| `test_initiate_creates_mpesa_transaction_map_record` | `mpesa_transaction_maps` row with tenant_id |

#### `POST /api/mpesa/callback`
| Test | Expected |
|------|----------|
| `test_callback_with_no_checkout_request_id_returns_ok` | Non-500 response (graceful) |
| `test_callback_success_marks_payment_completed` | `payments` status=completed |
| `test_callback_success_dispatches_provisioning_jobs` | `CreateHotspotUserJob` pushed |
| `test_callback_cancelled_by_user_marks_payment_failed` | `payments` status=failed |
| `test_callback_failed_does_not_dispatch_provisioning_jobs` | `CreateHotspotUserJob` NOT pushed |
| `test_callback_insufficient_funds_marks_payment_failed` | `payments` status=failed |

#### `GET /api/payments/{payment}/status`
| Test | Expected |
|------|----------|
| `test_check_status_returns_pending_for_pending_payment` | `{status: pending}` |
| `test_check_status_returns_completed_with_credentials` | `{status: completed, credentials: {...}}` |
| `test_check_status_returns_failed_for_failed_payment` | `{status: failed}` |

---

## 5. Frontend Unit Tests (Vitest)

### 5.1 `usePppoePayments.test.js`

| Test group | Tests |
|------------|-------|
| `fetchSettings()` | loading lifecycle, stores response, correct URL, error handling, generic error message, clears error |
| `saveSettings()` | posts with form data, updates settings ref, error+rethrow, loading reset on error |
| `testConnection()` | posts correct URL, returns data, throws on failure |
| `registerUrls()` | posts correct URL, throws on failure |
| `useLandlordPaybill()` | posts correct URL, returns landlord shortcode |
| `activateOwnPaybill()` | posts correct URL, throws on failure |
| `getPaymentInstructions()` | GET with userId, returns instructions data |
| `fetchTransactions()` | GET with page params, updates transactions ref, defaults to page 1 |
| `fetchCheckLogs()` | GET correct URL, stores in checkLogs ref |
| `triggerPaymentCheck()` | POST correct URL, throws on failure |
| **Computed** | `hasOwnPaybill`, `usingLandlordPaybill`, `activeShortcode` (landlord vs own), false when null |
| **WebSocket** | null when Echo absent, subscribes to 3 channels, cleanup calls stopListening×3, onSettingsUpdated callback |

### 5.2 `usePayments.test.js`

| Test group | Tests |
|------------|-------|
| `initiatePayment()` | correct POST payload, status=success type, includes transactionId, default message, returns `{success,data}`, error type on server failure, returns `{success:false}`, axios error handling, network error, loading lifecycle, loading reset on error, clears state, "Unexpected error" for unknown error |
| `fetchPayments()` | GET /payments, populates transactions, empty array on error, sets error ref, loading lifecycle, loading reset on error, accepts empty array, handles null response |
| **Reactive state** | loading starts false, error starts null, paymentStatus starts null, transactions starts `[]` |

---

## 6. Running the Tests

### Backend (inside Docker)
```bash
docker run --rm --network wificore-network \
  -v /path/to/backend:/src \
  -e APP_ENV=testing -e APP_KEY="..." \
  -e DB_CONNECTION=pgsql -e DB_HOST=wificore-postgres \
  -e DB_DATABASE=wms_testing -e DB_USERNAME=admin -e DB_PASSWORD=secret \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync \
  wificore-wificore-backend \
  -c "cd /src && php vendor/bin/pest tests/ --no-coverage 2>&1"
```

### Frontend
```bash
cd frontend && npm run test:unit
```

### Coverage report
```bash
# Backend
php vendor/bin/pest --coverage --coverage-html=coverage/html

# Frontend
npm run test:unit -- --coverage
```
