<?php

namespace App\Notifications;

use App\Models\PppoeUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PppoePaymentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PppoeUser $pppoeUser,
        protected int $daysUntilDue,
        protected array $paymentInstructions,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $packageName = $this->pppoeUser->package?->name ?? 'PPPoE Package';
        $dueDate = $this->pppoeUser->next_payment_due?->format('F j, Y') ?? 'N/A';
        $amountDue = number_format((float) ($this->pppoeUser->amount_due ?? $this->pppoeUser->package?->price ?? 0), 2);

        return (new MailMessage)
            ->subject('PPPoE Payment Reminder')
            ->greeting('Hello ' . $this->pppoeUser->getBillingName() . ',')
            ->line('This is a reminder that your PPPoE service payment is coming due.')
            ->line('Account Number: ' . $this->pppoeUser->account_number)
            ->line('Username: ' . $this->pppoeUser->username)
            ->line('Package: ' . $packageName)
            ->line('Amount Due: KES ' . $amountDue)
            ->line('Due Date: ' . $dueDate)
            ->line('Days Until Due: ' . max($this->daysUntilDue, 0))
            ->line('Paybill Number: ' . ($this->paymentInstructions['paybill_number'] ?? 'N/A'))
            ->line('Payment Account Reference: ' . ($this->paymentInstructions['account_number'] ?? $this->pppoeUser->account_number))
            ->line('Please make payment before the due date to avoid service interruption.');
    }
}
