<?php

namespace App\Jobs;

use App\Models\User;
use App\Events\UserDeleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Async job to delete user
 * Replaces synchronous user deletion in controllers
 */
class DeleteUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public string $deletedBy;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, string $deletedBy)
    {
        $this->userId = $userId;
        $this->deletedBy = $deletedBy;
        
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
            $username = $user->username;
            $email = $user->email;
            
            // Delete from RADIUS
            DB::table('radcheck')
                ->where('username', $username)
                ->delete();
                
            DB::table('radius_user_schema_mapping')
                ->where('username', $username)
                ->delete();
            
            // Delete user
            $user->delete();
            
            DB::commit();
            
            // Broadcast event
            broadcast(new UserDeleted($this->userId, $username, $email))->toOthers();
            
            Log::warning('User deleted successfully (async)', [
                'user_id' => $this->userId,
                'username' => $username,
                'deleted_by' => $this->deletedBy,
                'job' => 'DeleteUserJob',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to delete user (async)', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
                'job' => 'DeleteUserJob',
            ]);
            
            $this->release(30);
        }
    }
}
