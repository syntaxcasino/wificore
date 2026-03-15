<?php

namespace App\Notifications;

use App\Models\PppoeUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PppoeInvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PppoeUser $pppoeUser,
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
            ->subject('PPPoE Service Invoice')
            ->greeting('Hello ' . $this->pppoeUser->getBillingName() . ',')
            ->line('Please find your upcoming PPPoE service invoice details below.')
            ->line('Account Number: ' . $this->pppoeUser->account_number)
            ->line('Username: ' . $this->pppoeUser->username)
            ->line('Package: ' . $packageName)
            ->line('Amount Due: KES ' . $amountDue)
            ->line('Due Date: ' . $dueDate)
            ->line('Paybill Number: ' . ($this->paymentInstructions['paybill_number'] ?? 'N/A'))
            ->line('Payment Reference: ' . ($this->paymentInstructions['account_number'] ?? $this->pppoeUser->account_number))
            ->line('Make payment before the due date to keep your service active.');
    }
}
