<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = ['name', 'description', 'price', 'duration_hours', 'mikrotik_profile'];
    protected $casts = [
        'price' => 'float'
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