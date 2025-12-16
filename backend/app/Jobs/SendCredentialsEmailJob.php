<?php

namespace App\Jobs;

use App\Models\TenantRegistration;
use App\Events\TenantCredentialsSent;
use App\Events\TenantRegistrationCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendCredentialsEmailJob implements ShouldQueue
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
            $loginUrl = url('/login');
            
            Mail::send('emails.tenant-credentials', [
                'registration' => $this->registration,
                'tenant' => $this->registration->tenant,
                'username' => $this->registration->generated_username,
                'password' => $this->registration->generated_password,
                'loginUrl' => $loginUrl,
            ], function ($message) {
                $message->to($this->registration->tenant_email)
                    ->subject('Your WifiCore Account Credentials');
            });

            $this->registration->update([
                'credentials_sent' => true,
                'credentials_sent_at' => now(),
                'status' => 'completed',
                'generated_password' => null, // Clear password after sending
            ]);

            Log::info('Credentials email sent', [
                'registration_id' => $this->registration->id,
                'tenant_id' => $this->registration->tenant_id,
                'username' => $this->registration->generated_username
            ]);

            event(new TenantCredentialsSent($this->registration));
            event(new TenantRegistrationCompleted($this->registration));

        } catch (\Exception $e) {
            Log::error('Failed to send credentials email', [
                'registration_id' => $this->registration->id,
                'error' => $e->getMessage()
            ]);

            $this->registration->update([
                'status' => 'failed',
                'error_message' => 'Failed to send credentials email: ' . $e->getMessage()
            ]);

            throw $e;
        }
    }
}
