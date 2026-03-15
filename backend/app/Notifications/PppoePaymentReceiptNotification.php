<?php

namespace App\Notifications;

use App\Models\PppoePayment;
use App\Models\PppoeUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PppoePaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected PppoeUser $pppoeUser,
        protected PppoePayment $payment,
        protected string $receiptNumber,
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
        $paymentDate = ($this->payment->payment_date ?? now())->format('F j, Y g:i A');
        $periodEnd = $this->payment->period_end?->format('F j, Y') ?? 'N/A';

        return (new MailMessage)
            ->subject("Payment Receipt #{$this->receiptNumber}")
            ->greeting('Hello ' . $this->pppoeUser->getBillingName() . ',')
            ->line('We have received your PPPoE payment successfully.')
            ->line('Receipt Number: ' . $this->receiptNumber)
            ->line('Account Number: ' . $this->pppoeUser->account_number)
            ->line('Username: ' . $this->pppoeUser->username)
            ->line('Package: ' . $packageName)
            ->line('Amount Paid: KES ' . number_format((float) $this->payment->amount, 2))
            ->line('Payment Method: ' . ucfirst((string) $this->payment->payment_method))
            ->line('Transaction ID: ' . ($this->payment->transaction_id ?: 'N/A'))
            ->line('Payment Date: ' . $paymentDate)
            ->line('Service Valid Until: ' . $periodEnd)
            ->line('Your PPPoE service is ready for use.');
    }
}
