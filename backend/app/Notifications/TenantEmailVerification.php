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

        return (new MailMessage)
            ->subject('Verify Your Email - WifiCore Registration')
            ->greeting('Welcome to WifiCore!')
            ->line("Thank you for registering **{$this->tenantName}** with WifiCore.")
            ->line('Please verify your email address by clicking the button below:')
            ->action('Verify Email Address', $verificationUrl)
            ->line('This verification link will expire in 60 minutes.')
            ->line('')
            ->line('**Important:** After verifying your email, your login credentials will be sent to you in a separate email.')
            ->line('If you did not create an account, no further action is required.')
            ->salutation('Best regards, The WifiCore Team');
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
