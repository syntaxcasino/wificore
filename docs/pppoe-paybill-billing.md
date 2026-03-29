# PPPoE Paybill Billing System

## Overview

This document describes the tenant-aware MPesa Paybill billing system for PPPoE users. The system supports:

- **Tenant-owned Paybill**: Tenants can configure their own MPesa Paybill credentials
- **Landlord Fallback**: Tenants without own Paybill use the system-wide (landlord) Paybill
- **Automatic Payment Detection**: Background job matches MPesa payments to PPPoE users
- **Automatic Disconnect/Reconnect**: Users are disconnected when payment is overdue and reconnected when payment is received
- **Real-time Updates**: All payment and session status changes are broadcast via Soketi/WebSocket
- **Zero Cross-Tenant Data Leaks**: Strict tenant isolation at all layers

## Architecture

```
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│   MPesa API     │────▶│  Callback URLs  │────▶│ TenantPaybill   │
│  (Safaricom)    │     │  /api/mpesa/    │     │   Controller    │
└─────────────────┘     │  paybill/*      │     └────────┬────────┘
                        └─────────────────┘              │
                                                         ▼
┌─────────────────┐     ┌─────────────────┐     ┌─────────────────┐
│  Soketi/WS      │◀────│    Events       │◀────│ TenantPaybill   │
│  Broadcast      │     │ PaymentReceived │     │    Service      │
└────────┬────────┘     │ StatusChanged   │     └────────┬────────┘
         │              └─────────────────┘              │
         ▼                                               ▼
┌─────────────────┐                             ┌─────────────────┐
│   Frontend      │                             │  PPPoE User     │
│   Dashboard     │                             │  Disconnect/    │
│                 │                             │  Reconnect Jobs │
└─────────────────┘                             └─────────────────┘
```

## Database Schema

### Tables (in tenant schema)

1. **tenant_paybill_settings**
   - Stores encrypted MPesa credentials per tenant
   - Supports landlord fallback flag
   - Tracks URL registration status

2. **mpesa_transactions**
   - Audit trail of all MPesa transactions
   - Links to PPPoE users and payments
   - Tracks matching status

3. **payment_check_logs**
   - Audit trail of automatic payment checks
   - Records transactions found, matched, users activated/disconnected

## API Endpoints

### Authenticated (Tenant Admin)

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/billing/paybill/settings` | Get Paybill settings (masked) |
| POST | `/api/billing/paybill/settings` | Save Paybill settings |
| POST | `/api/billing/paybill/test` | Test MPesa connection |
| POST | `/api/billing/paybill/register-urls` | Register callback URLs |
| POST | `/api/billing/paybill/activate` | Activate own Paybill |
| POST | `/api/billing/paybill/use-landlord` | Switch to landlord Paybill |
| GET | `/api/billing/paybill/instructions/{userId}` | Get payment instructions |
| GET | `/api/billing/paybill/transactions` | Get transaction history |
| GET | `/api/billing/paybill/logs` | Get payment check logs |
| POST | `/api/billing/paybill/check-payments` | Manually trigger payment check |

### Public (MPesa Callbacks)

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/mpesa/paybill/validation/{tenantId}` | Validation callback |
| POST | `/api/mpesa/paybill/confirmation/{tenantId}` | Confirmation callback |

## WebSocket Events

All events are broadcast on tenant-scoped private channels.

### Channel: `tenant.{tenantId}.payments`

| Event | Payload | Description |
|-------|---------|-------------|
| `payment.received` | `{ user_id, payment_id, amount, timestamp }` | Payment received |

### Channel: `tenant.{tenantId}.pppoe-users`

| Event | Payload | Description |
|-------|---------|-------------|
| `pppoe.payment.status.changed` | `{ user_id, status, action, timestamp }` | User payment status changed |

### Channel: `tenant.{tenantId}.settings`

| Event | Payload | Description |
|-------|---------|-------------|
| `paybill.settings.updated` | `{ settings, timestamp }` | Paybill settings updated |

## Landlord Fallback Flow

When a tenant doesn't have their own Paybill configured:

1. System uses landlord's Paybill credentials from `config/mpesa.php`
2. Payments are received to landlord's Paybill
3. Account reference (BillRefNumber) is used to identify the tenant's user
4. Transaction is recorded with `is_landlord_paybill = true`
5. Payment is matched to the correct tenant's PPPoE user

### Configuration (`.env`)

```env
# Landlord/System Paybill (fallback)
MPESA_ENV=sandbox
MPESA_CONSUMER_KEY=your_consumer_key
MPESA_CONSUMER_SECRET=your_consumer_secret
MPESA_SHORTCODE=174379
MPESA_PASSKEY=your_passkey
```

## Payment Flow

### 1. User Initiates Payment

```
User → M-Pesa Menu → Pay Bill → Business Number + Account Number → Confirm
```

### 2. Safaricom Sends Validation

```
POST /api/mpesa/paybill/validation/{tenantId}
{
  "TransactionType": "Pay Bill",
  "TransID": "RKTQDM7W6S",
  "TransAmount": "500.00",
  "BusinessShortCode": "174379",
  "BillRefNumber": "john_doe",  // Account number = username
  "MSISDN": "254712345678"
}
```

### 3. System Validates Account

- Finds PPPoE user by account_number or username
- Returns `ResultCode: 0` (Accept) or error code (Reject)

### 4. Safaricom Sends Confirmation

```
POST /api/mpesa/paybill/confirmation/{tenantId}
{
  "TransID": "RKTQDM7W6S",
  "TransAmount": "500.00",
  "BillRefNumber": "john_doe",
  "MSISDN": "254712345678",
  "TransTime": "20260205223000"
}
```

### 5. System Processes Payment

1. Records transaction in `mpesa_transactions`
2. Matches to PPPoE user
3. Creates payment record in `pppoe_payments`
4. Activates user if suspended
5. Dispatches `ReconnectPppoeUserJob` if needed
6. Broadcasts `PaymentReceived` event

## Automatic Disconnect/Reconnect

### Disconnect Flow (Overdue Payment)

1. `CheckPppoePaymentsJob` runs every 5 minutes
2. Finds users with `next_payment_due < now()` and not in grace period
3. Puts users in grace period (default: 3 days)
4. After grace period expires:
   - Updates user status to `suspended`
   - Adds `Auth-Type := Reject` to RADIUS
   - Disconnects active session on router via SSH
   - Broadcasts `PppoeUserPaymentStatusChanged` event

### Reconnect Flow (Payment Received)

1. Payment confirmation received
2. User status updated to `active`
3. `Auth-Type := Reject` removed from RADIUS
4. `ReconnectPppoeUserJob` dispatched
5. User can reconnect (next PPPoE dial-in will succeed)
6. Broadcasts `PppoeUserPaymentStatusChanged` event

## Security

### Credential Encryption

All sensitive MPesa credentials are encrypted at rest using Laravel's `Crypt` facade:

```php
// TenantPaybillSetting model
public function setConsumerKeyAttribute(?string $value): void
{
    $this->attributes['consumer_key'] = $value ? Crypt::encryptString($value) : null;
}
```

### Tenant Isolation

1. **Database**: Schema-based isolation - each tenant has own schema
2. **API Queries**: All queries scoped to tenant's schema
3. **WebSocket**: Tenant-scoped private channels with authorization
4. **Jobs**: Use `TenantAwareJob` trait with `executeInTenantContext()`

### WebSocket Channel Authorization

```php
// routes/channels.php
Broadcast::channel('tenant.{tenantId}.payments', function ($user, $tenantId) {
    return $user->isAdmin() && (string) $user->tenant_id === (string) $tenantId;
});
```

## Testing

### Test Script: Verify Tenant Isolation

```bash
php artisan tinker

# Create test transactions for two different tenants
# Verify tenant A cannot see tenant B's transactions
```

### Test Script: Payment Flow

```bash
# 1. Create test PPPoE user
# 2. Simulate MPesa validation callback
# 3. Simulate MPesa confirmation callback
# 4. Verify user is activated
# 5. Verify payment record created
# 6. Verify WebSocket event broadcast
```

### Test Script: Disconnect/Reconnect

```bash
# 1. Create PPPoE user with expired payment
# 2. Run CheckPppoePaymentsJob
# 3. Verify user in grace period
# 4. Advance time past grace period
# 5. Run CheckPppoePaymentsJob again
# 6. Verify user disconnected
# 7. Simulate payment
# 8. Verify user reconnected
```

## Onboarding New Tenants

1. Tenant registers and schema is created
2. Default: Uses landlord Paybill (no configuration needed)
3. Optional: Tenant configures own Paybill in portal
   - Enter credentials
   - Test connection
   - Register callback URLs
   - Activate

## Troubleshooting

### Payment Not Matched

1. Check `mpesa_transactions` for unmatched records
2. Verify `bill_ref_number` matches PPPoE user's `account_number` or `username`
3. Check `payment_check_logs` for errors

### User Not Reconnecting

1. Check RADIUS `radcheck` table for `Auth-Type := Reject`
2. Verify `ReconnectPppoeUserJob` completed successfully
3. Check router for active session (should be cleared)

### WebSocket Events Not Received

1. Verify Soketi is running
2. Check channel authorization in browser console
3. Verify tenant ID matches in channel name
