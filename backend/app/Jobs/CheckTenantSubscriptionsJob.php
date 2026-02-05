<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantDisconnectionNotification;
use App\Services\SaasBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

class CheckTenantSubscriptionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;
    public $tries = 3;
    public $maxExceptions = 2;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public $backoff = [60, 120, 300];

    public function __construct()
    {
        $this->onQueue('saas-enforcement');
    }

    /**
     * Execute the job.
     */
    public function handle(SaasBillingService $billingService): void
    {
        Log::info('Starting tenant subscription enforcement check');

        $processedCount = 0;
        $suspendedCount = 0;
        $overriddenCount = 0;
        $errors = [];

        // Get all active, non-landlord tenants with expired subscriptions
        $expiredTenants = Tenant::where('is_landlord', false)
            ->where('is_active', true)
            ->whereNull('suspended_at')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '<', now())
            ->get();

        foreach ($expiredTenants as $tenant) {
            try {
                $processedCount++;

                // Check for active landlord override
                if ($billingService->hasActiveOverride($tenant)) {
                    Log::info('Tenant has active landlord override, skipping suspension', [
                        'tenant_id' => $tenant->id,
                        'tenant_name' => $tenant->name,
                        'override_reason' => $tenant->landlord_override_reason,
                        'override_until' => $tenant->landlord_override_until,
                    ]);
                    $overriddenCount++;
                    continue;
                }

                // Suspend the tenant - no grace period for SaaS
                $this->suspendTenant($tenant);
                $suspendedCount++;

                // Send disconnection notification to tenant admins
                $this->notifyTenantAdmins($tenant);

                Log::warning('Tenant suspended for expired subscription', [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'subscription_ended_at' => $tenant->subscription_ends_at,
                ]);

            } catch (\Exception $e) {
                $errors[] = [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to process tenant subscription enforcement', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        // Also check for expired overrides and clear them
        $this->clearExpiredOverrides();

        Log::info('Tenant subscription enforcement check completed', [
            'total_expired' => $expiredTenants->count(),
            'processed' => $processedCount,
            'suspended' => $suspendedCount,
            'overridden' => $overriddenCount,
            'errors' => count($errors),
        ]);
    }

    /**
     * Suspend a tenant's services
     */
    private function suspendTenant(Tenant $tenant): void
    {
        $tenant->is_active = false;
        $tenant->suspended_at = now();
        $tenant->suspension_reason = 'Subscription expired';
        $tenant->subscription_status = 'expired';
        $tenant->save();

        Log::info('Tenant services suspended', [
            'tenant_id' => $tenant->id,
            'tenant_name' => $tenant->name,
        ]);
    }

    /**
     * Send disconnection notification to tenant admin users
     */
    private function notifyTenantAdmins(Tenant $tenant): void
    {
        if (!config('saas.notifications.disconnection_enabled', true)) {
            return;
        }

        try {
            // Get tenant admin users
            $admins = User::where('tenant_id', $tenant->id)
                ->where('role', User::ROLE_ADMIN)
                ->get();

            if ($admins->isEmpty()) {
                Log::warning('No admin users found for tenant disconnection notification', [
                    'tenant_id' => $tenant->id,
                ]);
                return;
            }

            Notification::send($admins, new TenantDisconnectionNotification($tenant));

            Log::info('Sent disconnection notifications to tenant admins', [
                'tenant_id' => $tenant->id,
                'admin_count' => $admins->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send disconnection notification', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear expired landlord overrides
     */
    private function clearExpiredOverrides(): void
    {
        $expiredOverrides = Tenant::where('landlord_override', true)
            ->whereNotNull('landlord_override_until')
            ->where('landlord_override_until', '<', now())
            ->get();

        foreach ($expiredOverrides as $tenant) {
            $tenant->landlord_override = false;
            $tenant->landlord_override_reason = null;
            $tenant->landlord_override_until = null;
            $tenant->save();

            Log::info('Cleared expired landlord override', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
            ]);
        }

        if ($expiredOverrides->count() > 0) {
            Log::info('Cleared expired landlord overrides', [
                'count' => $expiredOverrides->count(),
            ]);
        }
    }

    /**
     * Get tags for queue monitoring
     */
    public function tags(): array
    {
        return ['saas', 'subscription-enforcement'];
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckTenantSubscriptionsJob failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
