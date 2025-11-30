<?php

namespace App\Jobs;

use App\Models\PaymentReminder;
use App\Services\SubscriptionManager;
use App\Notifications\PaymentDueReminderNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPaymentRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('notifications'); // Medium priority queue
    }

    /**
     * Execute the job.
     */
    public function handle(SubscriptionManager $subscriptionManager): void
    {
        Log::info('SendPaymentRemindersJob: Starting');

        try {
            // Get subscriptions needing reminders
            $subscriptions = $subscriptionManager->getSubscriptionsNeedingReminders();

            Log::info('SendPaymentRemindersJob: Found subscriptions needing reminders', [
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
                'reminders_sent' => $subscriptions->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('SendPaymentRemindersJob: Job failed', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
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
            'error' => $exception->getMessage(),
        ]);
    }
}
