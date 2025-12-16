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
    public $timeout = 120;

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
        try {
            $verificationUrl = url("/api/register/verify/{$this->registration->token}");
            
            Mail::send('emails.tenant-verification', [
                'registration' => $this->registration,
                'verificationUrl' => $verificationUrl,
            ], function ($message) {
                $message->to($this->registration->tenant_email)
                    ->subject('Verify Your WifiCore Account');
            });

            $this->registration->update([
                'status' => 'email_sent'
            ]);

            Log::info('Verification email sent', [
                'registration_id' => $this->registration->id,
                'email' => $this->registration->tenant_email
            ]);

            event(new TenantRegistrationStarted($this->registration));

        } catch (\Exception $e) {
            Log::error('Failed to send verification email', [
                'registration_id' => $this->registration->id,
                'error' => $e->getMessage()
            ]);

            $this->registration->update([
                'status' => 'failed',
                'error_message' => 'Failed to send verification email: ' . $e->getMessage()
            ]);

            throw $e;
        }
    }
}
