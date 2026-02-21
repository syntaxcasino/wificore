<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Traits\HasUuid;

class WireguardPeer extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'router_id',
        'peer_name',
        'public_key',
        'endpoint',
        'allowed_ips',
        'transfer_rx',
        'transfer_tx',
        'last_handshake',
    ];

    protected $casts = [
        'last_handshake' => 'datetime',
        'transfer_rx' => 'integer',
        'transfer_tx' => 'integer',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
