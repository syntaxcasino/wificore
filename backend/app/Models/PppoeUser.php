<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class PppoeUser extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'username',
        'password',
        'package_id',
        'router_id',
        'expires_at',
        'rate_limit',
        'simultaneous_use',
        'is_active',
        'status',
    ];

    protected $hidden = [
        'password',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_active' => 'boolean',
        'simultaneous_use' => 'integer',
    ];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
