<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RadiusSession extends Model
{
    use HasFactory;

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

    /**
     * Get the hotspot user that owns the session
     */
    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * Get the payment associated with the session
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Get the package associated with the session
     */
    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    /**
     * Get the RADIUS accounting record
     */
    public function radacct()
    {
        return $this->belongsTo(Radacct::class, 'radacct_id', 'radacctid');
    }

    /**
     * Get disconnection records
     */
    public function disconnections()
    {
        return $this->hasMany(SessionDisconnection::class);
    }

    /**
     * Get data usage logs
     */
    public function dataUsageLogs()
    {
        return $this->hasMany(DataUsageLog::class);
    }

    /**
     * Check if session is active
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && $this->expected_end->isFuture();
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        return $this->expected_end->isPast() || $this->status === 'expired';
    }

    /**
     * Get formatted data usage
     */
    public function getFormattedDataUsageAttribute(): string
    {
        $bytes = $this->total_bytes;
        
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }

    /**
     * Scope for active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('expected_end', '>', now());
    }

    /**
     * Scope for expired sessions
     */
    public function scopeExpired($query)
    {
        return $query->where(function ($q) {
            $q->where('expected_end', '<=', now())
              ->orWhere('status', 'expired');
        });
    }
}
