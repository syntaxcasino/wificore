<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class UserSession extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
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
    ];

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }
}
