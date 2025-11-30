<?php

namespace App\Notifications;

use App\Models\UserSubscription;
use App\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;

class PaymentDueReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected UserSubscription $subscription;
    protected int $daysUntilDue;

    public function __construct(UserSubscription $subscription, int $daysUntilDue)
    {
        $this->subscription = $subscription;
        $this->daysUntilDue = $daysUntilDue;
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        $channels = ['database']; // Always store in database

        // Add email if user has email
        if ($notifiable->email) {
            $channels[] = 'mail';
        }

        // Add SMS/WhatsApp if user has phone
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
        $dueDate = $this->subscription->next_payment_date->format('F j, Y');

        return (new MailMessage)
            ->subject('Payment Reminder - ' . $this->daysUntilDue . ' Days Until Due')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('This is a friendly reminder that your subscription payment is due soon.')
            ->line('**Package:** ' . $package->name)
            ->line('**Amount:** KES ' . number_format($package->price, 2))
            ->line('**Due Date:** ' . $dueDate)
            ->line('**Days Until Due:** ' . $this->daysUntilDue . ' days')
            ->action('Make Payment', url('/payments'))
            ->line('Please make your payment before the due date to avoid service interruption.')
            ->line('Thank you for using our service!');
    }

    /**
     * Get the WhatsApp representation of the notification.
     */
    public function toWhatsApp($notifiable): array
    {
        try {
            $package = $this->subscription->package;
            $dueDate = $this->subscription->next_payment_date->format('F j, Y');
            
            $message = "🔔 *Payment Reminder*\n\n";
            $message .= "Hello {$notifiable->name},\n\n";
            $message .= "Your subscription payment is due in *{$this->daysUntilDue} days*.\n\n";
            $message .= "📦 *Package:* {$package->name}\n";
            $message .= "💰 *Amount:* KES " . number_format($package->price, 2) . "\n";
            $message .= "📅 *Due Date:* {$dueDate}\n\n";
            $message .= "Please make your payment to avoid service interruption.\n\n";
            $message .= "Thank you! 🙏";

            $whatsappService = app(WhatsAppService::class);
            $result = $whatsappService->sendMessage($notifiable->phone_number, $message);

            Log::info('WhatsApp payment reminder sent', [
                'user_id' => $notifiable->id,
                'subscription_id' => $this->subscription->id,
                'result' => $result,
            ]);

            return $result;
        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp payment reminder', [
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
            'type' => 'payment_due_reminder',
            'subscription_id' => $this->subscription->id,
            'package_name' => $this->subscription->package->name,
            'amount' => $this->subscription->package->price,
            'due_date' => $this->subscription->next_payment_date,
            'days_until_due' => $this->daysUntilDue,
            'message' => "Your payment of KES {$this->subscription->package->price} is due in {$this->daysUntilDue} days.",
        ];
    }
}
