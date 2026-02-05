<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class TenantDisconnectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected Tenant $tenant;
    protected ?string $reason;

    /**
     * Create a new notification instance.
     */
    public function __construct(Tenant $tenant, ?string $reason = null)
    {
        $this->tenant = $tenant;
        $this->reason = $reason ?? 'Subscription expired';
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
        $suspendedAt = Carbon::parse($this->tenant->suspended_at ?? now())->format('F j, Y \a\t g:i A');

        return (new MailMessage)
            ->subject("🚫 Service Suspended - {$this->tenant->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("We regret to inform you that services for **{$this->tenant->name}** have been **suspended**.")
            ->line("**Reason:** {$this->reason}")
            ->line("**Suspended on:** {$suspendedAt}")
            ->line('---')
            ->line('**Impact:**')
            ->line('- All PPPoE and Hotspot services are offline')
            ->line('- Your customers cannot connect to the network')
            ->line('- Dashboard access is restricted')
            ->line('---')
            ->line('**To restore your services:**')
            ->line("1. Go to M-Pesa → Lipa na M-Pesa → Paybill")
            ->line("2. Business Number: **{$paybill}**")
            ->line("3. Account Number: **{$accountNumber}**")
            ->line('4. Pay your outstanding subscription amount')
            ->line('---')
            ->line('Your services will be automatically restored within minutes of payment confirmation.')
            ->action('Contact Support', url('/support'))
            ->line('If you believe this is an error, please contact support immediately.')
            ->salutation('WifiCore SaaS Team');
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'service_disconnection',
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'reason' => $this->reason,
            'suspended_at' => $this->tenant->suspended_at ?? now()->toIso8601String(),
            'message' => "Services for {$this->tenant->name} have been suspended. Reason: {$this->reason}",
            'action_url' => '/tenant/billing',
            'action_text' => 'Pay Now',
            'priority' => 'critical',
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
