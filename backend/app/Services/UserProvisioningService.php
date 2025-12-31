<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Payment;
use App\Models\Package;
use App\Jobs\ProvisionUserInMikroTikJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserProvisioningService extends TenantAwareService
{
    /**
     * Process payment and provision user
     */
    public function processPayment(Payment $payment): array
    {
        try {
            DB::beginTransaction();

            // SECURITY: Validate payment belongs to correct tenant
            $tenantId = $payment->tenant_id;
            $this->validatePayment($payment, $tenantId);

            // Get package details and validate tenant ownership
            $package = Package::findOrFail($payment->package_id);
            $this->validatePackage($package, $tenantId);

            // Find or create user
            $user = $this->findOrCreateUser($payment);

            // Create subscription
            $subscription = $this->createSubscription($user, $package, $payment);

            // Generate MikroTik credentials
            $credentials = $this->generateMikroTikCredentials($user, $subscription);

            // Create RADIUS entry immediately
            $this->createRadiusEntry($credentials['username'], $credentials['password']);

            // Dispatch MikroTik provisioning to queue (if router is available)
            if ($payment->router_id) {
                ProvisionUserInMikroTikJob::dispatch($subscription->id, $payment->router_id, $tenantId)
                    ->onQueue('provisioning')
                    ->delay(now()->addSeconds(5)); // Delay to ensure RADIUS entry is committed
                
                \Log::info('MikroTik provisioning job dispatched', [
                    'subscription_id' => $subscription->id,
                    'router_id' => $payment->router_id,
                    'tenant_id' => $tenantId
                ]);
            }

            DB::commit();

            return [
                'success' => true,
                'user' => $user,
                'subscription' => $subscription,
                'credentials' => $credentials,
            ];

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('User provisioning failed', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Find or create hotspot user
     */
    protected function findOrCreateUser(Payment $payment): User
    {
        // Try to find user by phone number
        $user = User::where('phone_number', $payment->phone_number)->first();

        if ($user) {
            \Log::info('Returning user found', [
                'user_id' => $user->id,
                'phone' => $payment->phone_number
            ]);
            return $user;
        }

        // Create new hotspot user
        $username = $this->generateUsername($payment->phone_number);
        $password = Str::random(12);

        $user = User::create([
            'name' => 'Hotspot User',
            'username' => $username,
            'email' => $username . '@hotspot.local',
            'password' => Hash::make($password),
            'role' => User::ROLE_HOTSPOT_USER,
            'phone_number' => $payment->phone_number,
            'account_balance' => 0.00,
            'is_active' => true,
        ]);

        \Log::info('New hotspot user created', [
            'user_id' => $user->id,
            'username' => $username,
            'phone' => $payment->phone_number
        ]);

        return $user;
    }

    /**
     * Create user subscription
     */
    protected function createSubscription(User $user, Package $package, Payment $payment): UserSubscription
    {
        // Parse duration
        $duration = $this->parseDuration($package->duration);
        $startTime = now();
        $endTime = $startTime->copy()->addMinutes($duration);

        $subscription = UserSubscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'payment_id' => $payment->id,
            'mac_address' => $payment->mac_address,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'status' => 'active',
            'data_used_mb' => 0,
            'time_used_minutes' => 0,
        ]);

        \Log::info('Subscription created', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'package' => $package->name,
            'duration' => $duration . ' minutes',
            'expires_at' => $endTime
        ]);

        return $subscription;
    }

    /**
     * Generate MikroTik credentials
     */
    protected function generateMikroTikCredentials(User $user, UserSubscription $subscription): array
    {
        // Generate unique username and password
        $username = 'user_' . $user->phone_number;
        $password = Str::random(8);

        // Update subscription with credentials
        $subscription->update([
            'mikrotik_username' => $username,
            'mikrotik_password' => $password,
        ]);

        return [
            'username' => $username,
            'password' => $password,
        ];
    }

    /**
     * Provision user in MikroTik router
     */
    protected function provisionInMikroTik(UserSubscription $subscription, array $credentials, string $routerId): void
    {
        try {
            $router = \App\Models\Router::findOrFail($routerId);
            $package = $subscription->package;

            // Connect to MikroTik
            $client = new \RouterOS\Client([
                'host' => $router->ip_address,
                'user' => $router->username,
                'pass' => $router->password,
                'port' => $router->port ?? 8728,
            ]);

            $query = new \RouterOS\Query('/ip/hotspot/user/add');
            $query->equal('name', $credentials['username']);
            $query->equal('password', $credentials['password']);
            $query->equal('limit-uptime', $this->formatDuration($package->duration));
            $query->equal('limit-bytes-total', $this->calculateDataLimit($package));
            $query->equal('profile', $this->getProfileName($package));

            $response = $client->query($query)->read();

            \Log::info('User provisioned in MikroTik', [
                'subscription_id' => $subscription->id,
                'router_id' => $routerId,
                'username' => $credentials['username'],
                'response' => $response
            ]);

        } catch (\Exception $e) {
            \Log::error('MikroTik provisioning failed', [
                'subscription_id' => $subscription->id,
                'router_id' => $routerId,
                'error' => $e->getMessage()
            ]);
            // Don't throw - allow subscription to be created even if MikroTik fails
        }
    }

    /**
     * Create RADIUS entry for authentication
     */
    protected function createRadiusEntry(string $username, string $password): void
    {
        try {
            DB::table('radcheck')->insert([
                'username' => $username,
                'attribute' => 'Cleartext-Password',
                'op' => ':=',
                'value' => $password,
            ]);

            \Log::info('RADIUS entry created', [
                'username' => $username
            ]);

        } catch (\Exception $e) {
            \Log::error('RADIUS entry creation failed', [
                'username' => $username,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Generate username from phone number
     */
    protected function generateUsername(string $phoneNumber): string
    {
        // Remove any non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phoneNumber);
        
        // Use last 9 digits if longer
        if (strlen($phone) > 9) {
            $phone = substr($phone, -9);
        }

        return 'hs_' . $phone;
    }

    /**
     * Parse duration string to minutes
     */
    protected function parseDuration(string $duration): int
    {
        // Parse formats like "1 hour", "12 hours", "1 day", etc.
        preg_match('/(\d+)\s*(hour|hours|day|days|minute|minutes)/i', $duration, $matches);

        if (empty($matches)) {
            return 60; // Default 1 hour
        }

        $value = (int) $matches[1];
        $unit = strtolower($matches[2]);

        switch ($unit) {
            case 'minute':
            case 'minutes':
                return $value;
            case 'hour':
            case 'hours':
                return $value * 60;
            case 'day':
            case 'days':
                return $value * 24 * 60;
            default:
                return 60;
        }
    }

    /**
     * Format duration for MikroTik (HH:MM:SS)
     */
    protected function formatDuration(string $duration): string
    {
        $minutes = $this->parseDuration($duration);
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        return sprintf('%02d:%02d:00', $hours, $mins);
    }

    /**
     * Calculate data limit in bytes
     */
    protected function calculateDataLimit(Package $package): int
    {
        // Parse speed strings like "3 Mbps", "10 Mbps"
        preg_match('/(\d+)\s*Mbps/i', $package->download_speed, $matches);
        $speedMbps = isset($matches[1]) ? (int) $matches[1] : 3;

        // Calculate data limit based on duration and speed
        // For example: 1 hour at 3 Mbps = ~1.35 GB
        $duration = $this->parseDuration($package->duration);
        $dataLimitMB = ($speedMbps * $duration * 60) / 8; // Convert to MB

        return (int) ($dataLimitMB * 1024 * 1024); // Convert to bytes
    }

    /**
     * Get MikroTik profile name based on package type
     */
    protected function getProfileName(Package $package): string
    {
        return strtolower($package->type) . '-profile';
    }

    /**
     * Extend existing subscription
     */
    public function extendSubscription(User $user, Package $package, Payment $payment): UserSubscription
    {
        $activeSubscription = $user->activeSubscription;

        if ($activeSubscription) {
            // Extend existing subscription
            $duration = $this->parseDuration($package->duration);
            $activeSubscription->end_time = $activeSubscription->end_time->addMinutes($duration);
            $activeSubscription->save();

            \Log::info('Subscription extended', [
                'subscription_id' => $activeSubscription->id,
                'user_id' => $user->id,
                'new_expiry' => $activeSubscription->end_time
            ]);

            return $activeSubscription;
        }

        // No active subscription, create new one
        return $this->createSubscription($user, $package, $payment);
    }
}
