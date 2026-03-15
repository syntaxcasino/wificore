<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PppoeMonthlyPaymentReportNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $reportMonth,
        protected array $summary,
        protected string $reportPath,
    ) {
        $this->onQueue('notifications');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('PPPoE Monthly Payment Report - ' . $this->reportMonth)
            ->greeting('Hello,')
            ->line('Your PPPoE monthly payment report has been generated.')
            ->line('Report Month: ' . $this->reportMonth)
            ->line('Total Payments: ' . ($this->summary['total_payments'] ?? 0))
            ->line('Total Amount: KES ' . number_format((float) ($this->summary['total_amount'] ?? 0), 2))
            ->line('Paid Accounts: ' . ($this->summary['paid_accounts'] ?? 0))
            ->line('Unpaid Accounts: ' . ($this->summary['unpaid_accounts'] ?? 0))
            ->line('Report File: ' . $this->reportPath);
    }
}
