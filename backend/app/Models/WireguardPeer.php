<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class WireguardPeer extends Model
{
    use HasFactory;

    protected $fillable = [
        'router_id',
        'peer_name',
        'public_key',
        'allowed_ips',
        'transfer_rx',
        'transfer_tx',
        'last_handshake',
    ];

    protected $casts = [
        'last_handshake' => 'datetime',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
