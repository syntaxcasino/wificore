<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class TenantSubscriptionExpiryWarning extends Notification implements ShouldQueue
{
    use Queueable;

    protected Tenant $tenant;
    protected int $daysUntilExpiry;
    protected array $subscriptionCost;

    /**
     * Create a new notification instance.
     */
    public function __construct(Tenant $tenant, int $daysUntilExpiry, array $subscriptionCost)
    {
        $this->tenant = $tenant;
        $this->daysUntilExpiry = $daysUntilExpiry;
        $this->subscriptionCost = $subscriptionCost;
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
        $expiryDate = Carbon::parse($this->tenant->subscription_ends_at)->format('F j, Y');
        $renewalAmount = number_format($this->subscriptionCost['total'], 2);
        $paybill = config('saas.default_paybill', 'N/A');
        $accountNumber = $this->tenant->slug;

        $message = (new MailMessage)
            ->subject("⚠️ Subscription Expiring in {$this->daysUntilExpiry} Days - {$this->tenant->name}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your subscription for **{$this->tenant->name}** will expire on **{$expiryDate}**.")
            ->line("You have **{$this->daysUntilExpiry} days** remaining to renew your subscription.")
            ->line('---')
            ->line("**Renewal Amount:** KES {$renewalAmount}");

        if ($this->subscriptionCost['breakdown']) {
            $breakdown = $this->subscriptionCost['breakdown'];
            $message->line('**Cost Breakdown:**')
                ->line("- Base Plan: KES " . number_format($breakdown['base_cost'], 2))
                ->line("- PPPoE Users ({$this->subscriptionCost['usage']['pppoe_users']}): KES " . number_format($breakdown['pppoe_cost'], 2))
                ->line("- Hotspot Revenue Share: KES " . number_format($breakdown['hotspot_cost'], 2))
                ->line("- Routers ({$this->subscriptionCost['usage']['routers']}): KES " . number_format($breakdown['router_cost'], 2));
        }

        $message->line('---')
            ->line('**Payment Instructions:**')
            ->line("1. Go to M-Pesa → Lipa na M-Pesa → Paybill")
            ->line("2. Business Number: **{$paybill}**")
            ->line("3. Account Number: **{$accountNumber}**")
            ->line("4. Amount: **KES {$renewalAmount}**")
            ->line('---')
            ->line('**⚠️ Important:** Your services will be automatically suspended if payment is not received before the expiry date. There is no grace period.')
            ->action('Pay Now', url('/tenant/billing'))
            ->line('Thank you for using our services!');

        return $message;
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'subscription_expiry_warning',
            'tenant_id' => $this->tenant->id,
            'tenant_name' => $this->tenant->name,
            'days_until_expiry' => $this->daysUntilExpiry,
            'expiry_date' => $this->tenant->subscription_ends_at,
            'renewal_amount' => $this->subscriptionCost['total'],
            'currency' => 'KES',
            'message' => "Your subscription expires in {$this->daysUntilExpiry} days. Please renew to avoid service interruption.",
            'action_url' => '/tenant/billing',
            'action_text' => 'Renew Subscription',
            'priority' => 'high',
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
