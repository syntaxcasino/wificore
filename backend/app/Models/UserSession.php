<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserSession extends Model
{
    /** @use HasFactory<\Database\Factories\UserSessionFactory> */
    use HasFactory;

    protected $fillable = ['mac_address', 'voucher', 'package_id', 'start_time', 'end_time', 'upload', 'download'];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
