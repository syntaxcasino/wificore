<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantSubscriptionExpiryWarning;
use App\Services\SaasBillingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Carbon\Carbon;

class SendTenantExpiryWarningJob implements ShouldQueue
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
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(SaasBillingService $billingService): void
    {
        if (!config('saas.notifications.expiry_warning_enabled', true)) {
            Log::info('Tenant expiry warning notifications are disabled');
            return;
        }

        Log::info('Starting tenant subscription expiry warning check');

        $warningDays = config('saas.enforcement.warning_days', 5);
        $warningThreshold = now()->addDays($warningDays);

        $sentCount = 0;
        $skippedCount = 0;
        $errors = [];

        // Get tenants expiring within the warning period who haven't been warned yet
        // Exclude landlord and default tenants (exempt from subscription payment)
        $expiringTenants = Tenant::where('is_landlord', false)
            ->where(function ($q) {
                $q->where('is_default', false)->orWhereNull('is_default');
            })
            ->where('is_active', true)
            ->whereNull('suspended_at')
            ->whereNotNull('subscription_ends_at')
            ->where('subscription_ends_at', '>', now())
            ->where('subscription_ends_at', '<=', $warningThreshold)
            ->where(function ($query) {
                // Either never warned, or warned more than 24 hours ago (allow daily reminders)
                $query->whereNull('subscription_warning_sent_at')
                      ->orWhere('subscription_warning_sent_at', '<', now()->subHours(24));
            })
            ->get();

        foreach ($expiringTenants as $tenant) {
            try {
                $daysUntilExpiry = $billingService->getDaysUntilExpiry($tenant);

                // Skip if already expired (shouldn't happen with query, but safety check)
                if ($daysUntilExpiry === null || $daysUntilExpiry <= 0) {
                    $skippedCount++;
                    continue;
                }

                // Calculate subscription cost for renewal
                $subscriptionCost = $billingService->calculateSubscriptionCost($tenant);

                // Send warning notification
                $this->sendExpiryWarning($tenant, $daysUntilExpiry, $subscriptionCost);

                // Mark warning as sent (idempotency)
                $tenant->subscription_warning_sent_at = now();
                $tenant->save();

                $sentCount++;

                Log::info('Sent subscription expiry warning to tenant', [
                    'tenant_id' => $tenant->id,
                    'tenant_name' => $tenant->name,
                    'days_until_expiry' => $daysUntilExpiry,
                    'subscription_ends_at' => $tenant->subscription_ends_at,
                ]);

            } catch (\Exception $e) {
                $errors[] = [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ];
                Log::error('Failed to send tenant expiry warning', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        Log::info('Tenant subscription expiry warning check completed', [
            'tenants_checked' => $expiringTenants->count(),
            'warnings_sent' => $sentCount,
            'skipped' => $skippedCount,
            'errors' => count($errors),
        ]);
    }

    /**
     * Send expiry warning notification to tenant admins
     */
    private function sendExpiryWarning(Tenant $tenant, int $daysUntilExpiry, array $subscriptionCost): void
    {
        // Get tenant admin users
        $admins = User::where('tenant_id', $tenant->id)
            ->where('role', User::ROLE_ADMIN)
            ->get();

        if ($admins->isEmpty()) {
            Log::warning('No admin users found for tenant expiry warning', [
                'tenant_id' => $tenant->id,
            ]);
            return;
        }

        Notification::send($admins, new TenantSubscriptionExpiryWarning(
            $tenant,
            $daysUntilExpiry,
            $subscriptionCost
        ));

        Log::info('Sent expiry warning notifications to tenant admins', [
            'tenant_id' => $tenant->id,
            'admin_count' => $admins->count(),
            'days_until_expiry' => $daysUntilExpiry,
        ]);
    }

    /**
     * Get tags for queue monitoring
     */
    public function tags(): array
    {
        return ['saas', 'expiry-warning', 'notifications'];
    }

    /**
     * Handle job failure
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendTenantExpiryWarningJob failed permanently', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
