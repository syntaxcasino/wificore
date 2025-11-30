<?php

namespace App\Notifications;

use App\Models\UserSubscription;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class ServiceDisconnectedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected UserSubscription $subscription;
    protected string $reason;

    public function __construct(UserSubscription $subscription, string $reason)
    {
        $this->subscription = $subscription;
        $this->reason = $reason;
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
        return (new MailMessage)
            ->subject('Service Disconnected')
            ->greeting('Hello ' . $notifiable->name)
            ->line('Your internet service has been disconnected.')
            ->line('**Reason:** ' . $this->reason)
            ->line('**Disconnected At:** ' . $this->subscription->disconnected_at->format('F j, Y g:i A'))
            ->action('Make Payment to Reconnect', url('/payments'))
            ->line('Please make a payment to restore your service.')
            ->line('If you have any questions, please contact our support team.');
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable): array
    {
        try {
            $message = "⚠️ *Service Disconnected*\n\n";
            $message .= "Hello {$notifiable->name},\n\n";
            $message .= "Your internet service has been disconnected.\n\n";
            $message .= "📋 *Reason:* {$this->reason}\n";
            $message .= "⏰ *Disconnected At:* " . $this->subscription->disconnected_at->format('F j, Y g:i A') . "\n\n";
            $message .= "💳 Please make a payment to restore your service.\n\n";
            $message .= "Need help? Contact our support team.";

            $whatsappService = app(WhatsAppService::class);
            $result = $whatsappService->sendMessage($notifiable->phone_number, $message);

            Log::info('WhatsApp disconnection notice sent', [
                'user_id' => $notifiable->id,
                'subscription_id' => $this->subscription->id,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp disconnection notice', [
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
            'type' => 'service_disconnected',
            'subscription_id' => $this->subscription->id,
            'reason' => $this->reason,
            'disconnected_at' => $this->subscription->disconnected_at,
            'message' => "Your service has been disconnected: {$this->reason}",
        ];
    }
}
