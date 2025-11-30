<?php

namespace App\Jobs;

use App\Models\HotspotUser;
use App\Models\HotspotCredential;
use App\Events\CredentialsSent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendCredentialsSMSJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $hotspotUserId;
    public $tries = 3;
    public $timeout = 30;

    /**
     * Create a new job instance.
     */
    public function __construct($hotspotUserId)
    {
        $this->hotspotUserId = $hotspotUserId;
        $this->onQueue('hotspot-sms');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $hotspotUser = HotspotUser::find($this->hotspotUserId);
        
        if (!$hotspotUser) {
            Log::warning('Hotspot user not found for SMS', ['user_id' => $this->hotspotUserId]);
            return;
        }

        $credential = HotspotCredential::where('hotspot_user_id', $this->hotspotUserId)
                                      ->where('sms_sent', false)
                                      ->first();
        
        if (!$credential) {
            Log::warning('No unsent credentials found', ['user_id' => $this->hotspotUserId]);
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
            broadcast(new CredentialsSent($credential))->toOthers();
            
            Log::info('Credentials SMS sent successfully', [
                'user_id' => $this->hotspotUserId,
                'phone' => $credential->phone_number,
                'message_id' => $messageId,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Failed to send credentials SMS', [
                'error' => $e->getMessage(),
                'user_id' => $this->hotspotUserId,
                'phone' => $credential->phone_number ?? 'unknown',
            ]);
            
            // Update status as failed
            if ($credential) {
                $credential->update([
                    'sms_status' => 'failed',
                ]);
            }
            
            throw $e;
        }
    }
    
    /**
     * Send SMS via gateway
     */
    private function sendSMS(string $phoneNumber, string $message): string
    {
        // TODO: Implement actual SMS gateway integration
        // Options: Africa's Talking, Twilio, etc.
        
        // For now, log the message
        Log::info('SMS to be sent', [
            'phone' => $phoneNumber,
            'message' => $message,
        ]);
        
        // Simulate SMS sending
        // In production, replace with actual SMS gateway call:
        /*
        $africastalking = new \AfricasTalking\SDK\AfricasTalking(
            config('services.africastalking.username'),
            config('services.africastalking.api_key')
        );
        
        $sms = $africastalking->sms();
        $result = $sms->send([
            'to' => $phoneNumber,
            'message' => $message,
            'from' => config('services.africastalking.sender_id')
        ]);
        
        return $result['SMSMessageData']['Recipients'][0]['messageId'];
        */
        
        return 'mock_message_id_' . time();
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendCredentialsSMSJob failed permanently', [
            'user_id' => $this->hotspotUserId,
            'error' => $exception->getMessage(),
        ]);
    }
}
