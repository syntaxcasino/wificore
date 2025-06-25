<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'price',
        'duration_hours',
        'mikrotik_profile',
        'speed_type',
    ];

    protected $casts = [
        'price' => 'float',
        'duration_hours' => 'integer',
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