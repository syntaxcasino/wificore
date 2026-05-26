<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PppoeTimedVoucher extends Model
{
    use HasUuid, SoftDeletes;

    protected $fillable = [
        'pppoe_user_id',
        'account_number',
        'duration_label',
        'duration_hours',
        'price',
        'status',
        'transaction_id',
        'payment_reference',
        'amount_paid',
        'activated_at',
        'expires_at',
    ];

    protected $casts = [
        'price'        => 'decimal:2',
        'amount_paid'  => 'decimal:2',
        'activated_at' => 'datetime',
        'expires_at'   => 'datetime',
    ];

    public function pppoeUser()
    {
        return $this->belongsTo(PppoeUser::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active'
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    public function activate(): void
    {
        $this->status       = 'active';
        $this->activated_at = now();
        $this->expires_at   = now()->addHours($this->duration_hours);
        $this->save();
    }
}
