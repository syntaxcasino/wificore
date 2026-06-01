<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PortalSupportTicket extends Model
{
    use HasFactory;
    use HasUuid;

    protected $fillable = [
        'tenant_id',
        'pppoe_user_id',
        'account_number',
        'ticket_number',
        'subject',
        'category',
        'priority',
        'status',
        'message',
        'metadata',
        'resolved_at',
        'closed_at',
    ];

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
        'pppoe_user_id' => 'string',
        'metadata' => 'array',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function pppoeUser()
    {
        return $this->belongsTo(PppoeUser::class);
    }
}
