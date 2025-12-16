<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantRegistration extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'tenant_name',
        'tenant_slug',
        'tenant_email',
        'tenant_phone',
        'tenant_address',
        'generated_username',
        'generated_password',
        'email_verified',
        'email_verified_at',
        'credentials_sent',
        'credentials_sent_at',
        'tenant_id',
        'user_id',
        'status',
        'error_message',
    ];

    protected $casts = [
        'email_verified' => 'boolean',
        'credentials_sent' => 'boolean',
        'email_verified_at' => 'datetime',
        'credentials_sent_at' => 'datetime',
    ];

    /**
     * Get the tenant associated with this registration
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Get the user associated with this registration
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Generate a unique token for this registration
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate username from slug (remove hyphens)
     */
    public static function generateUsername(string $slug): string
    {
        return str_replace('-', '', $slug);
    }

    /**
     * Generate a secure random password
     */
    public static function generatePassword(): string
    {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $special = '@$!%*?&';
        
        $password = '';
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];
        
        $all = $uppercase . $lowercase . $numbers . $special;
        for ($i = 0; $i < 12; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        
        return str_shuffle($password);
    }
}
