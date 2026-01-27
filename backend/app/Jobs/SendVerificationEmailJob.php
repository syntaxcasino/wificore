<?php

namespace App\Jobs;

use App\Models\TenantRegistration;
use App\Events\TenantRegistrationStarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $registrationId;
    public $tries = 3;
    public $timeout = 60;
    public $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(int $registrationId)
    {
        $this->registrationId = $registrationId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $registration = TenantRegistration::find($this->registrationId);
        
        if (!$registration) {
            Log::error('Registration not found', ['registration_id' => $this->registrationId]);
            return;
        }
        
        $startTime = microtime(true);
        $mailer = (string) (config('mail.default') ?: 'smtp');
        $smtpHost = (string) config('mail.mailers.smtp.host');
        $smtpPort = (int) (config('mail.mailers.smtp.port') ?: 0);
        $smtpEncryption = (string) (config('mail.mailers.smtp.encryption') ?: '');
        $smtpUsername = (string) (config('mail.mailers.smtp.username') ?: '');
        $fromAddress = (string) (config('mail.from.address') ?: '');
        $fromName = (string) (config('mail.from.name') ?: '');
        
        try {
            $verificationUrl = url("/register/verify/{$registration->token}");
            
            // Use Mail facade with explicit mailer for better control
            $sent = Mail::mailer($mailer)->send(
                'emails.tenant-verification',
                [
                    'registration' => $registration,
                    'verificationUrl' => $verificationUrl,
                ],
                function ($message) use ($registration) {
                    $message->to($registration->tenant_email)
                        ->subject('Verify Your Email - WifiCore Registration')
                        ->priority(1); // High priority
                }
            );

            $messageId = null;
            if ($sent && method_exists($sent, 'getMessageId')) {
                $messageId = $sent->getMessageId();
            } elseif ($sent && method_exists($sent, 'getSymfonySentMessage')) {
                $symfonySent = $sent->getSymfonySentMessage();
                if ($symfonySent && method_exists($symfonySent, 'getMessageId')) {
                    $messageId = $symfonySent->getMessageId();
                }
            }

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $registration->update([
                'status' => 'email_sent'
            ]);

            Log::info('Verification email sent successfully', [
                'registration_id' => $registration->id,
                'email' => $registration->tenant_email,
                'duration_ms' => $duration,
                'attempt' => $this->attempts(),
                'mailer' => $mailer,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
                'smtp_username' => $smtpUsername !== '' ? (substr($smtpUsername, 0, 3) . '***') : '',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
                'message_id' => $messageId,
                'verification_url' => $verificationUrl,
            ]);

            event(new TenantRegistrationStarted($registration));

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('Failed to send verification email', [
                'registration_id' => $registration->id,
                'email' => $registration->tenant_email,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries,
                'mailer' => $mailer,
                'smtp_host' => $smtpHost,
                'smtp_port' => $smtpPort,
                'smtp_encryption' => $smtpEncryption,
                'smtp_username' => $smtpUsername !== '' ? (substr($smtpUsername, 0, 3) . '***') : '',
                'from_address' => $fromAddress,
                'from_name' => $fromName,
            ]);

            // Only update to failed if all retries exhausted
            if ($this->attempts() >= $this->tries) {
                $registration->update([
                    'status' => 'failed',
                    'error_message' => 'Failed to send verification email after ' . $this->tries . ' attempts: ' . $e->getMessage()
                ]);
            }

            throw $e;
        }
    }
}
