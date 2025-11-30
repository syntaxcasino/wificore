<?php

namespace App\Notifications;

use App\Models\UserSubscription;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class GracePeriodWarningNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected UserSubscription $subscription;
    protected int $daysRemaining;

    public function __construct(UserSubscription $subscription, int $daysRemaining)
    {
        $this->subscription = $subscription;
        $this->daysRemaining = $daysRemaining;
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        if ($notifiable->phone_number) {
            $channels[] = 'whatsapp';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        $package = $this->subscription->package;
        $expiresAt = $this->subscription->grace_period_ends_at->format('F j, Y g:i A');

        return (new MailMessage)
            ->subject('⚠️ Final Warning - Grace Period Ending Soon')
            ->greeting('URGENT: ' . $notifiable->name)
            ->line('Your subscription is in the grace period and will be disconnected soon!')
            ->line('**Days Remaining:** ' . $this->daysRemaining . ' day(s)')
            ->line('**Grace Period Ends:** ' . $expiresAt)
            ->line('**Package:** ' . $package->name)
            ->line('**Amount Due:** KES ' . number_format($package->price, 2))
            ->action('Make Payment Now', url('/payments'))
            ->line('⚠️ **IMPORTANT:** Your service will be automatically disconnected if payment is not received before the grace period ends.')
            ->line('Please make your payment immediately to avoid service interruption.');
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable): array
    {
        try {
            $package = $this->subscription->package;
            $expiresAt = $this->subscription->grace_period_ends_at->format('F j, Y g:i A');

            $message = "⚠️ *URGENT: Final Warning*\n\n";
            $message .= "Hello {$notifiable->name},\n\n";
            $message .= "Your subscription is in the grace period and will be disconnected soon!\n\n";
            $message .= "⏰ *Days Remaining:* {$this->daysRemaining} day(s)\n";
            $message .= "📅 *Grace Period Ends:* {$expiresAt}\n";
            $message .= "📦 *Package:* {$package->name}\n";
            $message .= "💰 *Amount Due:* KES " . number_format($package->price, 2) . "\n\n";
            $message .= "🚨 *Your service will be automatically disconnected if payment is not received!*\n\n";
            $message .= "Please make your payment immediately to avoid service interruption.\n\n";
            $message .= "Thank you!";

            $whatsappService = app(WhatsAppService::class);
            $result = $whatsappService->sendMessage($notifiable->phone_number, $message);

            Log::info('WhatsApp grace period warning sent', [
                'user_id' => $notifiable->id,
                'subscription_id' => $this->subscription->id,
                'days_remaining' => $this->daysRemaining,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp grace period warning', [
                'user_id' => $notifiable->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        return [
            'type' => 'grace_period_warning',
            'subscription_id' => $this->subscription->id,
            'package_name' => $this->subscription->package->name,
            'amount_due' => $this->subscription->package->price,
            'days_remaining' => $this->daysRemaining,
            'grace_period_ends_at' => $this->subscription->grace_period_ends_at,
            'message' => "URGENT: Your grace period ends in {$this->daysRemaining} day(s). Service will be disconnected if payment is not received!",
        ];
    }
}
