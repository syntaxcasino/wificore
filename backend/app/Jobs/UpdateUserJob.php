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
 */
class UpdateUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public array $updateData;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, array $updateData)
    {
        $this->userId = $userId;
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
            $user->update($this->updateData);
            
            // Broadcast event
            broadcast(new UserUpdated($user))->toOthers();
            
            Log::info('User updated successfully (async)', [
                'user_id' => $user->id,
                'username' => $user->username,
                'updated_fields' => array_keys($this->updateData),
                'job' => 'UpdateUserJob',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update user (async)', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
                'job' => 'UpdateUserJob',
            ]);
            
            $this->release(30);
        }
    }
}
