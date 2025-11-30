<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HotspotSession extends Model
{
    use HasFactory;

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

    /**
     * Get the hotspot user that owns the session
     */
    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * Get session duration in seconds
     */
    public function getDurationAttribute(): int
    {
        if (!$this->session_end) {
            return now()->diffInSeconds($this->session_start);
        }
        
        return $this->session_end->diffInSeconds($this->session_start);
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
    }

    /**
     * Check if session is expired
     */
    public function isExpired(): bool
    {
        if (!$this->expires_at) {
            return false;
        }
        
        return $this->expires_at->isPast();
    }

    /**
     * End the session
     */
    public function endSession(): void
    {
        $this->update([
            'is_active' => false,
            'session_end' => now(),
        ]);
    }
}
