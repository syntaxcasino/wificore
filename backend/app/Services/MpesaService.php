<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MpesaService
{
    protected Client $client;
    protected array $config;
    protected string $cachePrefix = 'mpesa_token_';

    public function __construct()
    {
        $this->client = new Client([
            'timeout' => 30,
            'connect_timeout' => 10,
            'http_errors' => false,
        ]);

        $this->config = [
            'base_url' => config('mpesa.base_url', 'https://sandbox.safaricom.co.ke'),
            'consumer_key' => config('mpesa.consumer_key'),
            'consumer_secret' => config('mpesa.consumer_secret'),
            'business_shortcode' => config('mpesa.business_shortcode'),
            'passkey' => config('mpesa.passkey'),
            'callback_url' => config('mpesa.callback_url'),
            'initiator_name' => config('mpesa.initiator_name'),
            'initiator_password' => config('mpesa.initiator_password'),
            'account_reference' => config('mpesa.account_reference', 'COMPANY'),
            'transaction_desc' => config('mpesa.transaction_desc', 'Payment'),
        ];

        $this->validateConfig();
    }

    protected function validateConfig(): void
    {
        $required = [
            'consumer_key',
            'consumer_secret',
            'business_shortcode',
            'passkey',
            'callback_url'
        ];

        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new \RuntimeException("M-Pesa configuration missing: $key");
            }
        }
    }

    /**
     * Get cached access token or generate new one with retry logic
     */
    public function getAccessToken(): ?string
    {
        return Cache::remember($this->cachePrefix . 'access_token', 3500, function () {
            $attempts = 3;
            $retryDelay = 1000; // 1 second delay between retries

            for ($i = 0; $i < $attempts; $i++) {
                try {
                    $response = $this->client->get($this->config['base_url'] . '/oauth/v1/generate?grant_type=client_credentials', [
                        'auth' => [$this->config['consumer_key'], $this->config['consumer_secret']],
                        'headers' => ['Accept' => 'application/json'],
                    ]);

                    $statusCode = $response->getStatusCode();
                    $data = json_decode($response->getBody(), true);

                    if ($statusCode !== 200) {
                        Log::error('M-Pesa Token Request Failed', [
                            'attempt' => $i + 1,
                            'status' => $statusCode,
                            'response' => $data
                        ]);
                        sleep($retryDelay / 1000);
                        continue;
                    }

                    if (!isset($data['access_token'])) {
                        Log::error('M-Pesa Token Missing in Response', [
                            'attempt' => $i + 1,
                            'response' => $data
                        ]);
                        return null;
                    }

                    return $data['access_token'];
                } catch (RequestException $e) {
                    Log::error('M-Pesa Token Request Exception', [
                        'attempt' => $i + 1,
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                    ]);
                    sleep($retryDelay / 1000);
                }
            }

            Log::error('M-Pesa Token Request Failed After All Retries');
            return null;
        });
    }

    /**
     * Initiate STK Push payment request
     */
    public function initiateSTKPush(
        string $phone,
        float $amount,
        ?string $reference = null,
        ?string $description = null,
        ?string $callbackUrl = null
    ): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return $this->errorResponse('Failed to obtain access token', [], 401);
        }

        $phone = $this->formatPhoneNumber($phone);
        $timestamp = date('YmdHis');
        $password = base64_encode($this->config['business_shortcode'] . $this->config['passkey'] . $timestamp);

        $payload = [
            'BusinessShortCode' => $this->config['business_shortcode'],
            'Password' => $password,
            'Timestamp' => $timestamp,
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => (int) $amount, // M-Pesa expects integer amounts
            'PartyA' => $phone,
            'PartyB' => $this->config['business_shortcode'],
            'PhoneNumber' => $phone,
            'CallBackURL' => $callbackUrl ?? $this->config['callback_url'],
            'AccountReference' => $reference ?? $this->config['account_reference'],
            'TransactionDesc' => $description ?? $this->config['transaction_desc'],
        ];

        try {
            $response = $this->client->post(
                $this->config['base_url'] . '/mpesa/stkpush/v1/processrequest',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            return $this->handleApiResponse($response, 'STK Push');
        } catch (RequestException $e) {
            return $this->errorResponse('STK Push failed: ' . $e->getMessage(), [], $e->getCode());
        }
    }

    /**
     * Check transaction status
     */
    public function checkTransactionStatus(
        string $transactionId,
        string $identifierType = '1',
        ?string $remarks = null,
        ?string $occasion = null
    ): array {
        $token = $this->getAccessToken();
        if (!$token) {
            return $this->errorResponse('Failed to obtain access token', [], 401);
        }

        $payload = [
            'Initiator' => $this->config['initiator_name'],
            'SecurityCredential' => $this->generateSecurityCredential(),
            'CommandID' => 'TransactionStatusQuery',
            'TransactionID' => $transactionId,
            'PartyA' => $this->config['business_shortcode'],
            'IdentifierType' => $identifierType,
            'ResultURL' => $this->config['callback_url'],
            'QueueTimeOutURL' => $this->config['callback_url'],
            'Remarks' => $remarks ?? 'Transaction status query',
            'Occasion' => $occasion ?? 'Check transaction status',
        ];

        try {
            $response = $this->client->post(
                $this->config['base_url'] . '/mpesa/transactionstatus/v1/query',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => $payload,
                ]
            );

            return $this->handleApiResponse($response, 'Transaction Status');
        } catch (RequestException $e) {
            return $this->errorResponse('Transaction Status Query failed: ' . $e->getMessage(), [], $e->getCode());
        }
    }

    /**
     * Process M-Pesa callback
     */
    public function processCallback(array $callbackData): array
    {
        Log::info('M-Pesa Callback Received', $callbackData);

        if (!isset($callbackData['Body']['stkCallback'])) {
            return $this->errorResponse('Invalid callback format');
        }

        $callback = $callbackData['Body']['stkCallback'];
        $resultCode = $callback['ResultCode'] ?? null;
        $metadata = $this->parseCallbackMetadata($callback['CallbackMetadata'] ?? []);

        if ($resultCode === '0') {
            return [
                'success' => true,
                'message' => $callback['ResultDesc'] ?? 'Payment successful',
                'data' => [
                    'merchant_request_id' => $callback['MerchantRequestID'] ?? null,
                    'checkout_request_id' => $callback['CheckoutRequestID'] ?? null,
                    'result_code' => $resultCode,
                    'amount' => $metadata['Amount'] ?? null,
                    'mpesa_receipt' => $metadata['MpesaReceiptNumber'] ?? null,
                    'transaction_date' => $metadata['TransactionDate'] ?? null,
                    'phone_number' => $metadata['PhoneNumber'] ?? null,
                ]
            ];
        }

        return $this->errorResponse(
            $callback['ResultDesc'] ?? 'Payment failed',
            [
                'result_code' => $resultCode,
                'request_id' => $callback['MerchantRequestID'] ?? null,
                'checkout_id' => $callback['CheckoutRequestID'] ?? null
            ]
        );
    }

    protected function formatPhoneNumber(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($phone) === 9 && str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (strlen($phone) === 10 && str_starts_with($phone, '0')) {
            return '254' . substr($phone, 1);
        }

        if (!str_starts_with($phone, '254') || strlen($phone) !== 12) {
            throw new \InvalidArgumentException('Invalid phone number format: ' . $phone);
        }

        return $phone;
    }

    protected function generateSecurityCredential(): string
    {
        if (empty($this->config['initiator_password'])) {
            throw new \RuntimeException('Initiator password not configured');
        }

        $publicKeyPath = config('mpesa.public_key_path');
        if (!file_exists($publicKeyPath)) {
            throw new \RuntimeException('M-Pesa public key not found at: ' . $publicKeyPath);
        }

        $publicKey = file_get_contents($publicKeyPath);
        if (!$publicKey) {
            throw new \RuntimeException('Failed to read M-Pesa public key');
        }

        if (!openssl_public_encrypt($this->config['initiator_password'], $encrypted, $publicKey, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('Failed to encrypt initiator password');
        }

        return base64_encode($encrypted);
    }

    protected function parseCallbackMetadata(array $metadata): array
    {
        $result = [];
        foreach ($metadata['Item'] ?? [] as $item) {
            $result[$item['Name']] = $item['Value'] ?? null;
        }
        return $result;
    }

    protected function handleApiResponse($response, string $action): array
    {
        $status = $response->getStatusCode();
        $data = json_decode($response->getBody(), true) ?? [];

        if ($status !== 200) {
            Log::error("M-Pesa $action Error", [
                'status' => $status,
                'response' => $data
            ]);
            return $this->errorResponse(
                $data['errorMessage'] ?? "$action request failed",
                $data,
                $status
            );
        }

        if (($data['ResponseCode'] ?? '1') !== '0') {
            return $this->errorResponse(
                $data['ResponseDescription'] ?? "$action failed",
                $data
            );
        }

        return [
            'success' => true,
            'message' => $data['ResponseDescription'] ?? "$action successful",
            'data' => $data
        ];
    }

    protected function errorResponse(
        string $message,
        array $data = [],
        int $httpCode = 400
    ): array {
        Log::error('M-Pesa Error', [
            'message' => $message,
            'data' => $data,
            'http_code' => $httpCode
        ]);

        return [
            'success' => false,
            'message' => $message,
            'data' => $data,
            'http_code' => $httpCode
        ];
    }
}