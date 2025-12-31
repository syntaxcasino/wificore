<?php

namespace App\Jobs;

use App\Models\Payment;
use App\Models\Package;
use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Models\RadiusSession;
use App\Events\HotspotUserCreated;
use App\Traits\TenantAwareJob;
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
    use TenantAwareJob;

    public $paymentId;
    public $packageId;

    /**
     * Create a new job instance.
     */
    public function __construct($paymentId, $packageId, $tenantId)
    {
        $this->paymentId = $paymentId;
        $this->packageId = $packageId;
        $this->setTenantContext($tenantId);
        
        // High priority queue for user provisioning
        $this->onQueue('hotspot-provisioning');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executeInTenantContext(function() {
            DB::beginTransaction();
            
            try {
                $payment = Payment::find($this->paymentId);
                $package = Package::find($this->packageId); // Package is in public schema but accessible via TenantScope? No, Package is in public schema. 
                // Wait, Package is in public schema. But we are in tenant context.
                // TenantAwareJob sets search_path to "tenant, public". So we can access public tables.
                
                if (!$payment) {
                    Log::error('Payment not found for hotspot user creation', [
                        'payment_id' => $this->paymentId,
                        'tenant_id' => $this->tenantId
                    ]);
                    DB::rollBack();
                    return;
                }

                if (!$package) {
                    // Packages are in public schema. We might need to query public.packages directly if model scope interferes?
                    // But wait, if Package model has no TenantScope (it was removed/not added for global packages?), it should work.
                    // Or if it has TenantScope, it filters by tenant_id. Since we set Auth::user() with tenant_id in TenantAwareJob, it should work fine.
                    Log::error('Package not found for hotspot user creation', [
                        'package_id' => $this->packageId,
                        'tenant_id' => $this->tenantId
                    ]);
                    DB::rollBack();
                    return;
                }

                // Generate credentials
                $username = $payment->phone_number;
                $password = Str::random(12);
                
                // Create hotspot user
                $hotspotUser = HotspotUser::create([
                    'username' => $username,
                    'password' => bcrypt($password),
                    'phone_number' => $payment->phone_number,
                    'mac_address' => $payment->mac_address,
                    'has_active_subscription' => true,
                    'package_name' => $package->name,
                    'package_id' => $package->id,
                    'subscription_starts_at' => now(),
                    'subscription_expires_at' => now()->addHours($package->duration_hours),
                    'data_limit' => $package->data_limit_bytes ?? null,
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
                        'value' => $package->duration_hours * 3600,
                    ],
                ];
                
                // Add data limit if specified
                if ($package->data_limit_bytes) {
                    $radiusAttributes[] = [
                        'username' => $username,
                        'attribute' => 'ChilliSpot-Max-Total-Octets',
                        'op' => ':=',
                        'value' => $package->data_limit_bytes,
                    ];
                }
                
                DB::table('radreply')->insert($radiusAttributes);
                
                // Store credentials for SMS
                HotspotCredential::create([
                    'hotspot_user_id' => $hotspotUser->id,
                    'payment_id' => $payment->id,
                    'username' => $username,
                    'plain_password' => $password,
                    'phone_number' => $payment->phone_number,
                    'credentials_expires_at' => now()->addHours(24),
                ]);
                
                // Create initial radius session
                RadiusSession::create([
                    'hotspot_user_id' => $hotspotUser->id,
                    'payment_id' => $payment->id,
                    'package_id' => $package->id,
                    'username' => $username,
                    'mac_address' => $payment->mac_address,
                    'session_start' => now(),
                    'expected_end' => now()->addHours($package->duration_hours),
                    'status' => 'pending',
                ]);
                
                DB::commit();
                
                $credentials = [
                    'hotspot_user_id' => $hotspotUser->id,
                    'username' => $username,
                    'password' => $password,
                    'package_name' => $package->name,
                    'expires_at' => $hotspotUser->subscription_expires_at->toIso8601String(),
                ];
                
                // Cache credentials for 5 minutes for auto-login
                Cache::put(
                    "payment_credentials_{$payment->id}", 
                    $credentials, 
                    now()->addMinutes(5)
                );
                
                // Dispatch SMS job (async)
                SendCredentialsSMSJob::dispatch($hotspotUser->id, $this->tenantId)->onQueue('hotspot-sms');
                
                // Broadcast event
                broadcast(new HotspotUserCreated($hotspotUser, $payment, $credentials))->toOthers();
                
                Log::info('Hotspot user created successfully (async)', [
                    'user_id' => $hotspotUser->id,
                    'username' => $username,
                    'payment_id' => $payment->id,
                    'job' => 'CreateHotspotUserJob',
                    'tenant_id' => $this->tenantId
                ]);
                
            } catch (\Exception $e) {
                DB::rollBack();
                
                Log::error('Failed to create hotspot user (async)', [
                    'error' => $e->getMessage(),
                    'payment_id' => $this->paymentId,
                    'trace' => $e->getTraceAsString(),
                    'job' => 'CreateHotspotUserJob',
                    'tenant_id' => $this->tenantId
                ]);
                
                // Retry the job
                $this->release(30);
            }
        });
    }
}
