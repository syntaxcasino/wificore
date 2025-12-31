<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class HotspotSession extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'hotspot_user_id',
        'mac_address',
        'ip_address',
        'session_start',
        'session_end',
        'last_activity',
        'expires_at',
        'is_active',
        'bytes_uploaded',
        'bytes_downloaded',
        'total_bytes',
        'user_agent',
        'device_type',
    ];

    protected $casts = [
        'session_start' => 'datetime',
        'session_end' => 'datetime',
        'last_activity' => 'datetime',
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'bytes_uploaded' => 'integer',
        'bytes_downloaded' => 'integer',
        'total_bytes' => 'integer',
    ];

    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }
}
