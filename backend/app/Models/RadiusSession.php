<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class RadiusSession extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'hotspot_user_id',
        'payment_id',
        'package_id',
        'radacct_id',
        'username',
        'mac_address',
        'ip_address',
        'nas_ip_address',
        'session_start',
        'session_end',
        'expected_end',
        'duration_seconds',
        'bytes_in',
        'bytes_out',
        'total_bytes',
        'status',
        'disconnect_reason',
    ];

    protected $casts = [
        'session_start' => 'datetime',
        'session_end' => 'datetime',
        'expected_end' => 'datetime',
        'duration_seconds' => 'integer',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'total_bytes' => 'integer',
    ];

    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function dataUsageLogs()
    {
        return $this->hasMany(DataUsageLog::class);
    }

    public function disconnections()
    {
        return $this->hasMany(SessionDisconnection::class);
    }
}
