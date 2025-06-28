<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use App\Models\SystemLog;
use RouterOS\Client;
use RouterOS\Query;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;

class MikrotikService
{
    protected $client;
    protected $config;
    protected $connectionError;

    public function __construct()
    {
        $this->config = config('mikrotik');
        $this->connectionError = null;
    }

    protected function connect(): void
    {
        if ($this->client && $this->isConnected()) {
            return;
        }

        try {
            $this->client = new Client([
                'host' => $this->config['host'],
                'user' => $this->config['user'],
                'pass' => $this->config['pass'],
                'port' => $this->config['port'],
                'timeout' => $this->config['timeout'] ?? 10,
                'attempts' => $this->config['attempts'] ?? 3,
                'delay' => $this->config['delay'] ?? 1,
            ]);

            $this->logToSystemAndFile(
                'Mikrotik connection established',
                ['host' => $this->config['host']],
                'info'
            );

        } catch (ClientException | ConfigException | QueryException | \Exception $e) {
            $this->connectionError = $e->getMessage();
            $this->logToSystemAndFile(
                'Mikrotik connection failed',
                [
                    'error' => $this->connectionError,
                    'config' => $this->sanitizeConfig($this->config)
                ],
                'error'
            );
            throw new \RuntimeException('Mikrotik connection failed: ' . $this->connectionError);
        }
    }

    protected function isConnected(): bool
    {
        try {
            $this->client->query('/system/identity/print')->read();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function createSession(string $voucher, string $macAddress, string $profile, int $durationHours): array
    {
        try {
            $this->connect();

            $uptime = $durationHours . 'h';
            $cacheKey = "mikrotik_session_{$macAddress}_{$voucher}";

            if (Cache::has($cacheKey)) {
                return [
                    'success' => true,
                    'message' => 'Session already exists',
                    'cached' => true
                ];
            }

            // Create hotspot user
            $userResponse = $this->createHotspotUser($voucher, $macAddress, $profile, $uptime);
            
            // Authenticate user
            $authResponse = $this->authenticateUser($voucher);
            
            if (!$authResponse['success']) {
                throw new \Exception('User created but authentication failed: ' . $authResponse['message']);
            }

            Cache::put($cacheKey, true, now()->addMinutes(5));

            $this->logToSystemAndFile(
                'Mikrotik user created and authenticated',
                [
                    'voucher' => $voucher,
                    'mac_address' => $macAddress,
                    'profile' => $profile,
                    'duration' => $uptime,
                    'user_response' => $userResponse,
                    'auth_response' => $authResponse
                ],
                'info'
            );

            return [
                'success' => true,
                'message' => 'User created and authenticated successfully',
                'data' => [
                    'user_creation' => $userResponse,
                    'authentication' => $authResponse
                ]
            ];

        } catch (\Exception $e) {
            $this->logToSystemAndFile(
                'Mikrotik session creation failed',
                [
                    'voucher' => $voucher,
                    'mac_address' => $macAddress,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                'error'
            );

            return [
                'success' => false,
                'message' => 'Session creation failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500
            ];
        }
    }

    protected function createHotspotUser(string $voucher, string $macAddress, string $profile, string $uptime): array
    {
        $query = (new Query('/ip/hotspot/user/add'))
            ->equal('name', $voucher)
            ->equal('password', $voucher)
            ->equal('mac-address', $macAddress)
            ->equal('profile', $profile)
            ->equal('limit-uptime', $uptime)
            ->equal('comment', 'Created via API on ' . now()->toDateTimeString());

        $response = $this->client->query($query)->read();

        if (empty($response[0]['ret'])) {
            throw new \Exception('Failed to create user: No response ID received');
        }

        return $response;
    }

    public function authenticateUser(string $voucher): array
    {
        try {
            $this->connect();

            $query = (new Query('/ip/hotspot/active/login'))
                ->equal('user', $voucher)
                ->equal('password', $voucher);

            $response = $this->client->query($query)->read();

            $this->logToSystemAndFile(
                'Mikrotik user authenticated',
                [
                    'voucher' => $voucher,
                    'response' => $response
                ],
                'info'
            );

            return [
                'success' => true,
                'message' => 'User authenticated successfully',
                'data' => $response
            ];

        } catch (\Exception $e) {
            $this->logToSystemAndFile(
                'Mikrotik authentication failed',
                [
                    'voucher' => $voucher,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                'error'
            );

            return [
                'success' => false,
                'message' => 'Authentication failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500
            ];
        }
    }

    public function getActiveUsers(): array
    {
        try {
            $this->connect();

            $query = new Query('/ip/hotspot/active/print');
            $users = $this->client->query($query)->read();

            return [
                'success' => true,
                'data' => $users,
                'count' => count($users)
            ];

        } catch (\Exception $e) {
            $this->logToSystemAndFile(
                'Failed to get active users',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                'error'
            );

            return [
                'success' => false,
                'message' => 'Failed to get active users',
                'error' => $e->getMessage()
            ];
        }
    }

    protected function logToSystemAndFile(string $action, array $details, string $logLevel = 'info'): void
    {
        $sanitizedDetails = $this->sanitizeLogData($details);

        SystemLog::create([
            'action' => $action,
            'details' => $sanitizedDetails,
        ]);

        Log::$logLevel($action, $sanitizedDetails);
    }

    protected function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['pass', 'password', 'secret', 'auth'];
        
        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys)) {
                $value = '*****';
            }
        });

        return $data;
    }

    protected function sanitizeConfig(array $config): array
    {
        return $this->sanitizeLogData($config);
    }
}