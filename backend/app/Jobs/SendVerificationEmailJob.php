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
        
        try {
            $verificationUrl = url("/register/verify/{$registration->token}");
            
            // Use Mail facade with explicit mailer for better control
            Mail::mailer(config('mail.default'))->send(
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

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $registration->update([
                'status' => 'email_sent'
            ]);

            Log::info('Verification email sent successfully', [
                'registration_id' => $registration->id,
                'email' => $registration->tenant_email,
                'duration_ms' => $duration,
                'attempt' => $this->attempts()
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
                'max_tries' => $this->tries
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
