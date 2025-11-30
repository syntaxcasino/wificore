<?php

namespace App\Jobs;

use App\Models\User;
use App\Events\AccountSuspended;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async job to track failed login attempts
 * Suspends account after 5 failed attempts
 */
class TrackFailedLoginJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $userId;
    public string $ipAddress;

    /**
     * Create a new job instance.
     */
    public function __construct(int $userId, string $ipAddress)
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
            
            // Increment failed attempts
            $user->increment('failed_login_attempts');
            $user->update(['last_failed_login_at' => now()]);
            
            Log::warning('Failed login attempt tracked', [
                'user_id' => $user->id,
                'username' => $user->username,
                'failed_attempts' => $user->failed_login_attempts,
                'ip' => $this->ipAddress,
                'job' => 'TrackFailedLoginJob',
            ]);
            
            // Suspend account after 5 failed attempts
            if ($user->failed_login_attempts >= 5) {
                $suspendedUntil = now()->addMinutes(30);
                
                $user->update([
                    'suspended_at' => now(),
                    'suspended_until' => $suspendedUntil,
                    'suspension_reason' => 'Too many failed login attempts'
                ]);
                
                Log::alert('Account suspended due to failed login attempts', [
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'failed_attempts' => $user->failed_login_attempts,
                    'suspended_until' => $suspendedUntil,
                    'ip' => $this->ipAddress,
                    'job' => 'TrackFailedLoginJob',
                ]);
                
                // Broadcast suspension event
                broadcast(new AccountSuspended(
                    $user,
                    $suspendedUntil->toIso8601String(),
                    'Too many failed login attempts',
                    $this->ipAddress
                ))->toOthers();
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to track login attempt', [
                'error' => $e->getMessage(),
                'user_id' => $this->userId,
                'trace' => $e->getTraceAsString(),
                'job' => 'TrackFailedLoginJob',
            ]);
            
            $this->release(30);
        }
    }
}
