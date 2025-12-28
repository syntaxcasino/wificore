<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class TenantEmailVerification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $tenantSlug;
    public string $tenantName;
    public string $username;
    public string $password;

    public function __construct(string $tenantSlug, string $tenantName, string $username, string $password)
    {
        $this->tenantSlug = $tenantSlug;
        $this->tenantName = $tenantName;
        $this->username = $username;
        $this->password = $password;
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $verificationUrl = $this->verificationUrl($notifiable);

        // Map data to match what the view expects (TenantRegistration structure)
        $registrationData = (object)[
            'tenant_name' => $this->tenantName,
            'tenant_slug' => $this->tenantSlug,
            'tenant_email' => $notifiable->email,
        ];

        return (new MailMessage)
            ->subject('Verify Your Email - WifiCore Registration')
            ->view('emails.tenant-verification', [
                'registration' => $registrationData,
                'verificationUrl' => $verificationUrl,
            ]);
    }

    protected function verificationUrl($notifiable): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );
    }
}
