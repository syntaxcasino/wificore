<?php

namespace App\Jobs;

use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Events\CredentialsSent;
use App\Traits\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCredentialsSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    use TenantAwareJob;

    public $hotspotUserId;
    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($hotspotUserId, $tenantId)
    {
        $this->hotspotUserId = $hotspotUserId;
        $this->setTenantContext($tenantId);
        $this->onQueue('hotspot-sms');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $this->executeInTenantContext(function() {
            $hotspotUser = HotspotUser::find($this->hotspotUserId);
            
            if (!$hotspotUser) {
                Log::warning('Hotspot user not found for SMS', [
                    'user_id' => $this->hotspotUserId,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }

            $credential = HotspotCredential::where('hotspot_user_id', $this->hotspotUserId)
                                          ->where('sms_sent', false)
                                          ->first();
            
            if (!$credential) {
                Log::warning('No unsent credentials found', [
                    'user_id' => $this->hotspotUserId,
                    'tenant_id' => $this->tenantId
                ]);
                return;
            }
            
            try {
                // Format SMS message
                $message = sprintf(
                    "WiFi Credentials - Username: %s, Password: %s. Valid for: %s. You are already connected! Use these credentials if you disconnect or on other devices. - TraidNet",
                    $credential->username,
                    $credential->plain_password,
                    $hotspotUser->package_name
                );
                
                // Send SMS
                $messageId = $this->sendSMS($credential->phone_number, $message);
                
                // Mark as sent
                $credential->update([
                    'sms_sent' => true,
                    'sms_sent_at' => now(),
                    'sms_message_id' => $messageId,
                    'sms_status' => 'sent',
                ]);
                
                // Broadcast event
                broadcast(new CredentialsSent($credential, $this->tenantId))->toOthers();
                
                Log::info('Credentials SMS sent successfully', [
                    'user_id' => $this->hotspotUserId,
                    'phone' => $credential->phone_number,
                    'message_id' => $messageId,
                    'tenant_id' => $this->tenantId
                ]);
                
            } catch (\Exception $e) {
                Log::error('Failed to send credentials SMS', [
                    'error' => $e->getMessage(),
                    'user_id' => $this->hotspotUserId,
                    'phone' => $credential->phone_number ?? 'unknown',
                    'tenant_id' => $this->tenantId
                ]);
                
                // Update status as failed
                if ($credential) {
                    $credential->update([
                        'sms_status' => 'failed',
                    ]);
                }
                
                throw $e;
            }
        });
    }
    
    /**
     * Send SMS via tenant's configured communication channel.
     */
    private function sendSMS(string $phoneNumber, string $message): string
    {
        $service = new \App\Services\MessagingService();
        $result = $service->sendViaDefaultChannel('sms', $phoneNumber, $message);

        if ($result['success']) {
            Log::info('SMS sent via MessagingService', [
                'phone' => $phoneNumber,
                'tenant_id' => $this->tenantId,
                'result' => $result['message'],
            ]);
            return $result['message'];
        }

        // If no SMS channel configured, log and return gracefully
        Log::warning('SMS sending failed or no channel configured', [
            'phone' => $phoneNumber,
            'tenant_id' => $this->tenantId,
            'reason' => $result['message'],
        ]);

        return 'no_channel_' . time();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCredentialsSMSJob failed permanently', [
            'user_id' => $this->hotspotUserId,
            'tenant_id' => $this->tenantId,
            'error' => $exception->getMessage(),
        ]);
    }
}
