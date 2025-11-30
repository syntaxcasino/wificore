<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SessionDisconnection extends Model
{
    use HasFactory;

    protected $fillable = [
        'radius_session_id',
        'hotspot_user_id',
        'disconnect_method',
        'disconnect_reason',
        'disconnected_at',
        'disconnected_by',
        'total_duration',
        'total_data_used',
    ];

    protected $casts = [
        'disconnected_at' => 'datetime',
        'total_duration' => 'integer',
        'total_data_used' => 'integer',
    ];

    public $timestamps = false;

    /**
     * Get the radius session
     */
    public function radiusSession()
    {
        return $this->belongsTo(RadiusSession::class);
    }

    /**
     * Get the hotspot user
     */
    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * Get the admin who disconnected
     */
    public function disconnectedBy()
    {
        return $this->belongsTo(User::class, 'disconnected_by');
    }

    /**
     * Get formatted duration
     */
    public function getFormattedDurationAttribute(): string
    {
        $seconds = $this->total_duration;
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        
        return sprintf('%dh %dm', $hours, $minutes);
    }

    /**
     * Get formatted data usage
     */
    public function getFormattedDataUsedAttribute(): string
    {
        $bytes = $this->total_data_used;
        
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' B';
    }
}
