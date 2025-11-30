<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Package;
use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Models\RadiusSession;
use App\Events\HotspotUserCreated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Async job to create hotspot user after payment
 * Replaces synchronous createHotspotUserSync() method
 */
class CreateHotspotUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public Payment $payment;
    public Package $package;

    /**
     * Create a new job instance.
     */
    public function __construct(Payment $payment, Package $package)
    {
        $this->payment = $payment;
        $this->package = $package;
        
        // High priority queue for user provisioning
        $this->onQueue('hotspot-provisioning');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        DB::beginTransaction();
        
        try {
            // Generate credentials
            $username = $this->payment->phone_number;
            $password = Str::random(12);
            
            // Create hotspot user
            $hotspotUser = HotspotUser::create([
                'username' => $username,
                'password' => bcrypt($password),
                'phone_number' => $this->payment->phone_number,
                'mac_address' => $this->payment->mac_address,
                'has_active_subscription' => true,
                'package_name' => $this->package->name,
                'package_id' => $this->package->id,
                'subscription_starts_at' => now(),
                'subscription_expires_at' => now()->addHours($this->package->duration_hours),
                'data_limit' => $this->package->data_limit_bytes ?? null,
                'is_active' => true,
                'status' => 'active',
            ]);
            
            // Create RADIUS authentication entry
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ]);
            
            // Create RADIUS reply attributes
            $radiusAttributes = [
                [
                    'username' => $username,
                    'attribute' => 'Session-Timeout',
                    'op' => ':=',
                    'value' => $this->package->duration_hours * 3600,
                ],
            ];
            
            // Add data limit if specified
            if ($this->package->data_limit_bytes) {
                $radiusAttributes[] = [
                    'username' => $username,
                    'attribute' => 'ChilliSpot-Max-Total-Octets',
                    'op' => ':=',
                    'value' => $this->package->data_limit_bytes,
                ];
            }
            
            DB::table('radreply')->insert($radiusAttributes);
            
            // Store credentials for SMS
            HotspotCredential::create([
                'hotspot_user_id' => $hotspotUser->id,
                'payment_id' => $this->payment->id,
                'username' => $username,
                'plain_password' => $password,
                'phone_number' => $this->payment->phone_number,
                'credentials_expires_at' => now()->addHours(24),
            ]);
            
            // Create initial radius session
            RadiusSession::create([
                'hotspot_user_id' => $hotspotUser->id,
                'payment_id' => $this->payment->id,
                'package_id' => $this->package->id,
                'username' => $username,
                'mac_address' => $this->payment->mac_address,
                'session_start' => now(),
                'expected_end' => now()->addHours($this->package->duration_hours),
                'status' => 'pending',
            ]);
            
            DB::commit();
            
            $credentials = [
                'hotspot_user_id' => $hotspotUser->id,
                'username' => $username,
                'password' => $password,
                'package_name' => $this->package->name,
                'expires_at' => $hotspotUser->subscription_expires_at->toIso8601String(),
            ];
            
            // Cache credentials for 5 minutes for auto-login
            Cache::put(
                "payment_credentials_{$this->payment->id}", 
                $credentials, 
                now()->addMinutes(5)
            );
            
            // Dispatch SMS job (async)
            SendCredentialsSMSJob::dispatch($hotspotUser->id)->onQueue('hotspot-sms');
            
            // Broadcast event
            broadcast(new HotspotUserCreated($hotspotUser, $this->payment, $credentials))->toOthers();
            
            Log::info('Hotspot user created successfully (async)', [
                'user_id' => $hotspotUser->id,
                'username' => $username,
                'payment_id' => $this->payment->id,
                'job' => 'CreateHotspotUserJob',
            ]);
            
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Failed to create hotspot user (async)', [
                'error' => $e->getMessage(),
                'payment_id' => $this->payment->id,
                'trace' => $e->getTraceAsString(),
                'job' => 'CreateHotspotUserJob',
            ]);
            
            // Retry the job
            $this->release(30);
        }
    }
}
