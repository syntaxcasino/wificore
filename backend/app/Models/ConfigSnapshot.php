<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Configuration Snapshot Model
 * 
 * Stores router configuration baselines for drift detection.
 */
class ConfigSnapshot extends Model
{
    protected $table = 'config_snapshots';

    protected $fillable = [
        'router_id',
        'config_text',
        'config_hash',
        'parsed_config',
        'created_by',
    ];

    protected $casts = [
        'parsed_config' => 'array',
    ];

    /**
     * Get the router this snapshot belongs to
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
