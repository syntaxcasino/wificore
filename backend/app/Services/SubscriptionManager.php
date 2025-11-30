<?php

namespace App\Services;

use App\Models\UserSubscription;
use App\Models\Package;
use App\Jobs\DisconnectUserJob;
use App\Jobs\ReconnectUserJob;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SubscriptionManager extends TenantAwareService
{
    protected RADIUSServiceController $radiusController;

    public function __construct(RADIUSServiceController $radiusController)
    {
        $this->radiusController = $radiusController;
    }

    /**
     * Handle expired subscription
     * 
     * @param UserSubscription $subscription
     * @return void
     */
    public function handleExpiredSubscription(UserSubscription $subscription): void
    {
        try {
            // Check if grace period is configured
            if ($subscription->grace_period_days > 0) {
                $this->startGracePeriod($subscription);
            } else {
                $this->disconnectUser($subscription, 'Subscription expired');
            }
            
            Log::info("Expired subscription handled", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'has_grace_period' => $subscription->grace_period_days > 0,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to handle expired subscription", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Start grace period for subscription
     * 
     * @param UserSubscription $subscription
     * @return void
     */
    public function startGracePeriod(UserSubscription $subscription): void
    {
        try {
            $subscription->startGracePeriod();
            
            // TODO: Send grace period notification
            
            Log::info("Grace period started", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'grace_period_days' => $subscription->grace_period_days,
                'grace_period_ends_at' => $subscription->grace_period_ends_at,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to start grace period", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Disconnect user
     * 
     * @param UserSubscription $subscription
     * @param string $reason
     * @return void
     */
    public function disconnectUser(UserSubscription $subscription, string $reason): void
    {
        try {
            // Queue the disconnection job
            dispatch(new DisconnectUserJob($subscription, $reason))
                ->onQueue('service-control');
            
            // Update subscription status
            $subscription->markAsDisconnected($reason);
            
            Log::info("User disconnection queued", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'reason' => $reason,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to disconnect user", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reconnect user after payment
     * 
     * @param UserSubscription $subscription
     * @return void
     */
    public function reconnectUser(UserSubscription $subscription): void
    {
        try {
            // Queue the reconnection job
            dispatch(new ReconnectUserJob($subscription))
                ->onQueue('service-control');
            
            // Update subscription status
            $subscription->reconnect();
            
            Log::info("User reconnection queued", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to reconnect user", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if subscription needs renewal
     * 
     * @param UserSubscription $subscription
     * @return bool
     */
    public function needsRenewal(UserSubscription $subscription): bool
    {
        if (!$subscription->next_payment_date) {
            return false;
        }
        
        return $subscription->next_payment_date <= now();
    }

    /**
     * Calculate next payment date
     * 
     * @param UserSubscription $subscription
     * @return Carbon
     */
    public function calculateNextPaymentDate(UserSubscription $subscription): Carbon
    {
        $package = $subscription->package;
        
        if (!$package) {
            return now()->addMonth();
        }
        
        // Parse duration from package
        // Format examples: "1 hour", "12 hours", "1 day", "30 days"
        $duration = $package->duration;
        
        if (str_contains($duration, 'hour')) {
            $hours = (int) filter_var($duration, FILTER_SANITIZE_NUMBER_INT);
            return now()->addHours($hours);
        } elseif (str_contains($duration, 'day')) {
            $days = (int) filter_var($duration, FILTER_SANITIZE_NUMBER_INT);
            return now()->addDays($days);
        } elseif (str_contains($duration, 'week')) {
            $weeks = (int) filter_var($duration, FILTER_SANITIZE_NUMBER_INT);
            return now()->addWeeks($weeks);
        } elseif (str_contains($duration, 'month')) {
            $months = (int) filter_var($duration, FILTER_SANITIZE_NUMBER_INT);
            return now()->addMonths($months);
        }
        
        // Default to 30 days
        return now()->addDays(30);
    }

    /**
     * Process payment and update subscription
     * 
     * @param UserSubscription $subscription
     * @return void
     */
    public function processPayment(UserSubscription $subscription): void
    {
        try {
            // Calculate next payment date
            $nextPaymentDate = $this->calculateNextPaymentDate($subscription);
            
            // Update subscription
            $subscription->update([
                'next_payment_date' => $nextPaymentDate,
                'reminder_count' => 0,
                'last_reminder_sent_at' => null,
            ]);
            
            // If user was disconnected, reconnect them
            if ($subscription->isDisconnected()) {
                $this->reconnectUser($subscription);
            }
            
            Log::info("Payment processed for subscription", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'next_payment_date' => $nextPaymentDate,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to process payment", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get subscriptions needing payment reminders
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getSubscriptionsNeedingReminders()
    {
        return UserSubscription::needingReminders()
            ->with(['user', 'package'])
            ->get()
            ->filter(function($subscription) {
                return $subscription->needsPaymentReminder();
            });
    }

    /**
     * Get expired subscriptions
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getExpiredSubscriptions()
    {
        return UserSubscription::where('status', 'active')
            ->where('end_time', '<=', now())
            ->with(['user', 'package'])
            ->get();
    }

    /**
     * Get subscriptions with expired grace period
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getGracePeriodExpired()
    {
        return UserSubscription::where('status', 'grace_period')
            ->where('grace_period_ends_at', '<=', now())
            ->with(['user', 'package'])
            ->get();
    }

    /**
     * Extend subscription
     * 
     * @param UserSubscription $subscription
     * @param int $days
     * @return void
     */
    public function extendSubscription(UserSubscription $subscription, int $days): void
    {
        try {
            $subscription->update([
                'end_time' => $subscription->end_time->addDays($days),
                'next_payment_date' => $subscription->next_payment_date 
                    ? $subscription->next_payment_date->addDays($days) 
                    : null,
            ]);
            
            Log::info("Subscription extended", [
                'subscription_id' => $subscription->id,
                'user_id' => $subscription->user_id,
                'extended_days' => $days,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to extend subscription", [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
