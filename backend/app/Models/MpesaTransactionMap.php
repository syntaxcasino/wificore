<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MpesaTransactionMap extends Model
{
    use HasFactory;

    protected $fillable = [
        'checkout_request_id',
        'merchant_request_id',
        'tenant_id',
        'payment_type',
        'related_id',
    ];

    /**
     * Get the tenant associated with this transaction
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
