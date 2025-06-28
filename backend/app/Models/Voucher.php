<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'mac_address',
        'payment_id',
        'package_id',
        'duration_hours',
        'status',
        'expires_at',
        'mikrotik_response'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'mikrotik_response' => 'array'
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}