<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouterConfig extends Model
{
    protected $table = 'router_configs';

    protected $fillable = [
        'router_id',
        'config_type',
        'config_content',
        'created_at',
        'updated_at',
    ];

    /**
     * Get the router that owns the configuration.
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
