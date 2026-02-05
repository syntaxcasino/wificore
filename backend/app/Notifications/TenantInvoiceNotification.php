<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class TenantInvoiceNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Tenant $tenant;
    protected array $invoice;
    protected string $invoiceNumber;

    /**
     * Create a new notification instance.
     */
    public function __construct(Tenant $tenant, array $invoice, string $invoiceNumber)
    {
        $this->tenant = $tenant;
        $this->invoice = $invoice;
        $this->invoiceNumber = $invoiceNumber;
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
        $paybill = config('saas.default_paybill', 'N/A');
        $accountNumber = $this->tenant->slug;
        $totalAmount = number_format($this->invoice['total'], 2);
        $dueDate = Carbon::parse($this->invoice['due_date'] ?? now()->addDays(7))->format('F j, Y');
        $periodStart = $this->invoice['period_start'] ?? now()->startOfMonth()->format('F j, Y');
        $periodEnd = $this->invoice['period_end'] ?? now()->endOfMonth()->format('F j, Y');

        $message = (new MailMessage)
            ->subject("📄 Invoice #{$this->invoiceNumber} - {$this->tenant->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Please find below your subscription invoice for **{$this->tenant->name}**.")
            ->line('---')
            ->line("**Invoice Number:** {$this->invoiceNumber}")
            ->line("**Billing Period:** {$periodStart} - {$periodEnd}")
            ->line("**Due Date:** {$dueDate}")
            ->line('---')
            ->line('**Invoice Details:**');

        if (isset($this->invoice['breakdown'])) {
            $breakdown = $this->invoice['breakdown'];
            $usage = $this->invoice['usage'] ?? [];
            $pppoeCount = $usage['pppoe_users'] ?? 0;
            $routerCount = $usage['routers'] ?? 0;
            
            $message->line("| Item | Amount |")
                ->line("|------|--------|")
                ->line("| Base Plan | KES " . number_format($breakdown['base_cost'] ?? 0, 2) . " |")
                ->line("| PPPoE Users ({$pppoeCount}) | KES " . number_format($breakdown['pppoe_cost'] ?? 0, 2) . " |")
                ->line("| Hotspot Revenue Share | KES " . number_format($breakdown['hotspot_cost'] ?? 0, 2) . " |")
                ->line("| Routers ({$routerCount}) | KES " . number_format($breakdown['router_cost'] ?? 0, 2) . " |");
        }

        $message->line('---')
            ->line("**Total Amount Due: KES {$totalAmount}**")
            ->line('---')
            ->line('**Payment Instructions:**')
            ->line("1. Go to M-Pesa → Lipa na M-Pesa → Paybill")
            ->line("2. Business Number: **{$paybill}**")
            ->line("3. Account Number: **{$accountNumber}**")
            ->line("4. Amount: **KES {$totalAmount}**")
            ->action('Pay Invoice', url('/tenant/billing/invoice/' . $this->invoiceNumber))
            ->line('Thank you for your business!');

        return $message;
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'invoice',
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'invoice_number' => $this->invoiceNumber,
            'amount' => $this->invoice['total'],
            'currency' => 'KES',
            'due_date' => $this->invoice['due_date'] ?? now()->addDays(7)->toIso8601String(),
            'period_start' => $this->invoice['period_start'],
            'period_end' => $this->invoice['period_end'],
            'message' => "Invoice #{$this->invoiceNumber} for KES " . number_format($this->invoice['total'], 2) . " is ready.",
            'action_url' => '/tenant/billing/invoice/' . $this->invoiceNumber,
            'action_text' => 'View Invoice',
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
