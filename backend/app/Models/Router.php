<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Router extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'ip_address',
        'model',
        'os_version',
        'last_seen',
        'port',
        'username',
        'password',
        'location',
        'status',
        'interface_assignments',
        'configurations',
        'config_token',
    ];

    protected $hidden = ['password'];

    protected $casts = [
        'last_seen' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'interface_assignments' => 'array',
        'configurations' => 'array',
    ];
   public function wireguardPeers()
    {
        return $this->hasMany(WireguardPeer::class, 'router_id', 'id');
    }

    public function routerConfigs()
    {
        return $this->hasMany(RouterConfig::class, 'router_id', 'id');
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'router_id', 'id');
    } 
    
} 
 