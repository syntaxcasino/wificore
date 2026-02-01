<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class PppoeUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'username',
        'password',
        'account_number',
        'package_id',
        'router_id',
        'expires_at',
        'rate_limit',
        'simultaneous_use',
        'is_active',
        'status',
        'payment_status',
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
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'simultaneous_use' => 'integer',
        'last_payment_date' => 'datetime',
        'next_payment_due' => 'datetime',
        'amount_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'in_grace_period' => 'boolean',
        'grace_period_ends' => 'datetime',
        'suspended_at' => 'datetime',
    ];

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

    public static function generateAccountNumber(string $tenantPrefix): string
    {
        // Use withTrashed() to include soft-deleted users and get the MAX number
        // This prevents duplicate account numbers when users are deleted
        $maxAccountNumber = static::withTrashed()
            ->where('account_number', 'like', strtoupper($tenantPrefix) . '%')
            ->selectRaw("MAX(CAST(SUBSTRING(account_number FROM '[0-9]+$') AS INTEGER)) as max_num")
            ->value('max_num');
        
        $nextNumber = ($maxAccountNumber ?? 0) + 1;
        
        return strtoupper($tenantPrefix) . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
