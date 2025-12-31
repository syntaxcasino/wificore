<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory, HasUuid;

    protected $fillable = [
        // tenant_id removed for schema isolation
        'user_id',
        'mac_address',
        'phone_number',
        'package_id',
        'router_id',
        'amount',
        'transaction_id',
        'status',
        'payment_method',
        'callback_response',
        'mpesa_receipt',
    ];

    protected $casts = [
        'id' => 'string',
        'amount' => 'float',
        'callback_response' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that made this payment
     */
    public function user()
    {
        // Users are in public schema
        return $this->belongsTo(User::class);
    }

    /**
     * Get the package for this payment
     */
    public function package()
    {
        // Packages are in public schema
        return $this->belongsTo(Package::class);
    }

    /**
     * Get the router for this payment
     */
    public function router()
    {
        // Routers are in tenant schema
        return $this->belongsTo(Router::class);
    }

    /**
     * Get the subscription created from this payment
     */
    public function subscription()
    {
        return $this->hasOne(UserSubscription::class);
    }

    /**
     * Get the user session for this payment
     */
    public function userSession()
    {
        return $this->hasOne(UserSession::class);
    }

    /**
     * Check if payment is completed
     */
    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    /**
     * Check if payment is pending
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Mark payment as completed
     */
    public function markAsCompleted(): bool
    {
        $this->status = 'completed';
        return $this->save();
    }

    /**
     * Mark payment as failed
     */
    public function markAsFailed(): bool
    {
        $this->status = 'failed';
        return $this->save();
    }
}