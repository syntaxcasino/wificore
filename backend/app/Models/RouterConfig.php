<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasUuid;

class RouterConfig extends Model
{
    use HasUuid;
    
    protected $table = 'router_configs';

    protected $fillable = [
        'router_id',
        'config_type',
        'config_content',
        'created_at',
        'updated_at',
    ];
    
    protected $casts = [
        'id' => 'string',
    ];

    /**
     * Get the router that owns the configuration.
     */
    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
