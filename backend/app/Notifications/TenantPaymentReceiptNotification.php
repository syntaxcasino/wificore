<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class TenantPaymentReceiptNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Tenant $tenant;
    protected array $payment;
    protected string $receiptNumber;

    /**
     * Create a new notification instance.
     */
    public function __construct(Tenant $tenant, array $payment, string $receiptNumber)
    {
        $this->tenant = $tenant;
        $this->payment = $payment;
        $this->receiptNumber = $receiptNumber;
        $this->onQueue(config('saas.notifications.queue', 'notifications'));
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $amount = number_format($this->payment['amount'], 2);
        $paidAt = Carbon::parse($this->payment['paid_at'] ?? now())->format('F j, Y \a\t g:i A');
        $transactionId = $this->payment['transaction_id'] ?? 'N/A';
        $paymentMethod = ucfirst($this->payment['payment_method'] ?? 'M-Pesa');
        $newExpiryDate = isset($this->payment['new_expiry_date']) 
            ? Carbon::parse($this->payment['new_expiry_date'])->format('F j, Y') 
            : 'N/A';

        return (new MailMessage)
            ->subject("✅ Payment Received - Receipt #{$this->receiptNumber}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Thank you! We have received your payment for **{$this->tenant->name}**.")
            ->line('---')
            ->line("**Receipt Number:** {$this->receiptNumber}")
            ->line("**Amount Paid:** KES {$amount}")
            ->line("**Payment Method:** {$paymentMethod}")
            ->line("**Transaction ID:** {$transactionId}")
            ->line("**Date & Time:** {$paidAt}")
            ->line('---')
            ->line('**Subscription Status:**')
            ->line("Your subscription has been extended. New expiry date: **{$newExpiryDate}**")
            ->line('---')
            ->line('All your services are now active:')
            ->line('✅ PPPoE services restored')
            ->line('✅ Hotspot services restored')
            ->line('✅ Full dashboard access')
            ->action('View Receipt', url('/tenant/billing/receipt/' . $this->receiptNumber))
            ->line('Thank you for your continued business!')
            ->salutation('WifiCore SaaS Team');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'payment_receipt',
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'receipt_number' => $this->receiptNumber,
            'amount' => $this->payment['amount'],
            'currency' => 'KES',
            'transaction_id' => $this->payment['transaction_id'] ?? null,
            'payment_method' => $this->payment['payment_method'] ?? 'mpesa',
            'paid_at' => $this->payment['paid_at'] ?? now()->toIso8601String(),
            'new_expiry_date' => $this->payment['new_expiry_date'] ?? null,
            'message' => "Payment of KES " . number_format($this->payment['amount'], 2) . " received. Receipt #{$this->receiptNumber}",
            'action_url' => '/tenant/billing/receipt/' . $this->receiptNumber,
            'action_text' => 'View Receipt',
            'priority' => 'normal',
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
