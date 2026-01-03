<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TenantRegistration;
use App\Events\TenantEmailVerified;
use App\Events\TenantWorkspaceCreating;
use App\Events\TenantWorkspaceCreated;
use App\Jobs\AllocateTenantIpBlockJob;
use App\Jobs\SendCredentialsEmailJob;
use App\Services\TenantVpnTunnelService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Artisan;

class CreateTenantWorkspaceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $registration;
    public $tries = 3;
    public $timeout = 300;
    public $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(TenantRegistration $registration)
    {
        $this->registration = $registration;
    }

    /**
     * Execute the job.
     */
    public function handle(TenantVpnTunnelService $vpnService, \App\Services\TenantMigrationManager $migrationManager): void
    {
        Log::info('CreateTenantWorkspaceJob started', [
            'registration_id' => $this->registration->id,
            'tenant_slug' => $this->registration->tenant_slug,
            'attempt' => $this->attempts()
        ]);

        try {
            DB::beginTransaction();

            Log::info('Creating tenant workspace', [
                'registration_id' => $this->registration->id,
                'tenant_slug' => $this->registration->tenant_slug,
            ]);

            // Check if tenant already exists (handle duplicate registration attempts)
            $existingTenant = Tenant::where('slug', $this->registration->tenant_slug)->first();
            
            if ($existingTenant) {
                Log::warning('Tenant already exists, using existing tenant', [
                    'registration_id' => $this->registration->id,
                    'tenant_id' => $existingTenant->id,
                    'tenant_slug' => $this->registration->tenant_slug,
                ]);
                
                // Update registration to link to existing tenant
                $this->registration->update([
                    'tenant_id' => $existingTenant->id,
                    'status' => 'completed',
                    'error_message' => 'Tenant already exists - linked to existing workspace'
                ]);
                
                DB::commit();
                return; // Exit gracefully
            }

            // Broadcast workspace creation started
            event(new TenantWorkspaceCreating($this->registration));

            // Generate credentials
            $username = TenantRegistration::generateUsername($this->registration->tenant_slug);
            $password = TenantRegistration::generatePassword();

            // Create tenant with trial subscription
            $tenant = Tenant::create([
                'name' => $this->registration->tenant_name,
                'slug' => $this->registration->tenant_slug,
                'subdomain' => $this->registration->tenant_slug,
                'email' => $this->registration->tenant_email,
                'phone' => $this->registration->tenant_phone,
                'address' => $this->registration->tenant_address,
                'is_active' => true,
                'subscription_status' => 'trial',
                'subscription_plan' => 'monthly',
                'subscription_started_at' => now(),
                'trial_ends_at' => now()->addDays(30),
            ]);

            // Create admin user
            $user = User::create([
                'tenant_id' => $tenant->id,
                'name' => $this->registration->tenant_name . ' Admin',
                'username' => $username,
                'email' => $this->registration->tenant_email,
                'password' => Hash::make($password),
                'role' => 'admin',
                'is_active' => true,
            ]);

            // CRITICAL: Setup Tenant Schema immediately after creating tenant record
            // This creates the schema and runs migrations so tables exist for subsequent steps
            Log::info('Setting up tenant schema and running migrations', ['tenant_id' => $tenant->id]);
            if (!$migrationManager->setupTenantSchema($tenant)) {
                throw new \Exception("Failed to setup tenant schema for {$tenant->slug}");
            }

            // CRITICAL: Create RADIUS credentials in tenant schema
            Log::info('Adding RADIUS credentials for admin user', [
                'username' => $username,
                'tenant_schema' => $tenant->schema_name,
            ]);

            // Switch to tenant schema to add RADIUS credentials
            DB::statement("SET search_path TO {$tenant->schema_name}, public");

            // Add to tenant's radcheck table
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Add to tenant's radreply table
            DB::table('radreply')->insert([
                [
                    'username' => $username,
                    'attribute' => 'Service-Type',
                    'op' => ':=',
                    'value' => 'Administrative-User',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'username' => $username,
                    'attribute' => 'Tenant-ID',
                    'op' => ':=',
                    'value' => $tenant->schema_name,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);

            // Switch back to public schema
            DB::statement("SET search_path TO public");

            // Create schema mapping for multi-tenant authentication
            DB::table('radius_user_schema_mapping')->insert([
                'username' => $username,
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Log::info('RADIUS credentials and schema mapping created for admin user', [
                'username' => $username,
                'schema_name' => $tenant->schema_name,
                'tenant_id' => $tenant->id
            ]);

            // Update registration with generated credentials
            $this->registration->update([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'generated_username' => $username,
                'generated_password' => $password, // Store temporarily for email
                'status' => 'verified'
            ]);

            // Initialize VPN Tunnel for the tenant - STRICT: Fail job if VPN creation fails
            // We use a fresh instance or the injected service
            $tunnel = $vpnService->getOrCreateTenantTunnel($tenant->id);
            Log::info('Tenant VPN tunnel initialized', [
                'tenant_id' => $tenant->id,
                'interface' => $tunnel->interface_name,
                'subnet' => $tunnel->subnet_cidr,
            ]);

            DB::commit();

            Log::info('Tenant workspace created', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'username' => $username
            ]);

            // Update registration status to completed
            $this->registration->update([
                'status' => 'completed',
                'credentials_sent' => false // Will be set to true by SendCredentialsEmailJob
            ]);

            // Broadcast workspace created event
            event(new TenantWorkspaceCreated($this->registration));

            // Dispatch job to send credentials
            SendCredentialsEmailJob::dispatch($this->registration)
                ->onQueue('emails');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Failed to create tenant workspace', [
                'registration_id' => $this->registration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->registration->update([
                'status' => 'failed',
                'error_message' => 'Failed to create workspace: ' . $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CreateTenantWorkspaceJob failed permanently', [
            'registration_id' => $this->registration->id,
            'tenant_slug' => $this->registration->tenant_slug,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
            'attempts' => $this->attempts()
        ]);

        // Update registration status
        $this->registration->update([
            'status' => 'failed',
            'error_message' => 'Workspace creation failed: ' . $exception->getMessage()
        ]);
    }
}
