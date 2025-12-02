<?php

namespace App\Jobs;

use App\Models\User;
use App\Events\UserCreated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Async job to create user
 * Replaces synchronous user creation in controllers
 */
class CreateUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public array $userData;
    public string $plainPassword;
    public ?int $tenantId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $userData, string $plainPassword, ?int $tenantId = null)
    {
        $this->userData = $userData;
        $this->plainPassword = $plainPassword;
        $this->tenantId = $tenantId;
        
        $this->onQueue('user-management');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tenantContext = app(\App\Services\TenantContext::class);
        
        DB::beginTransaction();
        
        try {
            // Create user in public schema (users table is always in public)
            $user = User::create([
                'tenant_id' => $this->tenantId,
                'name' => $this->userData['name'],
                'username' => $this->userData['username'],
                'email' => $this->userData['email'],
                'phone_number' => $this->userData['phone_number'] ?? null,
                'password' => Hash::make($this->plainPassword),
                'role' => $this->userData['role'],
                'is_active' => true,
                'email_verified_at' => now(),
                'account_number' => $this->generateAccountNumber($this->userData['role']),
            ]);
            
            // Add to RADIUS based on role
            if ($user->role === User::ROLE_SYSTEM_ADMIN) {
                // System admins go to public schema RADIUS tables
                DB::table('radcheck')->insert([
                    'username' => $user->username,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $this->plainPassword,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                
                Log::info('System admin RADIUS entry created in public schema', [
                    'username' => $user->username,
                ]);
            } else if ($this->tenantId) {
                // Tenant users go to tenant schema RADIUS tables
                $tenant = \App\Models\Tenant::find($this->tenantId);
                
                if ($tenant && $tenant->schema_created) {
                    // Set tenant context to create RADIUS entry in tenant schema
                    $tenantContext->runInTenantContext($tenant, function() use ($user) {
                        $searchPath = DB::selectOne("SHOW search_path")->search_path ?? 'unknown';
                        
                        DB::table('radcheck')->insert([
                            'username' => $user->username,
                            'attribute' => 'Cleartext-Password',
                            'op' => ':=',
                            'value' => $this->plainPassword,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                        
                        Log::info('Tenant user RADIUS entry created in tenant schema', [
                            'username' => $user->username,
                            'schema' => $searchPath,
                        ]);
                    });
                }
            }
            
            DB::commit();
            
            // Broadcast event
            broadcast(new UserCreated($user))->toOthers();
            
            Log::info('User created successfully (async)', [
                'user_id' => $user->id,
                'username' => $user->username,
                'role' => $user->role,
                'tenant_id' => $this->tenantId,
                'job' => 'CreateUserJob',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create user (async)', [
                'error' => $e->getMessage(),
                'username' => $this->userData['username'],
                'trace' => $e->getTraceAsString(),
                'job' => 'CreateUserJob',
            ]);
            
            $this->release(30);
        }
    }
    
    private function generateAccountNumber(string $role): string
    {
        $prefix = match($role) {
            User::ROLE_SYSTEM_ADMIN => 'SYS',
            User::ROLE_ADMIN => 'TNT',
            default => 'USR',
        };
        
        return $prefix . '-' . strtoupper(substr(md5(uniqid()), 0, 8));
    }
}
