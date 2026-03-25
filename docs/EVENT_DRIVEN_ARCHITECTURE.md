# Event-Driven Architecture - Registration Flow

## Overview

The tenant registration system uses a fully event-driven architecture with Laravel jobs and queues. All async operations (email sending, schema creation, IP allocation) are handled by dedicated jobs for better scalability, error handling, and monitoring.

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────────┐
│                    Registration Flow                             │
└─────────────────────────────────────────────────────────────────┘

User Submits Form
       │
       ▼
TenantRegistrationController
       │
       ├─► Create Tenant (inactive)
       │   Store credentials in settings
       │
       └─► Dispatch: SendTenantVerificationEmailJob
                     Queue: emails
                     │
                     ▼
           ┌─────────────────────────┐
           │ Send Verification Email │
           └─────────────────────────┘
                     │
                     ▼
           User Clicks Link
                     │
                     ▼
EmailVerificationController
       │
       ├─► Mark email_verified_at
       │
       └─► Dispatch: CreateTenantJob
                     Queue: tenant-management
                     │
                     ▼
           ┌─────────────────────────┐
           │  Create Schema & User   │
           │  Add RADIUS Credentials │
           └─────────────────────────┘
                     │
                     ├─► Dispatch: AllocateTenantIpBlockJob
                     │             Queue: tenant-management
                     │             │
                     │             ▼
                     │   ┌──────────────────────┐
                     │   │ Allocate IP Block    │
                     │   │ 10.X.0.0/16          │
                     │   └──────────────────────┘
                     │
                     └─► Dispatch: SendTenantCredentialsEmailJob
                                   Queue: emails
                                   │
                                   ▼
                         ┌─────────────────────────┐
                         │ Send Credentials Email  │
                         │ Mark tenant active      │
                         └─────────────────────────┘
```

## Jobs and Queues

### 1. SendTenantVerificationEmailJob

**Queue:** `emails`  
**Retries:** 3  
**Timeout:** 60 seconds  
**Backoff:** [10, 30, 60] seconds

**Purpose:** Send email verification link to tenant

**Triggered by:** `TenantRegistrationController::register()`

**Actions:**
- Sends verification email with 60-minute expiry link
- Logs success/failure

**Error Handling:**
- Retries 3 times with exponential backoff
- Logs permanent failure

```php
SendTenantVerificationEmailJob::dispatch(
    $tenant->id,
    $slug,
    $companyName
)->onQueue('emails');
```

### 2. CreateTenantJob

**Queue:** `tenant-management`  
**Retries:** 2  
**Timeout:** 300 seconds  
**Backoff:** [30, 60] seconds

**Purpose:** Create tenant schema, admin user, and RADIUS credentials

**Triggered by:** `EmailVerificationController::verify()`

**Actions:**
1. Create tenant schema in PostgreSQL
2. Create admin user in tenant schema
3. Add RADIUS credentials
4. Broadcast `TenantCreated` event
5. Dispatch `AllocateTenantIpBlockJob`
6. Dispatch `SendTenantCredentialsEmailJob`

**Error Handling:**
- Full database transaction rollback on failure
- Retries 2 times
- Logs detailed error information

```php
CreateTenantJob::dispatch($tenantData, $adminData, $password)
    ->onQueue('tenant-management');
```

### 3. AllocateTenantIpBlockJob

**Queue:** `tenant-management`  
**Retries:** 3  
**Timeout:** 120 seconds  
**Backoff:** [10, 30, 60] seconds

**Purpose:** Allocate unique IP block to tenant

**Triggered by:** `CreateTenantJob::handle()`

**Actions:**
- Finds next available IP block (10.1.0.0/16, 10.2.0.0/16, etc.)
- Allocates /16 subnet (65,536 IPs)
- Stores in tenant settings

**Error Handling:**
- Checks if block already allocated (idempotent)
- Retries 3 times
- Logs allocation details

```php
AllocateTenantIpBlockJob::dispatch($tenant->id)
    ->onQueue('tenant-management');
```

### 4. SendTenantCredentialsEmailJob

**Queue:** `emails`  
**Retries:** 3  
**Timeout:** 60 seconds  
**Backoff:** [10, 30, 60] seconds

**Purpose:** Send login credentials to tenant

**Triggered by:** `CreateTenantJob::handle()`

**Actions:**
- Sends email with username and password
- Marks tenant as active
- Sets `credentials_sent` flag

**Error Handling:**
- Checks email verification status
- Retries 3 times
- Logs success/failure

```php
SendTenantCredentialsEmailJob::dispatch(
    $tenant->id,
    $username,
    $password
)->onQueue('emails');
```

## Queue Configuration

### Queue Workers

The system uses two separate queues for better resource management:

**1. emails Queue**
- Handles all email notifications
- Lower priority, can be scaled independently
- Workers: 2-4 recommended

**2. tenant-management Queue**
- Handles critical tenant operations
- Higher priority, requires more resources
- Workers: 1-2 recommended

### Starting Queue Workers

```bash
# Start email queue worker
php artisan queue:work --queue=emails --tries=3 --timeout=60

# Start tenant management queue worker
php artisan queue:work --queue=tenant-management --tries=3 --timeout=300

# Or use supervisor (recommended for production)
```

### Supervisor Configuration

**File:** `backend/supervisor/laravel-worker.conf`

```ini
[program:laravel-worker-emails]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --queue=emails --tries=3 --timeout=60 --sleep=3 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-emails.log
stopwaitsecs=3600

[program:laravel-worker-tenant]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work --queue=tenant-management --tries=3 --timeout=300 --sleep=3 --max-jobs=100
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=1
redirect_stderr=true
stdout_logfile=/var/www/html/storage/logs/worker-tenant.log
stopwaitsecs=3600
```

## Events

### TenantCreated Event

**Triggered by:** `CreateTenantJob`

**Payload:**
- Tenant model
- Admin user model

**Listeners:**
- Broadcasts to WebSocket (Soketi)
- Can be used for analytics, notifications, etc.

```php
broadcast(new TenantCreated($tenant, $adminUser))->toOthers();
```

## Error Handling

### Job Failures

All jobs implement the `failed()` method for permanent failure handling:

```php
public function failed(\Throwable $exception): void
{
    Log::error('JobName failed permanently', [
        'tenant_id' => $this->tenantId,
        'error' => $exception->getMessage(),
        'trace' => $exception->getTraceAsString(),
    ]);
}
```

### Retry Strategy

- **Exponential Backoff:** Jobs retry with increasing delays
- **Max Attempts:** Configured per job (2-3 attempts)
- **Timeout:** Each job has appropriate timeout
- **Failed Jobs Table:** Laravel stores failed jobs for manual retry

### Monitoring Failed Jobs

```bash
# View failed jobs
php artisan queue:failed

# Retry specific failed job
php artisan queue:retry {id}

# Retry all failed jobs
php artisan queue:retry all

# Clear failed jobs
php artisan queue:flush
```

## Database Transactions

### CreateTenantJob Transaction Scope

```php
DB::beginTransaction();
try {
    // Create tenant
    // Create schema
    // Create user
    // Add RADIUS credentials
    
    DB::commit();
    
    // Dispatch other jobs (outside transaction)
    AllocateTenantIpBlockJob::dispatch(...);
    SendTenantCredentialsEmailJob::dispatch(...);
    
} catch (\Exception $e) {
    DB::rollBack();
    throw $e;
}
```

**Important:** Jobs are dispatched AFTER transaction commit to ensure data consistency.

## Idempotency

All jobs are designed to be idempotent:

### SendTenantVerificationEmailJob
```php
if ($tenant->email_verified_at) {
    Log::info('Email already verified, skipping');
    return;
}
```

### AllocateTenantIpBlockJob
```php
if (isset($tenant->settings['ip_block'])) {
    Log::info('IP block already allocated');
    return;
}
```

### SendTenantCredentialsEmailJob
```php
if (!$tenant->email_verified_at) {
    Log::warning('Email not verified, skipping');
    return;
}
```

## Testing

### Manual Testing

```bash
# Dispatch test job
php artisan tinker
>>> App\Jobs\SendTenantVerificationEmailJob::dispatch('tenant-id', 'slug', 'name');

# Check queue status
php artisan queue:work --once

# Monitor logs
tail -f storage/logs/laravel.log
```

### Queue Testing in Tests

```php
use Illuminate\Support\Facades\Queue;

public function test_registration_dispatches_verification_email()
{
    Queue::fake();
    
    $response = $this->post('/api/register/tenant', [
        'company_name' => 'Test Company',
        // ... other fields
    ]);
    
    Queue::assertPushed(SendTenantVerificationEmailJob::class);
}
```

## Performance Considerations

### Queue Priorities

```bash
# Process high-priority queue first
php artisan queue:work --queue=tenant-management,emails
```

### Horizon (Optional)

For advanced queue management, consider Laravel Horizon:

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

## Troubleshooting

### Jobs Not Processing

**Check:**
1. Queue workers running: `ps aux | grep queue:work`
2. Supervisor status: `supervisorctl status`
3. Database connection
4. Redis connection (if using Redis queue)

**Solution:**
```bash
# Restart queue workers
php artisan queue:restart

# Restart supervisor
supervisorctl restart all
```

### Jobs Failing Silently

**Check:**
1. Laravel logs: `storage/logs/laravel.log`
2. Failed jobs table: `php artisan queue:failed`
3. Worker logs: `storage/logs/worker-*.log`

**Solution:**
```bash
# Enable verbose logging
php artisan queue:work --verbose

# Check specific job
php artisan queue:retry {id} --verbose
```

### Email Not Sending

**Check:**
1. Mail configuration in `.env`
2. Email queue worker running
3. SMTP credentials valid

**Solution:**
```bash
# Test email configuration
php artisan tinker
>>> Mail::raw('Test', function($msg) { $msg->to('test@example.com'); });

# Check email queue
php artisan queue:work --queue=emails --once
```

## Best Practices

1. **Always use queues for:**
   - Email sending
   - External API calls
   - Heavy computations
   - Database migrations
   - File processing

2. **Keep jobs focused:**
   - One job = one responsibility
   - Chain jobs for complex workflows
   - Use events for side effects

3. **Handle failures gracefully:**
   - Implement `failed()` method
   - Log detailed error information
   - Set appropriate retry limits

4. **Monitor queue health:**
   - Set up alerts for failed jobs
   - Monitor queue length
   - Track job processing time

5. **Test thoroughly:**
   - Use `Queue::fake()` in tests
   - Test retry logic
   - Test failure scenarios

## Summary

The event-driven architecture provides:

- ✅ **Scalability:** Independent queue workers
- ✅ **Reliability:** Automatic retries with backoff
- ✅ **Monitoring:** Detailed logging and failed job tracking
- ✅ **Performance:** Non-blocking async operations
- ✅ **Maintainability:** Focused, single-responsibility jobs
- ✅ **Testability:** Easy to mock and test

All registration operations are now fully async and event-driven, providing a robust and scalable system.
