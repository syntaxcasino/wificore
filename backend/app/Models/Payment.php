<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    /** @use HasFactory<\Database\Factories\PaymentFactory> */
    use HasFactory;

     protected $fillable = ['mac_address', 'phone_number', 'package_id', 'amount', 'transaction_id', 'status'];

    public function package()
    {
        return $this->belongsTo(Package::class);
    }
}
