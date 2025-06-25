<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    protected $client;

    public function __construct()
    {
        $config = [
            'host' => config('mikrotik.host', '192.168.100.1'),
            'user' => config('mikrotik.user', 'admin'),
            'pass' => config('mikrotik.password', ''),
            'port' => config('mikrotik.port', 8728),
        ];

        try {
            $this->client = new Client($config);
            Log::info('Mikrotik connection established', ['host' => $config['host']]);
        } catch (\Exception $e) {
            Log::error('Mikrotik connection failed', ['error' => $e->getMessage()]);
            throw new \RuntimeException('Failed to connect to Mikrotik: ' . $e->getMessage());
        }
    }

    public function createSession(string $voucher, string $macAddress, string $profile, int $durationHours): void
    {
        try {
            $uptime = $durationHours . 'h';
            $query = new Query('/ip/hotspot/user/add');
            $query->equal('name', $voucher)
                  ->equal('password', $voucher)
                  ->equal('mac-address', $macAddress)
                  ->equal('profile', $profile)
                  ->equal('limit-uptime', $uptime);

            $response = $this->client->query($query)->read();
            if (isset($response['ret'])) {
                Log::info('Mikrotik user created', [
                    'voucher' => $voucher,
                    'mac_address' => $macAddress,
                    'profile' => $profile,
                ]);
            } else {
                throw new \Exception('Failed to create user');
            }
        } catch (\Exception $e) {
            Log::error('Mikrotik user creation failed', [
                'voucher' => $voucher,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function authenticateUser(string $voucher): void
    {
        try {
            $query = new Query('/ip/hotspot/active/login');
            $query->equal('user', $voucher)
                  ->equal('password', $voucher);

            $response = $this->client->query($query)->read();
            if (isset($response['ret'])) {
                Log::info('Mikrotik user authenticated', ['voucher' => $voucher]);
            } else {
                throw new \Exception('Failed to authenticate user');
            }
        } catch (\Exception $e) {
            Log::error('Mikrotik authentication failed', [
                'voucher' => $voucher,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
}