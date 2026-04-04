<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuid;

/**
 * Configuration Snapshot Model
 * 
 * Stores router configuration baselines for drift detection.
 */
class ConfigSnapshot extends Model
{
    use HasFactory, HasUuid;
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
