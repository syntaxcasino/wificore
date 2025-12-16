<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Events\TenantCreated;
use App\Notifications\TenantCredentialsEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Async job to create tenant with admin user
 * Replaces synchronous tenant registration
 */
class CreateTenantJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $tenantData;
    public array $adminData;
    public string $plainPassword;

    /**
     * Create a new job instance.
     */
    public function __construct(array $tenantData, array $adminData, string $plainPassword)
    {
        $this->tenantData = $tenantData;
        $this->adminData = $adminData;
        $this->plainPassword = $plainPassword;
        
        $this->onQueue('tenant-management');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info('CreateTenantJob started', [
                'tenant_slug' => $this->tenantData['slug'],
                'admin_username' => $this->adminData['username'],
            ]);
            
            // Create tenant - Tenant model boot event will:
            // 1. Generate secure schema name (ts_xxxxxxxxxxxx)
            // 2. Create schema and run migrations
            // 3. Set schema_created = true
            $tenant = Tenant::create([
                'name' => $this->tenantData['name'],
                'slug' => $this->tenantData['slug'],
                'subdomain' => $this->tenantData['slug'],
                'email' => $this->tenantData['email'],
                'phone' => $this->tenantData['phone'] ?? null,
                'address' => $this->tenantData['address'] ?? null,
                'is_active' => true,
                'trial_ends_at' => now()->addDays(30),
                'public_packages_enabled' => true,
                'public_registration_enabled' => true,
                'settings' => [
                    'timezone' => 'Africa/Nairobi',
                    'currency' => 'KES',
                    'max_routers' => 5,
                    'max_users' => 100,
                    'features' => [
                        'vpn' => true,
                        'analytics' => true,
                        'api_access' => false,
                    ],
                ],
                'branding' => [
                    'logo_url' => null,
                    'primary_color' => '#3b82f6',
                    'secondary_color' => '#10b981',
                    'company_name' => $this->tenantData['name'],
                    'tagline' => null,
                    'support_email' => $this->tenantData['email'],
                    'support_phone' => $this->tenantData['phone'] ?? null,
                ],
            ]);
            
            Log::info('Tenant created with schema', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
                'schema_created' => $tenant->schema_created,
            ]);
            
            // Wait a moment for schema creation to complete
            sleep(2);
            
            // Create admin user
            $adminUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $this->adminData['name'],
                'username' => $this->adminData['username'],
                'email' => $this->adminData['email'],
                'phone_number' => $this->adminData['phone'] ?? null,
                'password' => Hash::make($this->plainPassword),
                'role' => User::ROLE_ADMIN,
                'is_active' => true,
                'email_verified_at' => now(),
                'account_number' => 'TNT-' . strtoupper(Str::random(8)),
            ]);
            
            Log::info('Admin user created, adding RADIUS credentials', [
                'username' => $this->adminData['username'],
                'tenant_schema' => $tenant->schema_name,
            ]);
            
            // Add RADIUS credentials to TENANT schema
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            
            // Add to tenant's radcheck table
            DB::table('radcheck')->insert([
                'username' => $this->adminData['username'],
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $this->plainPassword,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            // Add to tenant's radreply table
            DB::table('radreply')->insert([
                [
                    'username' => $this->adminData['username'],
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Administrative-User',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'username' => $this->adminData['username'],
                    'attribute' => 'Tenant-ID',
                    'op' => ':=',
                    'value' => $tenant->schema_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            
            // Switch back to public schema
            DB::statement("SET search_path TO public");
            
            // Add schema mapping in public schema
            DB::table('radius_user_schema_mapping')->insert([
                'username' => $this->adminData['username'],
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id,
                'user_role' => User::ROLE_ADMIN,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            Log::info('RADIUS credentials added successfully', [
                'username' => $this->adminData['username'],
                'tenant_schema' => $tenant->schema_name,
            ]);
            
            // Broadcast event
            broadcast(new TenantCreated($tenant, $adminUser))->toOthers();
            
            // Send credentials email
            $tenant->notify(new TenantCredentialsEmail(
                $tenant->name,
                $tenant->slug,
                $this->adminData['username'],
                $this->plainPassword,
                $tenant->slug
            ));
            
            // Mark credentials as sent
            $tenant->update([
                'is_active' => true,
                'settings' => array_merge($tenant->settings, [
                    'credentials_sent' => true,
                    'credentials_sent_at' => now()->toIso8601String(),
                ])
            ]);
            
            Log::info('Tenant created successfully - credentials email sent', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'admin_user_id' => $adminUser->id,
                'job' => 'CreateTenantJob',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create tenant (async)', [
                'error' => $e->getMessage(),
                'tenant_slug' => $this->tenantData['slug'],
                'trace' => $e->getTraceAsString(),
                'job' => 'CreateTenantJob',
            ]);
            
            $this->release(60);
        }
    }
}
