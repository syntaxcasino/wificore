<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Models\SystemLog;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use App\Models\SystemLog;

class MpesaService
{
    protected $client;
    protected $config;
    protected $client;
    protected $config;

    public function __construct()
    {
        $this->config = config('mpesa');
        $this->config = config('mpesa');
        $this->client = new Client([
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['timeout'] ?? 30,
            'verify' => $this->config['verify_ssl'] ?? true,
        ]);
            'base_uri' => $this->config['base_url'],
            'timeout' => $this->config['timeout'] ?? 30,
            'verify' => $this->config['verify_ssl'] ?? true,
        ]);
    }

    public function initiateSTKPush(string $phoneNumber, float $amount): array
    {
        try {
            $shortcode = $this->config['business_shortcode'];
            $timestamp = date('YmdHis');
            $password = base64_encode($shortcode . $this->config['passkey'] . $timestamp);
            $phoneNumber = preg_replace('/^\+/', '', $phoneNumber);

            $payload = [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $this->config['transaction_type'],
                'Amount' => $amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => $this->config['callback_url'],
                'AccountReference' => $this->config['account_reference'],
                'TransactionDesc' => $this->config['transaction_desc'],
            ];
            $payload = [
                'BusinessShortCode' => $shortcode,
                'Password' => $password,
                'Timestamp' => $timestamp,
                'TransactionType' => $this->config['transaction_type'],
                'Amount' => $amount,
                'PartyA' => $phoneNumber,
                'PartyB' => $shortcode,
                'PhoneNumber' => $phoneNumber,
                'CallBackURL' => $this->config['callback_url'],
                'AccountReference' => $this->config['account_reference'],
                'TransactionDesc' => $this->config['transaction_desc'],
            ];

            $this->logRequest('STK Push Initiation', $payload);
            $this->logRequest('STK Push Initiation', $payload);

            $response = $this->client->post('mpesa/stkpush/v1/processrequest', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getAccessToken(),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            $this->logResponse('STK Push Response', $responseData);

            if (!isset($responseData['ResponseCode']) || $responseData['ResponseCode'] !== '0') {
                throw new \Exception($responseData['errorMessage'] ?? 'STK Push failed');
            }

            return [
                'success' => true,
                'message' => 'STK Push initiated successfully',
                'data' => [
                    'CheckoutRequestID' => $responseData['CheckoutRequestID'],
                    'MerchantRequestID' => $responseData['MerchantRequestID'],
                ],
            ];

        } catch (\Exception | GuzzleException $e) {
            $this->logError('STK Push Failed', $e, isset($payload) ? $payload : []);
            return [
                'success' => false,
                'message' => $e->getMessage(),
                'code' => method_exists($e, 'getCode') ? $e->getCode() : 500,
            ];
        }
    }

    public function processCallback(array $callbackData): array
    {
        try {
            $stkCallback = $callbackData['Body']['stkCallback'] ?? null;
            if (!$stkCallback) {
                throw new \Exception('Missing stkCallback data');
            }

            $resultCode = (int) $stkCallback['ResultCode'];
            $resultMessage = $this->getResultCodeMessage($resultCode);

            $data = [
                'amount' => $stkCallback['CallbackMetadata']['Item'][0]['Value'] ?? null,
                'mpesa_receipt' => $stkCallback['CallbackMetadata']['Item'][1]['Value'] ?? null,
                'phone_number' => $stkCallback['CallbackMetadata']['Item'][4]['Value'] ?? null,
            ];

            return [
                'success' => $resultCode === 0,
                'message' => $resultMessage,
                'data' => $data,
            ];

        } catch (\Exception $e) {
            $this->logError('Callback Processing Failed', $e);
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    public function getResultCodeMessage(int $code): string
    {
        return match ($code) {
            0 => 'The service request is processed successfully.',
            1 => 'Insufficient funds on M-PESA or declined Fuliza.',
            1001 => 'Another transaction is already in process.',
            1019 => 'Transaction expired before completion.',
            1025 => 'System error. Retry request.',
            1032 => 'Request was cancelled by the user.',
            1037 => 'Could not reach phone or no response from user.',
            2001 => 'Invalid M-PESA initiator credentials.',
            9999 => 'Unknown system error.',
            default => 'An unknown ResultCode was returned.',
        };
    }

    public function getAccessToken(): string
    {
        try {
            $response = $this->client->get('oauth/v1/generate', [
                'query' => ['grant_type' => 'client_credentials'],
                'auth' => [
                    $this->config['consumer_key'],
                    $this->config['consumer_secret'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'] ?? '';
        } catch (\Exception | GuzzleException $e) {
            $this->logError('Access Token Failed', $e);
            throw new \Exception('Failed to get access token: ' . $e->getMessage());
        try {
            $response = $this->client->get('oauth/v1/generate', [
                'query' => ['grant_type' => 'client_credentials'],
                'auth' => [
                    $this->config['consumer_key'],
                    $this->config['consumer_secret'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            return $data['access_token'] ?? '';
        } catch (\Exception | GuzzleException $e) {
            $this->logError('Access Token Failed', $e);
            throw new \Exception('Failed to get access token: ' . $e->getMessage());
        }
    }

    protected function logRequest(string $action, array $data): void
    protected function logRequest(string $action, array $data): void
    {
        $sanitizedData = $this->sanitizeLogData($data);
        Log::info($action, $sanitizedData);
        SystemLog::create(['action' => $action, 'details' => $sanitizedData]);
        $sanitizedData = $this->sanitizeLogData($data);
        Log::info($action, $sanitizedData);
        SystemLog::create(['action' => $action, 'details' => $sanitizedData]);
    }

    protected function logResponse(string $action, array $data): void
    protected function logResponse(string $action, array $data): void
    {
        $sanitizedData = $this->sanitizeLogData($data);
        Log::debug($action, $sanitizedData);
        SystemLog::create(['action' => $action, 'details' => $sanitizedData]);
    }

    protected function logError(string $action, \Throwable $e, ?array $context = []): void
    {
        $logData = [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        if (!empty($context)) {
            $logData['context'] = $this->sanitizeLogData($context);
        }

        Log::error($action, $logData);
        SystemLog::create(['action' => $action, 'details' => $logData]);
        $sanitizedData = $this->sanitizeLogData($data);
        Log::debug($action, $sanitizedData);
        SystemLog::create(['action' => $action, 'details' => $sanitizedData]);
    }

    protected function logError(string $action, \Throwable $e, ?array $context = []): void
    {
        $logData = [
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];

        if (!empty($context)) {
            $logData['context'] = $this->sanitizeLogData($context);
        }

        Log::error($action, $logData);
        SystemLog::create(['action' => $action, 'details' => $logData]);
    }

    protected function sanitizeLogData(array $data): array
    {
        $sensitiveFields = ['password', 'access_token', 'auth', 'authorization'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeLogData($value);
            } elseif (in_array(strtolower($key), $sensitiveFields)) {
                $data[$key] = '*****';
            }
        }

        return $data;
    protected function sanitizeLogData(array $data): array
    {
        $sensitiveFields = ['password', 'access_token', 'auth', 'authorization'];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sanitizeLogData($value);
            } elseif (in_array(strtolower($key), $sensitiveFields)) {
                $data[$key] = '*****';
            }
        }

        return $data;
    }
}

