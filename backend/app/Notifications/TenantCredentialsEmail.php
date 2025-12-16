<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TenantCredentialsEmail extends Notification implements ShouldQueue
{
    use Queueable;

    public string $tenantName;
    public string $tenantSlug;
    public string $username;
    public string $password;
    public string $subdomain;

    public function __construct(
        string $tenantName,
        string $tenantSlug,
        string $username,
        string $password,
        string $subdomain
    ) {
        $this->tenantName = $tenantName;
        $this->tenantSlug = $tenantSlug;
        $this->username = $username;
        $this->password = $password;
        $this->subdomain = $subdomain;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $loginUrl = "https://{$this->subdomain}.wificore.traidsolutions.com/login";

        return (new MailMessage)
            ->subject('Your WifiCore Account Credentials')
            ->greeting("Welcome to WifiCore, {$this->tenantName}!")
            ->line('Your account has been successfully created and verified.')
            ->line('Here are your login credentials:')
            ->line("**Username:** {$this->username}")
            ->line("**Password:** {$this->password}")
            ->line("**Login URL:** {$loginUrl}")
            ->line('')
            ->line('**Important Security Notes:**')
            ->line('• Please change your password after your first login')
            ->line('• Keep your credentials secure and do not share them')
            ->line('• Your subdomain is: ' . $this->subdomain . '.wificore.traidsolutions.com')
            ->line('')
            ->action('Login to Your Dashboard', $loginUrl)
            ->line('If you have any questions, please contact our support team.')
            ->salutation('Best regards, The WifiCore Team');
    }
}
