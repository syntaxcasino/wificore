<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'type',
        'name',
        'duration',
        'upload_speed',
        'download_speed',
        'price',
        'devices',
        'enable_burst',
        'enable_schedule',
        'hide_from_client',
    ];

    protected $casts = [
        'price' => 'float',
        'devices' => 'integer',
        'enable_burst' => 'boolean',
        'enable_schedule' => 'boolean',
        'hide_from_client' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function userSessions()
    {
        return $this->hasMany(UserSession::class);
    }
}