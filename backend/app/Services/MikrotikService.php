<?php
namespace App\Services;

use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    private $client;

    public function __construct()
    {
        $this->client = new Client([
            'host' => env('MIKROTIK_HOST', '192.168.1.1'),
            'user' => env('MIKROTIK_USER', 'admin'),
            'pass' => env('MIKROTIK_PASS', 'password')
        ]);
    }

    public function createUser($macAddress, $voucher, $package)
    {
        $query = new Query('/ip/hotspot/user/add');
        $query->equal('name', $macAddress);
        $query->equal('password', $voucher);
        $query->equal('profile', $package->mikrotik_profile);
        $this->client->query($query)->read();
    }

    public function disconnectUser($macAddress)
    {
        $query = new Query('/ip/hotspot/active/remove');
        $query->equal('user', $macAddress);
        $this->client->query($query)->read();
    }

    public function updateUsage($macAddress)
    {
        $query = new Query('/ip/hotspot/active/print');
        $query->where('user', $macAddress);
        $response = $this->client->query($query)->read();

        if (!empty($response)) {
            $session = UserSession::where('mac_address', $macAddress)->latest()->first();
            $session->update([
                'upload' => $response[0]['bytes-out'],
                'download' => $response[0]['bytes-in']
            ]);
        }
    }
}

