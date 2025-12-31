<?php

namespace App\Jobs;

use App\Models\PaymentReminder;
use App\Models\Tenant;
use App\Services\SubscriptionManager;
use App\Notifications\PaymentDueReminderNotification;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPaymentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct($tenantId = null)
    {
        $this->setTenantContext($tenantId);
        $this->onQueue('notifications'); // Medium priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        // If no tenant ID is set, this is the main scheduler job.
        // We need to dispatch a job for each active tenant.
        if (!$this->tenantId) {
            $tenants = Tenant::where('is_active', true)->get();
            
            foreach ($tenants as $tenant) {
                self::dispatch($tenant->id);
            }
            
            Log::info("Dispatched payment reminders jobs for " . $tenants->count() . " tenants");
            return;
        }

        $this->executeInTenantContext(function() use ($subscriptionManager) {
            Log::info('SendPaymentRemindersJob: Starting', ['tenant_id' => $this->tenantId]);

            try {
                // Get subscriptions needing reminders (UserSubscription is in tenant schema)
                $subscriptions = $subscriptionManager->getSubscriptionsNeedingReminders();

                Log::info('SendPaymentRemindersJob: Found subscriptions needing reminders', [
                    'tenant_id' => $this->tenantId,
                    'count' => $subscriptions->count(),
                ]);

                foreach ($subscriptions as $subscription) {
                    try {
                        $daysUntilDue = $subscription->getDaysUntilPaymentDue();

                        if ($daysUntilDue === null) {
                            continue;
                        }

                        // Determine reminder type
                        $reminderType = $this->getReminderType($daysUntilDue);

                        // Send reminder
                        $this->sendReminder($subscription, $reminderType, $daysUntilDue);

                        // Record reminder sent
                        $subscription->recordReminderSent();

                        Log::info('SendPaymentRemindersJob: Reminder sent', [
                            'subscription_id' => $subscription->id,
                            'user_id' => $subscription->user_id,
                            'days_until_due' => $daysUntilDue,
                            'reminder_type' => $reminderType,
                        ]);

                    } catch (\Exception $e) {
                        Log::error('SendPaymentRemindersJob: Failed to send reminder', [
                            'subscription_id' => $subscription->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                Log::info('SendPaymentRemindersJob: Completed', [
                    'tenant_id' => $this->tenantId,
                    'reminders_sent' => $subscriptions->count(),
                ]);

            } catch (\Exception $e) {
                Log::error('SendPaymentRemindersJob: Job failed', [
                    'tenant_id' => $this->tenantId,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }
        });
    }

    /**
     * Get reminder type based on days until due
     */
    protected function getReminderType(int $daysUntilDue): string
    {
        return match(true) {
            $daysUntilDue >= 7 => PaymentReminder::TYPE_DUE_SOON,
            $daysUntilDue >= 3 => PaymentReminder::TYPE_DUE_SOON,
            $daysUntilDue >= 1 => PaymentReminder::TYPE_FINAL_WARNING,
            $daysUntilDue === 0 => PaymentReminder::TYPE_OVERDUE,
            default => PaymentReminder::TYPE_DUE_SOON,
        };
    }

    /**
     * Send reminder to user
     */
    protected function sendReminder($subscription, string $reminderType, int $daysUntilDue): void
    {
        $user = $subscription->user;

        // Send notification (will handle email, WhatsApp, and database)
        $user->notify(new PaymentDueReminderNotification($subscription, $daysUntilDue));

        // Create reminder record for tracking
        PaymentReminder::create([
            'user_id' => $user->id,
            'subscription_id' => $subscription->id,
            'reminder_type' => $reminderType,
            'days_before_due' => $daysUntilDue,
            'sent_at' => now(),
            'channel' => PaymentReminder::CHANNEL_EMAIL, // Primary channel
            'status' => PaymentReminder::STATUS_SENT,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('SendPaymentRemindersJob: Job failed permanently', [
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
