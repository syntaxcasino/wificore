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
            ->view('emails.tenant-credentials', [
                'registration' => $notifiable,
                'tenant' => (object)[
                    'name' => $this->tenantName, 
                    'slug' => $this->tenantSlug
                ],
                'username' => $this->username,
                'password' => $this->password,
                'loginUrl' => $loginUrl,
                'subdomain' => $this->subdomain
            ]);
    }
}
