<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job to update login statistics
 * Resets failed attempts and updates last login
 */
class UpdateLoginStatsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $userId;
    public string $ipAddress;

    /**
     * Create a new job instance.
     */
    public function __construct(string $userId, string $ipAddress)
    {
        $this->userId = $userId;
        $this->ipAddress = $ipAddress;
        
        $this->onQueue('auth-tracking');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            $user = User::findOrFail($this->userId);
            
            // Reset failed login attempts and update last login
            $user->update([
                'last_login_at' => now(),
                'failed_login_attempts' => 0,
                'last_failed_login_at' => null,
                'suspended_at' => null,
                'suspended_until' => null,
                'suspension_reason' => null
            ]);
            
            Log::info('Login stats updated', [
                'user_id' => $user->id,
                'username' => $user->username,
                'ip' => $this->ipAddress,
                'job' => 'UpdateLoginStatsJob',
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to update login stats', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
                'job' => 'UpdateLoginStatsJob',
            ]);
            
            $this->release(30);
        }
    }
}
