<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use App\Models\SystemLog;
use App\Models\Router;
use App\Models\Voucher;
use RouterOS\Client;
use RouterOS\Query;
use RouterOS\Exceptions\ClientException;
use RouterOS\Exceptions\ConfigException;
use RouterOS\Exceptions\QueryException;

class MikrotikSessionService extends TenantAwareService
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
            $this->client = $this->createClient([
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

    protected function createClient(array $config): Client
    {
        return new Client($config);
    }

    public function createSession(string $voucher, string $macAddress, string $profile, int $durationHours): array
    {
        try {
            $this->connect();

            $uptime = $durationHours . 'h';

            // Check if session already exists in MikroTik (NOT in our cache)
            // Query MikroTik directly to avoid stale cache issues
            $existingQuery = (new Query('/ip/hotspot/active/print'))
                ->where('mac-address', $macAddress);
            $existing = $this->client->query($existingQuery)->read();
            
            if (!empty($existing)) {
                return [
                    'success' => true,
                    'message' => 'Session already active in MikroTik',
                    'data' => ['existing_session' => $existing[0]]
                ];
            }

            // Create hotspot user
            $userResponse = $this->createHotspotUser($voucher, $macAddress, $profile, $uptime);
            
            // Authenticate user
            $authResponse = $this->authenticateUser($voucher);
            
            if (!$authResponse['success']) {
                throw new \Exception('User created but authentication failed: ' . $authResponse['message']);
            }

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

        // Log raw response for debugging
        Log::info('Mikrotik add user raw response', ['response' => $response]);

        // Instead of strictly checking 'ret', check for error conditions
        if (empty($response)) {
            throw new \Exception('Failed to create user: Mikrotik returned an empty response.');
        }

        if (isset($response[0]['!trap'])) {
            throw new \Exception('Mikrotik returned an error: ' . json_encode($response));
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

    /**
     * Get all hotspot users from Mikrotik
     */
    public function getAllHotspotUsers(): array
    {
        try {
            $this->connect();

            $query = new Query('/ip/hotspot/user/print');
            $users = $this->client->query($query)->read();

            return [
                'success' => true,
                'data' => $users,
                'count' => count($users)
            ];

        } catch (\Exception $e) {
            $this->logToSystemAndFile(
                'Failed to get all hotspot users',
                [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ],
                'error'
            );

            return [
                'success' => false,
                'message' => 'Failed to get all hotspot users',
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Disconnect an active hotspot user by MAC address
     */
    public function disconnectUser(string $macAddress): array
    {
        try {
            $this->connect();

            // Find active session by MAC address
            $activeQuery = (new Query('/ip/hotspot/active/print'))
                ->where('mac-address', $macAddress);
            $active = $this->client->query($activeQuery)->read();

            if (empty($active)) {
                return [
                    'success' => false,
                    'message' => 'No active session found for MAC address',
                ];
            }

            $id = $active[0]['.id'] ?? null;
            if (!$id) {
                return [
                    'success' => false,
                    'message' => 'Unable to determine session ID for disconnection',
                ];
            }

            // Disconnect the active session
            $removeQuery = (new Query('/ip/hotspot/active/remove'))
                ->equal('.id', $id);
            $this->client->query($removeQuery)->read();

            $this->logToSystemAndFile('Hotspot session disconnected', [
                'mac_address' => $macAddress,
                'session_id' => $id,
            ], 'info');

            return [
                'success' => true,
                'message' => 'User disconnected successfully',
            ];

        } catch (\Exception $e) {
            $this->logToSystemAndFile('Failed to disconnect user', [
                'mac_address' => $macAddress,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ], 'error');

            return [
                'success' => false,
                'message' => 'Failed to disconnect user: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function logToSystemAndFile(string $action, array $details, string $logLevel = 'info'): void
    {
        $sanitizedDetails = $this->sanitizeLogData($details);

        try {
            SystemLog::create([
                'action' => $action,
                'details' => $sanitizedDetails,
            ]);
        } catch (\Throwable $e) {
            // Swallow DB errors so logging never crashes the service
        }

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
