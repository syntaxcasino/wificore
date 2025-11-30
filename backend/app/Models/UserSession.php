<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    /** @use HasFactory<\Database\Factories\UserSessionFactory> */
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'payment_id',
        'voucher',
        'mac_address',
        'start_time',
        'end_time',
        'status',
        'data_used',
        'data_upload',
        'data_download',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'data_used' => 'integer',
        'data_upload' => 'integer',
        'data_download' => 'integer',
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