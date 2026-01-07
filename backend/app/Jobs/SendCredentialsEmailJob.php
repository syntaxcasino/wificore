<?php

namespace App\Jobs;

use App\Models\TenantRegistration;
use App\Events\TenantCredentialsSent;
use App\Events\TenantRegistrationCompleted;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendCredentialsEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public $registrationId;
    public $tries = 3;
    public $timeout = 120;

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
        
        try {
            $loginUrl = url('/login');
            
            Mail::send('emails.tenant-credentials', [
                'registration' => $registration,
                'tenant' => $registration->tenant,
                'username' => $registration->generated_username,
                'password' => $registration->generated_password,
                'loginUrl' => $loginUrl,
            ], function ($message) use ($registration) {
                $message->to($registration->tenant_email)
                    ->subject('Your WifiCore Account Credentials');
            });

            $registration->update([
                'credentials_sent' => true,
                'credentials_sent_at' => now(),
                'status' => 'completed',
                'generated_password' => null, // Clear password after sending
            ]);

            Log::info('Credentials email sent', [
                'registration_id' => $registration->id,
                'tenant_id' => $registration->tenant_id,
                'username' => $registration->generated_username
            ]);

            event(new TenantCredentialsSent($registration));
            event(new TenantRegistrationCompleted($registration));

        } catch (\Exception $e) {
            Log::error('Failed to send credentials email', [
                'registration_id' => $registration->id,
                'error' => $e->getMessage()
            ]);

            $registration->update([
                'status' => 'failed',
                'error_message' => 'Failed to send credentials email: ' . $e->getMessage()
            ]);

            throw $e;
        }
    }
}
