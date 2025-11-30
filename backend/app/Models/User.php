<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\VerifyEmailNotification;
use App\Traits\HasUuid;
use App\Traits\BelongsToTenant;
use App\Models\Scopes\TenantScope;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens, HasUuid, BelongsToTenant;

    // User roles
    const ROLE_SYSTEM_ADMIN = 'system_admin';  // Platform administrator (SaaS level)
    const ROLE_ADMIN = 'admin';                 // Tenant administrator
    const ROLE_HOTSPOT_USER = 'hotspot_user';   // End user

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope());
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'name',
        'username',
        'email',
        'password',
        'role',
        'phone_number',
        'account_number',
        'account_balance',
        'is_active',
        'last_login_at',
        'failed_login_attempts',
        'last_failed_login_at',
        'suspended_at',
        'suspended_until',
        'suspension_reason',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'id' => 'string',
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'last_login_at' => 'datetime',
        'is_active' => 'boolean',
        'account_balance' => 'decimal:2',
        'last_failed_login_at' => 'datetime',
        'suspended_at' => 'datetime',
        'suspended_until' => 'datetime',
    ];

    /**
     * Check if user is system administrator (platform level)
     */
    public function isSystemAdmin(): bool
    {
        return $this->role === self::ROLE_SYSTEM_ADMIN;
    }

    /**
     * Check if user is tenant admin
     */
    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    /**
     * Check if user is hotspot user
     */
    public function isHotspotUser(): bool
    {
        return $this->role === self::ROLE_HOTSPOT_USER;
    }

    /**
     * Check if user has admin privileges (system or tenant admin)
     */
    public function hasAdminPrivileges(): bool
    {
        return in_array($this->role, [self::ROLE_SYSTEM_ADMIN, self::ROLE_ADMIN]);
    }

    /**
     * Get user's active subscriptions
     */
    public function subscriptions()
    {
        return $this->hasMany(UserSubscription::class);
    }

    /**
     * Get user's active subscription
     */
    public function activeSubscription()
    {
        return $this->hasOne(UserSubscription::class)
            ->where('status', 'active')
            ->where('end_time', '>', now());
    }

    /**
     * Get user's payments
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Check if user has sufficient balance
     */
    public function hasSufficientBalance(float $amount): bool
    {
        return $this->account_balance >= $amount;
    }

    /**
     * Deduct from account balance
     */
    public function deductBalance(float $amount): bool
    {
        if (!$this->hasSufficientBalance($amount)) {
            return false;
        }

        $this->account_balance -= $amount;
        return $this->save();
    }

    /**
     * Add to account balance
     */
    public function addBalance(float $amount): bool
    {
        $this->account_balance += $amount;
        return $this->save();
    }

    /**
     * Update last login timestamp
     */
    public function updateLastLogin(): void
    {
        $this->last_login_at = now();
        $this->save();
    }

    /**
     * Generate unique account number
     */
    public static function generateAccountNumber(): string
    {
        do {
            // Format: HS-YYYYMMDD-XXXXX (e.g., HS-20251004-12345)
            $accountNumber = 'HS-' . date('Ymd') . '-' . str_pad(rand(1, 99999), 5, '0', STR_PAD_LEFT);
        } while (self::where('account_number', $accountNumber)->exists());

        return $accountNumber;
    }

    /**
     * Boot method to auto-generate account number for hotspot users
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($user) {
            // Auto-generate account number for hotspot users if not provided
            if ($user->role === self::ROLE_HOTSPOT_USER && empty($user->account_number)) {
                $user->account_number = self::generateAccountNumber();
            }
        });
    }

    /**
     * Get user's active subscription
     */
    public function getActiveSubscription()
    {
        return $this->subscriptions()
            ->where('status', 'active')
            ->where('end_time', '>', now())
            ->orderBy('end_time', 'desc')
            ->first();
    }

    /**
     * Send the email verification notification.
     */
    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailNotification());
    }
}
