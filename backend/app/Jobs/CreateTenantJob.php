<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Events\TenantCreated;
use App\Jobs\AllocateTenantIpBlockJob;
use App\Jobs\SendTenantCredentialsEmailJob;
use App\Services\TenantVpnTunnelService;
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

    public int $tries = 3;
    public int $maxExceptions = 3;
    public array $backoff = [30, 60, 120];

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
     *
     * Architecture note: Schema creation and migrations (DDL) run OUTSIDE any
     * DB::beginTransaction() because PostgreSQL aborts the entire transaction
     * on any DDL error, making subsequent DML impossible.  The job is split
     * into phases:
     *   Phase 1 – Create Tenant + User records (DML, transactional)
     *   Phase 2 – Create schema & run migrations (DDL, non-transactional)
     *   Phase 3 – Seed RADIUS credentials & VPN tunnel (DML, transactional)
     */
    public function handle(TenantVpnTunnelService $vpnService, \App\Services\TenantMigrationManager $migrationManager): void
    {
        $tenant = null;
        $adminUser = null;

        try {
            Log::info('CreateTenantJob started', [
                'tenant_slug' => $this->tenantData['slug'],
                'admin_username' => $this->adminData['username'],
            ]);

            // ── Phase 1: Create Tenant + User (DML only, safe to transact) ──
            DB::beginTransaction();
            
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

            DB::commit();

            Log::info('Phase 1 complete: Tenant and User created', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
                'admin_user_id' => $adminUser->id,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Phase 1 failed: Could not create tenant/user', [
                'error' => $e->getMessage(),
                'tenant_slug' => $this->tenantData['slug'],
            ]);
            throw $e;
        }

        try {
            // ── Phase 2: Schema creation + migrations (DDL — NO transaction) ──
            Log::info('Phase 2: Setting up tenant schema and running migrations', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
            ]);

            if (!$migrationManager->setupTenantSchema($tenant)) {
                throw new \Exception("Failed to setup tenant schema for {$tenant->slug}");
            }

            Log::info('Phase 2 complete: Schema and migrations ready', [
                'tenant_id' => $tenant->id,
                'schema_name' => $tenant->schema_name,
            ]);

        } catch (\Exception $e) {
            Log::error('Phase 2 failed: Schema setup error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);

            // Clean up Phase 1 records
            try {
                $adminUser?->forceDelete();
                $tenant?->forceDelete();
            } catch (\Exception $cleanupError) {
                Log::error('Cleanup after Phase 2 failure also failed', [
                    'error' => $cleanupError->getMessage(),
                ]);
            }

            throw $e;
        }

        try {
            // ── Phase 3: RADIUS credentials, VPN tunnel, finalize (DML) ──
            DB::beginTransaction();

            DB::statement("SET search_path TO public");

            Log::info('Phase 3: Adding RADIUS credentials', [
                'username' => $this->adminData['username'],
                'tenant_schema' => $tenant->schema_name,
            ]);
            
            // Add RADIUS credentials to TENANT schema
            DB::statement("SET search_path TO {$tenant->schema_name}, public");
            
            DB::table('radcheck')->insert([
                'username' => $this->adminData['username'],
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $this->plainPassword,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
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
                    'value' => (string) $tenant->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            
            DB::statement("SET search_path TO public");
            
            DB::table('public.radius_user_schema_mapping')->insert([
                'username' => strtolower(trim($this->adminData['username'])),
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id,
                'user_role' => User::ROLE_ADMIN,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Initialize VPN Tunnel for the tenant
            $tunnel = $vpnService->getOrCreateTenantTunnel($tenant->id);
            Log::info('Tenant VPN tunnel initialized', [
                'tenant_id' => $tenant->id,
                'interface' => $tunnel->interface_name,
                'subnet' => $tunnel->subnet_cidr,
            ]);

            DB::commit();
            
            Log::info('Tenant created successfully', [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
                'admin_user_id' => $adminUser->id,
                'job' => 'CreateTenantJob',
            ]);

            // Broadcast event
            broadcast(new TenantCreated($tenant, $adminUser))->toOthers();
            
            // EVENT-BASED: Dispatch job to allocate IP block (async)
            AllocateTenantIpBlockJob::dispatch($tenant->id)
                ->onQueue('tenant-management');
            
            // EVENT-BASED: Dispatch job to send credentials email (async)
            SendTenantCredentialsEmailJob::dispatch(
                $tenant->id,
                $this->adminData['username'],
                $this->plainPassword
            )->onQueue('emails');
            
        } catch (\Exception $e) {
            DB::rollBack();

            try {
                DB::statement("SET search_path TO public");
            } catch (\Exception $ignored) {
                DB::reconnect();
            }
            
            Log::error('Phase 3 failed: RADIUS/VPN setup error', [
                'error' => $e->getMessage(),
                'tenant_slug' => $this->tenantData['slug'],
                'job' => 'CreateTenantJob',
            ]);
            
            throw $e;
        }
    }

    /**
     * Handle a job failure after all retries exhausted.
     */
    public function failed(?\Throwable $exception): void
    {
        Log::critical('CreateTenantJob permanently failed after all retries', [
            'tenant_slug' => $this->tenantData['slug'],
            'admin_username' => $this->adminData['username'],
            'error' => $exception?->getMessage(),
            'job' => 'CreateTenantJob',
        ]);
    }
}
