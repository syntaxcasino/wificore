<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;

class ApActiveSession extends Model
{
    use HasFactory, HasUuid, BelongsToTenant;

    protected $fillable = [
        'access_point_id',
        'router_id',
        'username',
        'mac_address',
        'ip_address',
        'session_id',
        'connected_at',
        'last_activity_at',
        'bytes_in',
        'bytes_out',
        'signal_strength',
    ];

    protected $casts = [
        'id' => 'string',
        'access_point_id' => 'string',
        'router_id' => 'string',
        'connected_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'signal_strength' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the access point that owns this session
     */
    public function accessPoint()
    {
        return $this->belongsTo(AccessPoint::class);
    }

    /**
     * Get the router that owns this session
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }

    /**
     * Get total bytes transferred
     */
    public function getTotalBytes(): int
    {
        return $this->bytes_in + $this->bytes_out;
    }

    /**
     * Get total bytes in MB
     */
    public function getTotalMB(): float
    {
        return round($this->getTotalBytes() / 1048576, 2);
    }

    /**
     * Get total bytes in GB
     */
    public function getTotalGB(): float
    {
        return round($this->getTotalBytes() / 1073741824, 2);
    }

    /**
     * Get formatted data usage
     */
    public function getFormattedDataUsage(): string
    {
        $bytes = $this->getTotalBytes();
        
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' B';
        }
    }

    /**
     * Get session duration in seconds
     */
    public function getDurationSeconds(): int
    {
        return now()->diffInSeconds($this->connected_at);
    }

    /**
     * Get session duration in minutes
     */
    public function getDurationMinutes(): int
    {
        return now()->diffInMinutes($this->connected_at);
    }

    /**
     * Get formatted session duration
     */
    public function getFormattedDuration(): string
    {
        $seconds = $this->getDurationSeconds();
        
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;
        
        if ($hours > 0) {
            return sprintf('%dh %dm', $hours, $minutes);
        } elseif ($minutes > 0) {
            return sprintf('%dm %ds', $minutes, $secs);
        } else {
            return sprintf('%ds', $secs);
        }
    }

    /**
     * Check if session is active (had activity in last 5 minutes)
     */
    public function isActive(): bool
    {
        if (!$this->last_activity_at) {
            return true; // Just connected
        }
        
        return $this->last_activity_at->diffInMinutes(now()) <= 5;
    }

    /**
     * Check if session is idle
     */
    public function isIdle(): bool
    {
        return !$this->isActive();
    }

    /**
     * Scope to get active sessions
     */
    public function scopeActive($query)
    {
        return $query->where('last_activity_at', '>=', now()->subMinutes(5))
                     ->orWhereNull('last_activity_at');
    }

    /**
     * Scope to get sessions by username
     */
    public function scopeByUsername($query, string $username)
    {
        return $query->where('username', $username);
    }

    /**
     * Scope to get sessions by MAC address
     */
    public function scopeByMac($query, string $mac)
    {
        return $query->where('mac_address', $mac);
    }
}
