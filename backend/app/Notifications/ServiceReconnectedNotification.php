<?php

namespace App\Notifications;

use App\Models\UserSubscription;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ServiceReconnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected UserSubscription $subscription;

    public function __construct(UserSubscription $subscription)
    {
        $this->subscription = $subscription;
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
        $nextDue = $this->subscription->next_payment_date 
            ? $this->subscription->next_payment_date->format('F j, Y')
            : 'N/A';

        return (new MailMessage)
            ->subject('Service Reconnected - Welcome Back!')
            ->greeting('Welcome back, ' . $notifiable->name . '! 🎉')
            ->line('Your internet service has been successfully reconnected.')
            ->line('Thank you for your payment!')
            ->line('**Package:** ' . $this->subscription->package->name)
            ->line('**Next Payment Due:** ' . $nextDue)
            ->action('View Dashboard', url('/dashboard'))
            ->line('Enjoy your internet service!')
            ->line('Thank you for choosing us!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable): array
    {
        try {
            $nextDue = $this->subscription->next_payment_date 
                ? $this->subscription->next_payment_date->format('F j, Y')
                : 'N/A';

            $message = "✅ *Service Reconnected*\n\n";
            $message .= "Welcome back, {$notifiable->name}! 🎉\n\n";
            $message .= "Your internet service has been successfully reconnected.\n\n";
            $message .= "📦 *Package:* {$this->subscription->package->name}\n";
            $message .= "📅 *Next Payment Due:* {$nextDue}\n\n";
            $message .= "Thank you for your payment! Enjoy your internet service! 🌐\n\n";
            $message .= "Thank you for choosing us! 🙏";

            $whatsappService = app(WhatsAppService::class);
            $result = $whatsappService->sendMessage($notifiable->phone_number, $message);

            Log::info('WhatsApp reconnection notice sent', [
                'user_id' => $notifiable->id,
                'subscription_id' => $this->subscription->id,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp reconnection notice', [
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
            'type' => 'service_reconnected',
            'subscription_id' => $this->subscription->id,
            'package_name' => $this->subscription->package->name,
            'next_payment_date' => $this->subscription->next_payment_date,
            'message' => 'Your service has been reconnected. Welcome back!',
        ];
    }
}
