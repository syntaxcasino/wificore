<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasUuid;

class Tenant extends Model
{
    use HasFactory, HasUuid, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'subdomain',
        'custom_domain',
        'schema_name',
        'email',
        'phone',
        'address',
        'settings',
        'branding',
        'is_active',
        'is_suspended',
        'suspended_at',
        'trial_ends_at',
        'suspension_reason',
        'schema_created',
        'schema_created_at',
        'public_packages_enabled',
        'public_registration_enabled',
    ];

    protected $casts = [
        'id' => 'string',
        'settings' => 'array',
        'branding' => 'array',
        'is_active' => 'boolean',
        'is_suspended' => 'boolean',
        'schema_created' => 'boolean',
        'public_packages_enabled' => 'boolean',
        'public_registration_enabled' => 'boolean',
        'trial_ends_at' => 'datetime',
        'suspended_at' => 'datetime',
        'schema_created_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Get users belonging to this tenant
     */
    public function users()
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get routers belonging to this tenant
     */
    public function routers()
    {
        return $this->hasMany(Router::class);
    }

    /**
     * Get packages belonging to this tenant
     */
    public function packages()
    {
        return $this->hasMany(Package::class);
    }

    /**
     * Get payments belonging to this tenant
     */
    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * Check if tenant is active
     */
    public function isActive(): bool
    {
        return $this->is_active && !$this->suspended_at;
    }

    /**
     * Check if tenant is suspended
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Check if tenant is on trial
     */
    public function isOnTrial(): bool
    {
        return $this->trial_ends_at && $this->trial_ends_at > now();
    }

    /**
     * Suspend tenant
     */
    public function suspend(string $reason = null): bool
    {
        $this->suspended_at = now();
        $this->suspension_reason = $reason;
        return $this->save();
    }

    /**
     * Activate tenant
     */
    public function activate(): bool
    {
        $this->suspended_at = null;
        $this->suspension_reason = null;
        $this->is_active = true;
        return $this->save();
    }

    /**
     * Scope to get active tenants
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->whereNull('suspended_at');
    }

    /**
     * Scope to get suspended tenants
     */
    public function scopeSuspended($query)
    {
        return $query->whereNotNull('suspended_at');
    }

    /**
     * Get tenant setting
     */
    public function getSetting(string $key, $default = null)
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Set tenant setting
     */
    public function setSetting(string $key, $value): bool
    {
        $settings = $this->settings ?? [];
        data_set($settings, $key, $value);
        $this->settings = $settings;
        return $this->save();
    }

    /**
     * Get full subdomain URL
     */
    public function getSubdomainUrl(string $protocol = 'https'): string
    {
        if ($this->custom_domain) {
            return "{$protocol}://{$this->custom_domain}";
        }

        $baseDomain = config('app.base_domain', 'yourdomain.com');
        return "{$protocol}://{$this->subdomain}.{$baseDomain}";
    }

    /**
     * Get public packages URL
     */
    public function getPublicPackagesUrl(): string
    {
        return $this->getSubdomainUrl() . '/packages';
    }

    /**
     * Get branding setting
     */
    public function getBranding(string $key, $default = null)
    {
        return data_get($this->branding, $key, $default);
    }

    /**
     * Set branding setting
     */
    public function setBranding(string $key, $value): bool
    {
        $branding = $this->branding ?? [];
        data_set($branding, $key, $value);
        $this->branding = $branding;
        return $this->save();
    }

    /**
     * Boot method to handle model events
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($tenant) {
            if (empty($tenant->id)) {
                $tenant->id = \Illuminate\Support\Str::uuid();
            }

            // Generate secure schema name if not set
            if (empty($tenant->schema_name)) {
                $tenant->schema_name = \App\Services\TenantMigrationManager::generateSecureSchemaName($tenant->slug);
            }
        });

        static::created(function ($tenant) {
            // Auto-create tenant schema and run migrations
            $migrationManager = app(\App\Services\TenantMigrationManager::class);

            if ($migrationManager->setupTenantSchema($tenant)) {
                $shouldAutoSeed = config('multitenancy.auto_seed_schema', false);

                if ($shouldAutoSeed) {
                    $seedWithTestData = config('multitenancy.seed_with_test_data')
                        ?? app()->environment(['local', 'development', 'testing']);

                    $migrationManager->seedTenantSchema($tenant, (bool) $seedWithTestData);
                }
            }
        });

        static::deleting(function ($tenant) {
            // Clean up tenant schema when tenant is deleted
            $migrationManager = app(\App\Services\TenantMigrationManager::class);
            $migrationManager->dropTenantSchema($tenant);
        });
    }
}
