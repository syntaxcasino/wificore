<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PppoeUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'username',
        'password',
        'account_number',
        'portal_password',
        'customer_name',
        'customer_email',
        'customer_phone',
        'package_id',
        'router_id',
        'expires_at',
        'rate_limit',
        'simultaneous_use',
        'is_active',
        'status',
        'payment_status',
        'balance',
        'last_payment_date',
        'next_payment_due',
        'amount_due',
        'amount_paid',
        'in_grace_period',
        'grace_period_ends',
        'suspended_at',
        'suspension_reason',
        'payment_method',
        'payment_reference',
        'last_reminder_sent_at',
        'reminder_count',
        'last_invoice_sent_at',
        'last_receipt_sent_at',
    ];

    protected $hidden = [
        'password',
        'portal_password',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'simultaneous_use' => 'integer',
        'balance' => 'decimal:2',
        'last_payment_date' => 'datetime',
        'next_payment_due' => 'datetime',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'in_grace_period' => 'boolean',
        'grace_period_ends' => 'datetime',
        'suspended_at' => 'datetime',
        'last_reminder_sent_at' => 'datetime',
        'last_invoice_sent_at' => 'datetime',
        'last_receipt_sent_at' => 'datetime',
    ];

    /**
     * Boot the model.
     * Automatically ensure radius_user_schema_mapping entry exists on save.
     */
    protected static function boot()
    {
        parent::boot();

        static::saved(function ($pppoeUser) {
            static::ensureRadiusSchemaMapping($pppoeUser);
            static::syncRadiusForPaymentStatus($pppoeUser);
        });
    }

    /**
     * Ensure radius_user_schema_mapping entry exists for this PPPoE user.
     * This is critical for FreeRADIUS to know which tenant schema to query.
     */
    public static function ensureRadiusSchemaMapping(self $pppoeUser): void
    {
        try {
            // Get tenant info from connection or app context
            $tenant = self::getCurrentTenant();
            if (!$tenant) {
                Log::warning('Cannot ensure schema mapping: no tenant context', [
                    'username' => $pppoeUser->username,
                ]);
                return;
            }

            DB::statement("
                INSERT INTO public.radius_user_schema_mapping (username, schema_name, tenant_id, user_role, is_active, created_at, updated_at)
                VALUES (?, ?, ?::uuid, 'pppoe', true, NOW(), NOW())
                ON CONFLICT (username) DO UPDATE SET
                    schema_name = EXCLUDED.schema_name,
                    tenant_id = EXCLUDED.tenant_id,
                    user_role = EXCLUDED.user_role,
                    is_active = true,
                    updated_at = NOW()
            ", [$pppoeUser->username, $tenant->schema_name, $tenant->id]);

            Log::info('RADIUS schema mapping ensured via model event', [
                'username' => $pppoeUser->username,
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to ensure RADIUS schema mapping', [
                'username' => $pppoeUser->username,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Sync RADIUS entries based on payment status.
     * - When payment_status transitions to 'paid': remove Auth-Type Reject (unblock)
     * - When payment_status transitions to 'unpaid' AND user is suspended: add Auth-Type Reject (block)
     * - On creation (wasRecentlyCreated): do NOT block — new users get a grace period
     *   handled by CheckPppoePaymentStatusJob.
     */
    public static function syncRadiusForPaymentStatus(self $pppoeUser): void
    {
        try {
            // Only sync if payment status actually changed
            if (!$pppoeUser->wasChanged('payment_status')) {
                return;
            }

            // Never auto-block on initial creation — new users get a grace period
            if ($pppoeUser->wasRecentlyCreated) {
                return;
            }

            if ($pppoeUser->payment_status === 'paid') {
                // Remove Auth-Type Reject to allow paid user to connect
                DB::table('radcheck')
                    ->where('username', $pppoeUser->username)
                    ->where('attribute', 'Auth-Type')
                    ->where('value', 'Reject')
                    ->delete();

                Log::info('RADIUS: Unblocked paid user', [
                    'username' => $pppoeUser->username,
                    'payment_status' => $pppoeUser->payment_status,
                ]);
            } elseif ($pppoeUser->payment_status === 'unpaid' && $pppoeUser->isSuspended()) {
                // Only block if user is already suspended (grace period expired)
                DB::table('radcheck')->updateOrInsert(
                    ['username' => $pppoeUser->username, 'attribute' => 'Auth-Type'],
                    ['op' => ':=', 'value' => 'Reject']
                );

                Log::info('RADIUS: Blocked suspended unpaid user', [
                    'username' => $pppoeUser->username,
                    'payment_status' => $pppoeUser->payment_status,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Failed to sync RADIUS for payment status', [
                'username' => $pppoeUser->username,
                'payment_status' => $pppoeUser->payment_status,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get current tenant from app context or database.
     */
    private static function getCurrentTenant(): ?Tenant
    {
        // Try to get from app container (set by TenantContext or middleware)
        if (app()->has('current_tenant')) {
            return app('current_tenant');
        }

        // Fallback: query from database using schema search path
        try {
            $schemaName = DB::selectOne("SELECT current_schema()")?->current_schema;
            if ($schemaName && $schemaName !== 'public') {
                return Tenant::where('schema_name', $schemaName)->first();
            }
        } catch (\Exception $e) {
            // Ignore and return null
        }

        return null;
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    public function payments()
    {
        return $this->hasMany(PppoePayment::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPaymentOverdue(): bool
    {
        return $this->next_payment_due && $this->next_payment_due < now() && !$this->in_grace_period;
    }

    public function isInGracePeriod(): bool
    {
        return $this->in_grace_period && $this->grace_period_ends && $this->grace_period_ends > now();
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    public function canConnect(): bool
    {
        return $this->is_active && 
               !$this->isSuspended() && 
               ($this->isPaid() || $this->isInGracePeriod());
    }

    public function suspendForNonPayment(): bool
    {
        $this->suspended_at = now();
        $this->suspension_reason = 'Payment overdue';
        $this->is_active = false;
        $this->status = 'suspended';
        return $this->save();
    }

    public function activateAfterPayment(): bool
    {
        $this->suspended_at = null;
        $this->suspension_reason = null;
        $this->is_active = true;
        $this->status = 'active';
        $this->payment_status = 'paid';
        $this->in_grace_period = false;
        $this->grace_period_ends = null;
        return $this->save();
    }

    public function getBillingName(): string
    {
        return (string) ($this->customer_name ?: $this->username);
    }

    public function getBillingEmail(): ?string
    {
        return $this->customer_email ?: null;
    }

    public function getBillingPhone(): ?string
    {
        return $this->customer_phone ?: $this->payment_reference ?: null;
    }

    public function canReceiveBillingNotifications(): bool
    {
        return !empty($this->getBillingEmail()) || !empty($this->getBillingPhone());
    }

    public function needsBillingReminder(): bool
    {
        if (!$this->next_payment_due || $this->isSuspended()) {
            return false;
        }

        if ($this->last_reminder_sent_at && $this->last_reminder_sent_at->isToday()) {
            return false;
        }

        $daysUntilDue = now()->diffInDays($this->next_payment_due, false);

        return in_array($daysUntilDue, [7, 3, 1, 0], true);
    }

    public function markReminderSent(): bool
    {
        $this->last_reminder_sent_at = now();
        $this->reminder_count = ($this->reminder_count ?? 0) + 1;

        return $this->save();
    }

    public function shouldSendInvoice(): bool
    {
        if (!$this->next_payment_due) {
            return false;
        }

        if ($this->last_invoice_sent_at && $this->last_invoice_sent_at->isSameDay($this->next_payment_due)) {
            return false;
        }

        return now()->diffInDays($this->next_payment_due, false) <= 7;
    }

    public function markInvoiceSent(): bool
    {
        $this->last_invoice_sent_at = now();

        return $this->save();
    }

    public function markReceiptSent(): bool
    {
        $this->last_receipt_sent_at = now();

        return $this->save();
    }

    /**
     * Generate a tenant-scoped account number.
     *
     * Format: {3-char prefix}{service-type-char}{5-digit counter}
     * Examples: TRDP00001 (PPPoE), TRDH00001 (Hotspot)
     *
     * @param  string  $tenantPrefix   3-char alphanumeric tenant code (from tenants.account_prefix)
     * @param  string  $serviceType    'P' for PPPoE, 'H' for Hotspot
     */
    public static function generateAccountNumber(string $tenantPrefix, string $serviceType = 'P'): string
    {
        $prefix = strtoupper(substr($tenantPrefix, 0, 3));
        $typeChar = strtoupper(substr($serviceType, 0, 1));
        $scope = $prefix . $typeChar;

        // Use withTrashed() to include soft-deleted users and prevent re-use of numbers
        $maxAccountNumber = static::withTrashed()
            ->where('account_number', 'like', $scope . '%')
            ->selectRaw("MAX(CAST(SUBSTRING(account_number FROM '[0-9]+$') AS INTEGER)) as max_num")
            ->value('max_num');

        $nextNumber = ($maxAccountNumber ?? 0) + 1;

        return $scope . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
