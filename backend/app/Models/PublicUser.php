<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PublicUser extends Model
{
    protected $table = 'public.users';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $casts = [
        'id' => 'string',
        'tenant_id' => 'string',
    ];
}
