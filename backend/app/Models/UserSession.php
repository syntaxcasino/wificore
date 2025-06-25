<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    /** @use HasFactory<\Database\Factories\UserSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'payment_id',
        'voucher',
        'mac_address',
        'start_time',
        'end_time',
        'status',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class, 'package_id');
    }
}