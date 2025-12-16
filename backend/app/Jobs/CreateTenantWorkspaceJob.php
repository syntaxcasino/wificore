<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Models\TenantRegistration;
use App\Events\TenantEmailVerified;
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
    public function handle(): void
    {
        try {
            DB::beginTransaction();

            // Generate credentials
            $username = TenantRegistration::generateUsername($this->registration->tenant_slug);
            $password = TenantRegistration::generatePassword();

            // Create tenant
            $tenant = Tenant::create([
                'name' => $this->registration->tenant_name,
                'slug' => $this->registration->tenant_slug,
                'email' => $this->registration->tenant_email,
                'phone' => $this->registration->tenant_phone,
                'address' => $this->registration->tenant_address,
                'status' => 'active',
                'subscription_status' => 'trial',
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
                'status' => 'active',
            ]);

            // Update registration with generated credentials
            $this->registration->update([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'generated_username' => $username,
                'generated_password' => $password, // Store temporarily for email
                'status' => 'verified'
            ]);

            // Create tenant schema
            try {
                Artisan::call('tenants:migrate', [
                    '--tenant' => $tenant->id
                ]);
            } catch (\Exception $e) {
                Log::warning('Tenant migration failed, will retry', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage()
                ]);
            }

            DB::commit();

            Log::info('Tenant workspace created', [
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'username' => $username
            ]);

            event(new TenantEmailVerified($this->registration));

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
}
