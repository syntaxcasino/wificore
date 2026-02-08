<?php

namespace App\Services;

use App\Models\CommunicationChannel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MessagingService
{
    /**
     * Send a test message through the given channel.
     */
    public function sendTestMessage(CommunicationChannel $channel, string $recipient): array
    {
        $testBody = "This is a test message from WifiCore. Channel: {$channel->name} ({$channel->type}/{$channel->provider}). Time: " . now()->toDateTimeString();

        return $this->send($channel, $recipient, $testBody);
    }

    /**
     * Send a message through the given channel.
     */
    public function send(CommunicationChannel $channel, string $recipient, string $body): array
    {
        return match ($channel->type) {
            'sms' => $this->sendSms($channel, $recipient, $body),
            'whatsapp' => $this->sendWhatsApp($channel, $recipient, $body),
            'email' => $this->sendEmail($channel, $recipient, $body),
            default => ['success' => false, 'message' => "Unsupported channel type: {$channel->type}"],
        };
    }

    /**
     * Send a message using the default channel for a given type.
     * Falls back gracefully if no channel is configured.
     */
    public function sendViaDefaultChannel(string $type, string $recipient, string $body): array
    {
        $channel = CommunicationChannel::getDefaultForType($type);

        if (!$channel) {
            Log::warning("No active {$type} channel configured for tenant");
            return ['success' => false, 'message' => "No active {$type} channel configured"];
        }

        return $this->send($channel, $recipient, $body);
    }

    /**
     * Send SMS via configured provider.
     */
    protected function sendSms(CommunicationChannel $channel, string $recipient, string $body): array
    {
        $creds = $channel->getDecryptedCredentials();

        return match ($channel->provider) {
            'africastalking' => $this->sendViaAfricasTalking($creds, $channel->sender_id, $recipient, $body),
            'twilio' => $this->sendViaTwilioSms($creds, $channel->phone_number ?? $channel->sender_id, $recipient, $body),
            'custom' => $this->sendViaCustomApi($creds, $channel->settings ?? [], $recipient, $body),
            default => ['success' => false, 'message' => "Unsupported SMS provider: {$channel->provider}"],
        };
    }

    /**
     * Send WhatsApp message via configured provider.
     */
    protected function sendWhatsApp(CommunicationChannel $channel, string $recipient, string $body): array
    {
        $creds = $channel->getDecryptedCredentials();

        return match ($channel->provider) {
            'twilio' => $this->sendViaTwilioWhatsApp($creds, $channel->phone_number, $recipient, $body),
            'whatsapp_business' => $this->sendViaWhatsAppBusiness($creds, $channel->phone_number, $recipient, $body),
            'custom' => $this->sendViaCustomApi($creds, $channel->settings ?? [], $recipient, $body),
            default => ['success' => false, 'message' => "Unsupported WhatsApp provider: {$channel->provider}"],
        };
    }

    /**
     * Send email via configured provider.
     */
    protected function sendEmail(CommunicationChannel $channel, string $recipient, string $body): array
    {
        // Email sending is handled by Laravel's built-in mail system
        // This is a placeholder for custom email providers
        return ['success' => false, 'message' => 'Email sending via custom provider not yet implemented. Use Laravel Mail configuration.'];
    }

    /**
     * Africa's Talking SMS API.
     */
    protected function sendViaAfricasTalking(array $creds, ?string $senderId, string $recipient, string $body): array
    {
        try {
            $apiKey = $creds['api_key'] ?? null;
            $username = $creds['username'] ?? null;

            if (!$apiKey || !$username) {
                return ['success' => false, 'message' => 'Missing Africa\'s Talking credentials (api_key, username)'];
            }

            $url = ($creds['environment'] ?? 'production') === 'sandbox'
                ? 'https://api.sandbox.africastalking.com/version1/messaging'
                : 'https://api.africastalking.com/version1/messaging';

            $payload = [
                'username' => $username,
                'to' => $recipient,
                'message' => $body,
            ];

            if ($senderId) {
                $payload['from'] = $senderId;
            }

            $response = Http::withHeaders([
                'apiKey' => $apiKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ])->asForm()->post($url, $payload);

            if ($response->successful()) {
                $data = $response->json();
                $recipients = $data['SMSMessageData']['Recipients'] ?? [];
                $firstRecipient = $recipients[0] ?? null;
                $status = $firstRecipient['status'] ?? 'Unknown';

                if (in_array($status, ['Success', 'Sent'])) {
                    return ['success' => true, 'message' => "SMS sent successfully. Status: {$status}"];
                }

                return ['success' => false, 'message' => "SMS delivery status: {$status}. " . ($firstRecipient['statusCode'] ?? '')];
            }

            return ['success' => false, 'message' => 'Africa\'s Talking API error: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('AfricasTalking SMS error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to send SMS: ' . $e->getMessage()];
        }
    }

    /**
     * Twilio SMS API.
     */
    protected function sendViaTwilioSms(array $creds, ?string $from, string $to, string $body): array
    {
        try {
            $accountSid = $creds['account_sid'] ?? null;
            $authToken = $creds['auth_token'] ?? null;

            if (!$accountSid || !$authToken || !$from) {
                return ['success' => false, 'message' => 'Missing Twilio credentials (account_sid, auth_token, from number)'];
            }

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post($url, [
                    'From' => $from,
                    'To' => $to,
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return ['success' => true, 'message' => "SMS sent. SID: " . ($data['sid'] ?? 'N/A')];
            }

            $error = $response->json();
            return ['success' => false, 'message' => 'Twilio error: ' . ($error['message'] ?? $response->body())];
        } catch (\Exception $e) {
            Log::error('Twilio SMS error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to send SMS: ' . $e->getMessage()];
        }
    }

    /**
     * Twilio WhatsApp API.
     */
    protected function sendViaTwilioWhatsApp(array $creds, ?string $from, string $to, string $body): array
    {
        try {
            $accountSid = $creds['account_sid'] ?? null;
            $authToken = $creds['auth_token'] ?? null;

            if (!$accountSid || !$authToken || !$from) {
                return ['success' => false, 'message' => 'Missing Twilio WhatsApp credentials'];
            }

            $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

            $response = Http::withBasicAuth($accountSid, $authToken)
                ->asForm()
                ->post($url, [
                    'From' => "whatsapp:{$from}",
                    'To' => "whatsapp:{$to}",
                    'Body' => $body,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                return ['success' => true, 'message' => "WhatsApp message sent. SID: " . ($data['sid'] ?? 'N/A')];
            }

            $error = $response->json();
            return ['success' => false, 'message' => 'Twilio WhatsApp error: ' . ($error['message'] ?? $response->body())];
        } catch (\Exception $e) {
            Log::error('Twilio WhatsApp error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to send WhatsApp message: ' . $e->getMessage()];
        }
    }

    /**
     * WhatsApp Business API (Cloud API).
     */
    protected function sendViaWhatsAppBusiness(array $creds, ?string $from, string $to, string $body): array
    {
        try {
            $accessToken = $creds['access_token'] ?? null;
            $phoneNumberId = $creds['phone_number_id'] ?? null;

            if (!$accessToken || !$phoneNumberId) {
                return ['success' => false, 'message' => 'Missing WhatsApp Business credentials (access_token, phone_number_id)'];
            }

            $url = "https://graph.facebook.com/v18.0/{$phoneNumberId}/messages";

            $response = Http::withToken($accessToken)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'text',
                    'text' => ['body' => $body],
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'WhatsApp Business message sent successfully'];
            }

            $error = $response->json();
            return ['success' => false, 'message' => 'WhatsApp Business error: ' . json_encode($error['error'] ?? $response->body())];
        } catch (\Exception $e) {
            Log::error('WhatsApp Business API error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to send WhatsApp message: ' . $e->getMessage()];
        }
    }

    /**
     * Custom API endpoint.
     */
    protected function sendViaCustomApi(array $creds, array $settings, string $recipient, string $body): array
    {
        try {
            $endpoint = $settings['api_endpoint'] ?? $creds['api_endpoint'] ?? null;
            $method = strtoupper($settings['method'] ?? 'POST');
            $apiKey = $creds['api_key'] ?? null;

            if (!$endpoint) {
                return ['success' => false, 'message' => 'Missing custom API endpoint'];
            }

            $headers = ['Accept' => 'application/json'];
            if ($apiKey) {
                $headerName = $settings['api_key_header'] ?? 'Authorization';
                $headerPrefix = $settings['api_key_prefix'] ?? 'Bearer';
                $headers[$headerName] = "{$headerPrefix} {$apiKey}";
            }

            $payload = [
                'to' => $recipient,
                'message' => $body,
            ];

            // Merge any custom payload fields
            if (!empty($settings['extra_payload'])) {
                $payload = array_merge($payload, $settings['extra_payload']);
            }

            $response = Http::withHeaders($headers)->{strtolower($method)}($endpoint, $payload);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Message sent via custom API'];
            }

            return ['success' => false, 'message' => 'Custom API error: ' . $response->body()];
        } catch (\Exception $e) {
            Log::error('Custom API messaging error', ['error' => $e->getMessage()]);
            return ['success' => false, 'message' => 'Failed to send message: ' . $e->getMessage()];
        }
    }
}
