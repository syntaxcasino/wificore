<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Service
 * 
 * Sends WhatsApp messages using WhatsApp Business API or third-party providers
 * Supports: Twilio, Africa's Talking, or direct WhatsApp Business API
 */
class WhatsAppService extends TenantAwareService
{
    protected string $provider;
    protected string $apiKey;
    protected string $apiUrl;
    protected string $fromNumber;

    public function __construct()
    {
        $this->provider = config('services.whatsapp.provider', 'twilio');
        $this->apiKey = config('services.whatsapp.api_key');
        $this->apiUrl = config('services.whatsapp.api_url');
        $this->fromNumber = config('services.whatsapp.from_number');
    }

    /**
     * Send WhatsApp message
     * 
     * @param string $to Phone number in international format (e.g., +254700000000)
     * @param string $message Message content
     * @return array Response with status and message_id
     */
    public function sendMessage(string $to, string $message): array
    {
        try {
            // Ensure phone number is in correct format
            $to = $this->formatPhoneNumber($to);

            return match($this->provider) {
                'twilio' => $this->sendViaTwilio($to, $message),
                'africas_talking' => $this->sendViaAfricasTalking($to, $message),
                'whatsapp_business' => $this->sendViaWhatsAppBusiness($to, $message),
                default => throw new \Exception("Unsupported WhatsApp provider: {$this->provider}"),
            };
        } catch (\Exception $e) {
            Log::error('WhatsApp send failed', [
                'to' => $to,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send via Twilio WhatsApp API
     */
    protected function sendViaTwilio(string $to, string $message): array
    {
        $accountSid = config('services.twilio.account_sid');
        $authToken = config('services.twilio.auth_token');

        $response = Http::withBasicAuth($accountSid, $authToken)
            ->asForm()
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json", [
                'From' => "whatsapp:{$this->fromNumber}",
                'To' => "whatsapp:{$to}",
                'Body' => $message,
            ]);

        if ($response->successful()) {
            $data = $response->json();
            
            Log::info('WhatsApp sent via Twilio', [
                'to' => $to,
                'message_sid' => $data['sid'] ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $data['sid'] ?? null,
                'provider' => 'twilio',
            ];
        }

        throw new \Exception('Twilio API error: ' . $response->body());
    }

    /**
     * Send via Africa's Talking WhatsApp API
     */
    protected function sendViaAfricasTalking(string $to, string $message): array
    {
        $username = config('services.africas_talking.username');
        $apiKey = config('services.africas_talking.api_key');

        $response = Http::withHeaders([
            'apiKey' => $apiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post('https://whatsapp.africastalking.com/v1/messaging', [
            'username' => $username,
            'to' => [$to],
            'message' => $message,
        ]);

        if ($response->successful()) {
            $data = $response->json();
            
            Log::info('WhatsApp sent via Africa\'s Talking', [
                'to' => $to,
                'response' => $data,
            ]);

            return [
                'success' => true,
                'message_id' => $data['messageId'] ?? null,
                'provider' => 'africas_talking',
            ];
        }

        throw new \Exception('Africa\'s Talking API error: ' . $response->body());
    }

    /**
     * Send via WhatsApp Business API (direct)
     */
    protected function sendViaWhatsAppBusiness(string $to, string $message): array
    {
        $response = Http::withToken($this->apiKey)
            ->post($this->apiUrl . '/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => [
                    'body' => $message,
                ],
            ]);

        if ($response->successful()) {
            $data = $response->json();
            
            Log::info('WhatsApp sent via Business API', [
                'to' => $to,
                'message_id' => $data['messages'][0]['id'] ?? null,
            ]);

            return [
                'success' => true,
                'message_id' => $data['messages'][0]['id'] ?? null,
                'provider' => 'whatsapp_business',
            ];
        }

        throw new \Exception('WhatsApp Business API error: ' . $response->body());
    }

    /**
     * Format phone number to international format
     */
    protected function formatPhoneNumber(string $phone): string
    {
        // Remove any spaces, dashes, or parentheses
        $phone = preg_replace('/[\s\-\(\)]/', '', $phone);

        // If doesn't start with +, add country code
        if (!str_starts_with($phone, '+')) {
            // Default to Kenya (+254) if no country code
            $phone = '+254' . ltrim($phone, '0');
        }

        return $phone;
    }

    /**
     * Send template message (for WhatsApp Business API)
     */
    public function sendTemplate(string $to, string $templateName, array $parameters = []): array
    {
        try {
            $to = $this->formatPhoneNumber($to);

            $response = Http::withToken($this->apiKey)
                ->post($this->apiUrl . '/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $templateName,
                        'language' => [
                            'code' => 'en',
                        ],
                        'components' => [
                            [
                                'type' => 'body',
                                'parameters' => $parameters,
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                
                return [
                    'success' => true,
                    'message_id' => $data['messages'][0]['id'] ?? null,
                ];
            }

            throw new \Exception('Template send failed: ' . $response->body());
        } catch (\Exception $e) {
            Log::error('WhatsApp template send failed', [
                'to' => $to,
                'template' => $templateName,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
