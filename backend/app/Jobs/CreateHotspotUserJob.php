<?php

namespace App\Jobs;

use App\Helpers\PackageExpiryHelper;
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
use Illuminate\Support\Carbon;
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

    public int $tries = 3;
    public int $maxExceptions = 3;
    public array $backoff = [10, 30, 60];

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
                $username    = $payment->phone_number;
                $password    = Str::random(12);
                $periodStart = Carbon::parse($payment->payment_date ?? $payment->created_at ?? now());
                $periodEnd   = PackageExpiryHelper::calculateExpiresAt($package, $periodStart);

                // Check for existing user with same phone number (reactivation scenario)
                $existingUser = HotspotUser::where(function ($query) use ($payment) {
                    $query->where('phone_number', $payment->phone_number)
                        ->orWhere('username', $payment->phone_number);
                })->first();

                if ($existingUser) {
                    // REACTIVATION: Existing user found - extend subscription and update credentials
                    Log::info('Existing hotspot user found - reactivating', [
                        'user_id' => $existingUser->id,
                        'username' => $existingUser->username,
                        'payment_id' => $payment->id,
                        'previous_expiry' => $existingUser->subscription_expires_at,
                        'new_expiry' => $periodEnd,
                    ]);

                    // Remove any Auth-Type := Reject (unblock if previously blocked)
                    DB::table('radcheck')
                        ->where('username', $existingUser->username)
                        ->where('attribute', 'Auth-Type')
                        ->where('value', 'Reject')
                        ->delete();

                    // Update RADIUS password
                    DB::table('radcheck')
                        ->where('username', $existingUser->username)
                        ->where('attribute', 'Cleartext-Password')
                        ->delete();

                    DB::table('radcheck')->insert([
                        'username' => $existingUser->username,
                        'attribute' => 'Cleartext-Password',
                        'op' => ':=',
                        'value' => $password,
                    ]);

                    // Remove old reply attributes and insert new ones
                    DB::table('radreply')
                        ->where('username', $existingUser->username)
                        ->whereIn('attribute', [
                            'Session-Timeout',
                            'Idle-Timeout',
                            'Mikrotik-Rate-Limit',
                            'ChilliSpot-Max-Total-Octets',
                        ])
                        ->delete();

                    // Session timeout = package duration in seconds
                    $sessionTimeoutSecs = PackageExpiryHelper::durationInDays($package) * 86400;

                    $radiusAttributes = [
                        [
                            'username'  => $existingUser->username,
                            'attribute' => 'Session-Timeout',
                            'op'        => ':=',
                            'value'     => (string) $sessionTimeoutSecs,
                        ],
                        [
                            'username'  => $existingUser->username,
                            'attribute' => 'Idle-Timeout',
                            'op'        => ':=',
                            'value'     => '300',
                        ],
                    ];

                    // Enforce bandwidth via Mikrotik-Rate-Limit
                    $rateLimit = null;
                    if ($package->download_speed && $package->upload_speed) {
                        $rateLimit = \App\Services\MikroTik\BandwidthHelper::formatMikrotikRateLimit(
                            (string) $package->download_speed,
                            (string) $package->upload_speed
                        );
                    }
                    if ($rateLimit) {
                        $radiusAttributes[] = [
                            'username'  => $existingUser->username,
                            'attribute' => 'Mikrotik-Rate-Limit',
                            'op'        => ':=',
                            'value'     => $rateLimit,
                        ];
                    }

                    // Add data limit if specified
                    if ($package->data_limit_bytes) {
                        $radiusAttributes[] = [
                            'username' => $existingUser->username,
                            'attribute' => 'ChilliSpot-Max-Total-Octets',
                            'op' => ':=',
                            'value' => $package->data_limit_bytes,
                        ];
                    }

                    DB::table('radreply')->insert($radiusAttributes);

                    // Reset data used if package changed (optional - can be configured)
                    $resetDataUsed = $existingUser->package_id !== $package->id;

                    // Update existing user record
                    $existingUser->update([
                        'password'                => bcrypt($password),
                        'mac_address'             => $payment->mac_address ?? $existingUser->mac_address,
                        'has_active_subscription' => true,
                        'package_name'            => $package->name,
                        'package_id'              => $package->id,
                        'subscription_starts_at'  => $periodStart,
                        'subscription_expires_at' => $periodEnd,
                        'data_limit'              => $package->data_limit_bytes ?? null,
                        'data_used'               => $resetDataUsed ? 0 : $existingUser->data_used,
                        'is_active'               => true,
                        'status'                  => 'active',
                    ]);

                    // Store credentials for SMS
                    HotspotCredential::create([
                        'hotspot_user_id' => $existingUser->id,
                        'payment_id' => $payment->id,
                        'username' => $existingUser->username,
                        'plain_password' => $password,
                        'phone_number' => $payment->phone_number,
                        'credentials_expires_at' => now()->addHours(24),
                    ]);

                    // Create new radius session record
                    RadiusSession::create([
                        'hotspot_user_id' => $existingUser->id,
                        'payment_id'      => $payment->id,
                        'package_id'      => $package->id,
                        'username'        => $existingUser->username,
                        'mac_address'     => $payment->mac_address,
                        'session_start'   => $periodStart,
                        'expected_end'    => $periodEnd,
                        'status'          => 'pending',
                    ]);

                    DB::commit();

                    $credentials = [
                        'hotspot_user_id' => $existingUser->id,
                        'username' => $existingUser->username,
                        'password' => $password,
                        'package_name' => $package->name,
                        'expires_at' => $periodEnd->toIso8601String(),
                        'reactivation' => true,
                    ];

                    // Cache credentials
                    Cache::put(
                        "tenant_{$this->tenantId}_payment_credentials_{$payment->id}",
                        $credentials,
                        now()->addSeconds(30)
                    );

                    // Dispatch SMS job (async)
                    SendCredentialsSMSJob::dispatch($existingUser->id, $this->tenantId)->onQueue('hotspot-sms');

                    // Broadcast reactivation event
                    broadcast(new HotspotUserCreated($existingUser, $payment, $credentials, $this->tenantId))->toOthers();

                    Log::info('Hotspot user reactivated successfully (async)', [
                        'user_id' => $existingUser->id,
                        'username' => $existingUser->username,
                        'payment_id' => $payment->id,
                        'new_expiry' => $periodEnd,
                        'job' => 'CreateHotspotUserJob',
                        'tenant_id' => $this->tenantId,
                        'reactivation' => true,
                    ]);

                    return; // Exit early - reactivation complete
                }

                // CREATE NEW USER: No existing user found - proceed with creation
                $hotspotUser = HotspotUser::create([
                    'username'                => $username,
                    'password'                => bcrypt($password),
                    'phone_number'            => $payment->phone_number,
                    'mac_address'             => $payment->mac_address,
                    'has_active_subscription' => true,
                    'package_name'            => $package->name,
                    'package_id'              => $package->id,
                    'subscription_starts_at'  => $periodStart,
                    'subscription_expires_at' => $periodEnd,
                    'data_limit'              => $package->data_limit_bytes ?? null,
                    'is_active'               => true,
                    'status'                  => 'active',
                ]);

                // Create RADIUS authentication entry
                DB::table('radcheck')->insert([
                    'username' => $username,
                    'attribute' => 'Cleartext-Password',
                    'op' => ':=',
                    'value' => $password,
                ]);

                // Session timeout = package duration in seconds
                $sessionTimeoutSecs = PackageExpiryHelper::durationInDays($package) * 86400;

                // Create RADIUS reply attributes
                $radiusAttributes = [
                    [
                        'username'  => $username,
                        'attribute' => 'Session-Timeout',
                        'op'        => ':=',
                        'value'     => (string) $sessionTimeoutSecs,
                    ],
                ];

                // Enforce bandwidth via Mikrotik-Rate-Limit — no local fallback cap on hotspot user profile
                $rateLimit = null;
                if ($package->download_speed && $package->upload_speed) {
                    $rateLimit = \App\Services\MikroTik\BandwidthHelper::formatMikrotikRateLimit(
                        (string) $package->download_speed,
                        (string) $package->upload_speed
                    );
                }
                if ($rateLimit) {
                    $radiusAttributes[] = [
                        'username'  => $username,
                        'attribute' => 'Mikrotik-Rate-Limit',
                        'op'        => ':=',
                        'value'     => $rateLimit,
                    ];
                }

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
                    'payment_id'      => $payment->id,
                    'package_id'      => $package->id,
                    'username'        => $username,
                    'mac_address'     => $payment->mac_address,
                    'session_start'   => $periodStart,
                    'expected_end'    => $periodEnd,
                    'status'          => 'pending',
                ]);

                DB::commit();

                $credentials = [
                    'hotspot_user_id' => $hotspotUser->id,
                    'username' => $username,
                    'password' => $password,
                    'package_name' => $package->name,
                    'expires_at' => $hotspotUser->subscription_expires_at->toIso8601String(),
                ];

                // Cache credentials for 30 seconds max to prevent stale data (tenant-scoped key)
                Cache::put(
                    "tenant_{$this->tenantId}_payment_credentials_{$payment->id}",
                    $credentials,
                    now()->addSeconds(30)
                );

                // Dispatch SMS job (async)
                SendCredentialsSMSJob::dispatch($hotspotUser->id, $this->tenantId)->onQueue('hotspot-sms');

                // Broadcast event
                broadcast(new HotspotUserCreated($hotspotUser, $payment, $credentials, $this->tenantId))->toOthers();

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
                
                throw $e;
            }
        });
    }

    public function failed(?\Throwable $exception): void
    {
        Log::critical('CreateHotspotUserJob permanently failed', [
            'payment_id' => $this->paymentId,
            'package_id' => $this->packageId,
            'tenant_id' => $this->tenantId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
