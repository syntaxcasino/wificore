<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DataUsageLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'hotspot_user_id',
        'radius_session_id',
        'bytes_in',
        'bytes_out',
        'total_bytes',
        'recorded_at',
        'source',
    ];

    protected $casts = [
        'bytes_in' => 'integer',
        'bytes_out' => 'integer',
        'total_bytes' => 'integer',
        'recorded_at' => 'datetime',
    ];

    public $timestamps = false;

    /**
     * Get the hotspot user
     */
    public function hotspotUser()
    {
        return $this->belongsTo(HotspotUser::class);
    }

    /**
     * Get the radius session
     */
    public function radiusSession()
    {
        return $this->belongsTo(RadiusSession::class);
    }

    /**
     * Get formatted total data
     */
    public function getFormattedTotalBytesAttribute(): string
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
}
