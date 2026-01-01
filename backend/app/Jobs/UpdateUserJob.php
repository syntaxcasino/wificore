<?php

namespace App\Jobs;

use App\Models\User;
use App\Events\UserUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job to update user
 * Replaces synchronous user updates in controllers
 * SECURITY: Validates tenant_id to prevent cross-tenant updates
 */
class UpdateUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $userId;
    public string $tenantId;
    public array $updateData;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $tenantId, array $updateData)
    {
        $this->userId = $userId;
        $this->tenantId = $tenantId;
        $this->updateData = $updateData;
        
        $this->onQueue('user-management');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $user = User::findOrFail($this->userId);
            
            // SECURITY: Validate user belongs to the expected tenant
            if ($user->tenant_id !== $this->tenantId) {
                Log::error('Attempted cross-tenant user update blocked', [
                    'user_id' => $this->userId,
                    'user_tenant_id' => $user->tenant_id,
                    'expected_tenant_id' => $this->tenantId,
                    'job' => 'UpdateUserJob',
                ]);
                throw new \Exception("Tenant mismatch: User {$this->userId} does not belong to tenant {$this->tenantId}");
            }
            
            $user->update($this->updateData);
            
            // Broadcast event
            broadcast(new UserUpdated($user))->toOthers();
            
            Log::info('User updated successfully (async)', [
                'user_id' => $user->id,
                'username' => $user->username,
                'tenant_id' => $user->tenant_id,
                'updated_fields' => array_keys($this->updateData),
                'job' => 'UpdateUserJob',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update user (async)', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'tenant_id' => $this->tenantId,
                'trace' => $e->getTraceAsString(),
                'job' => 'UpdateUserJob',
            ]);
            
            $this->release(30);
        }
    }
}
