<?php

namespace App\Jobs;

use App\Models\TenantRegistration;
use App\Events\TenantRegistrationStarted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendVerificationEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $registration;
    public $tries = 3;
    public $timeout = 60;
    public $backoff = [5, 15, 30];

    /**
     * Create a new job instance.
     */
    public function __construct(TenantRegistration $registration)
    {
        $this->registration = $registration;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $startTime = microtime(true);
        
        try {
            $verificationUrl = url("/register/verify/{$this->registration->token}");
            
            // Use Mail facade with explicit mailer for better control
            Mail::mailer(config('mail.default'))->send(
                'emails.tenant-verification',
                [
                    'registration' => $this->registration,
                    'verificationUrl' => $verificationUrl,
                ],
                function ($message) {
                    $message->to($this->registration->tenant_email)
                        ->subject('Verify Your Email - WifiCore Registration')
                        ->priority(1); // High priority
                }
            );

            $duration = round((microtime(true) - $startTime) * 1000, 2);

            $this->registration->update([
                'status' => 'email_sent'
            ]);

            Log::info('Verification email sent successfully', [
                'registration_id' => $this->registration->id,
                'email' => $this->registration->tenant_email,
                'duration_ms' => $duration,
                'attempt' => $this->attempts()
            ]);

            event(new TenantRegistrationStarted($this->registration));

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            Log::error('Failed to send verification email', [
                'registration_id' => $this->registration->id,
                'email' => $this->registration->tenant_email,
                'error' => $e->getMessage(),
                'duration_ms' => $duration,
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries
            ]);

            // Only update to failed if all retries exhausted
            if ($this->attempts() >= $this->tries) {
                $this->registration->update([
                    'status' => 'failed',
                    'error_message' => 'Failed to send verification email after ' . $this->tries . ' attempts: ' . $e->getMessage()
                ]);
            }

            throw $e;
        }
    }
}
