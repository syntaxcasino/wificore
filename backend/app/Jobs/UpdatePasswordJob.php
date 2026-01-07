<?php

namespace App\Jobs;

use App\Models\User;
use App\Events\PasswordChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Async job to update user password
 * Updates both database and RADIUS
 * SECURITY: Validates tenant_id to prevent cross-tenant password changes
 */
class UpdatePasswordJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public string $userId;
    public string $tenantId;
    public string $newPassword;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $tenantId, string $newPassword)
    {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->newPassword = $newPassword;
        
        $this->onQueue('user-management');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::beginTransaction();
        
        try {
            $user = User::findOrFail($this->userId);
            
            // SECURITY: Validate user belongs to the expected tenant
            if ($user->tenant_id !== $this->tenantId) {
                Log::error('Attempted cross-tenant password update blocked', [
                    'user_id' => $this->userId,
                    'user_tenant_id' => $user->tenant_id,
                    'expected_tenant_id' => $this->tenantId,
                    'job' => 'UpdatePasswordJob',
                ]);
                throw new \Exception("Tenant mismatch: User {$this->userId} does not belong to tenant {$this->tenantId}");
            }
            
            // Update password in database
            $user->update([
                'password' => Hash::make($this->newPassword),
            ]);
            
            // Update password in RADIUS
            DB::table('radcheck')
                ->where('username', $user->username)
                ->where('attribute', 'Cleartext-Password')
                ->update(['value' => $this->newPassword]);
            
            DB::commit();
            
            // Broadcast event
            broadcast(new PasswordChanged($user))->toOthers();
            
            Log::info('Password updated successfully (async)', [
                'user_id' => $user->id,
                'username' => $user->username,
                'tenant_id' => $user->tenant_id,
                'job' => 'UpdatePasswordJob',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to update password (async)', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'tenant_id' => $this->tenantId,
                'trace' => $e->getTraceAsString(),
                'job' => 'UpdatePasswordJob',
            ]);
            
            $this->release(30);
        }
    }
}
