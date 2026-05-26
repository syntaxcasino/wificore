<?php

namespace App\Services;

use App\Models\SystemLog;
use Illuminate\Support\Facades\Log;

class MikrotikSessionService extends TenantAwareService
{
    protected array $config;
    protected ?string $connectionError;
    protected ProvisioningServiceClient $provisioningClient;

    public function __construct()
    {
        $this->config = config('mikrotik');
        $this->connectionError = null;
        $this->provisioningClient = app(ProvisioningServiceClient::class);
    }

    protected function connect(): void
    {
        try {
            $this->execute(['/system identity print']);
        } catch (\Exception $e) {
            $this->connectionError = $e->getMessage();
            $this->logToSystemAndFile('Mikrotik connection failed', [
                'error' => $this->connectionError,
                'config' => $this->sanitizeConfig($this->config),
            ], 'error');
            throw new \RuntimeException('Mikrotik connection failed: ' . $this->connectionError);
        }
    }

    public function createSession(string $voucher, string $macAddress, string $profile, int $durationHours): array
    {
        try {
            $this->connect();

            $existing = $this->execute([
                sprintf('/ip/hotspot/active/print detail without-paging where mac-address="%s"', addslashes($macAddress)),
            ])[0]['output'] ?? '';

            if (trim($existing) !== '') {
                return [
                    'success' => true,
                    'message' => 'Session already active in MikroTik',
                    'data' => ['existing_session' => $existing],
                ];
            }

            $uptime = $durationHours . 'h';
            $comment = 'Created via API on ' . now()->toDateTimeString();

            $create = $this->execute([
                sprintf('/ip/hotspot/user/add name="%s" password="%s" mac-address="%s" profile="%s" limit-uptime="%s" comment="%s"', addslashes($voucher), addslashes($voucher), addslashes($macAddress), addslashes($profile), addslashes($uptime), addslashes($comment)),
            ]);

            $authResponse = $this->authenticateUser($voucher);
            if (!($authResponse['success'] ?? false)) {
                throw new \Exception('User created but authentication failed: ' . ($authResponse['message'] ?? 'unknown error'));
            }

            return [
                'success' => true,
                'message' => 'User created and authenticated successfully',
                'data' => [
                    'user_creation' => $create,
                    'authentication' => $authResponse,
                ],
            ];
        } catch (\Exception $e) {
            $this->logToSystemAndFile('Mikrotik session creation failed', [
                'voucher' => $voucher,
                'mac_address' => $macAddress,
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'success' => false,
                'message' => 'Session creation failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ];
        }
    }

    public function authenticateUser(string $voucher): array
    {
        try {
            $this->connect();
            $results = $this->execute([
                sprintf('/ip/hotspot/active/login user="%s" password="%s"', addslashes($voucher), addslashes($voucher)),
            ]);

            return [
                'success' => true,
                'message' => 'User authenticated successfully',
                'data' => $results,
            ];
        } catch (\Exception $e) {
            $this->logToSystemAndFile('Mikrotik authentication failed', [
                'voucher' => $voucher,
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'success' => false,
                'message' => 'Authentication failed: ' . $e->getMessage(),
                'error' => $e->getMessage(),
                'code' => $e->getCode() ?: 500,
            ];
        }
    }

    public function getActiveUsers(): array
    {
        return $this->readList('/ip/hotspot/active/print detail without-paging', 'Failed to get active users');
    }

    public function getAllHotspotUsers(): array
    {
        return $this->readList('/ip/hotspot/user/print detail without-paging', 'Failed to get all hotspot users');
    }

    public function disconnectUser(string $macAddress): array
    {
        try {
            $this->connect();

            $active = $this->execute([
                sprintf('/ip/hotspot/active/print detail without-paging where mac-address="%s"', addslashes($macAddress)),
            ])[0]['output'] ?? '';

            if (trim($active) === '') {
                return [
                    'success' => false,
                    'message' => 'No active session found for MAC address',
                ];
            }

            $this->execute([
                sprintf(':do { /ip hotspot active remove [find mac-address="%s"] } on-error={}', addslashes($macAddress)),
            ]);

            $this->logToSystemAndFile('Hotspot session disconnected', [
                'mac_address' => $macAddress,
            ], 'info');

            return [
                'success' => true,
                'message' => 'User disconnected successfully',
            ];
        } catch (\Exception $e) {
            $this->logToSystemAndFile('Failed to disconnect user', [
                'mac_address' => $macAddress,
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'success' => false,
                'message' => 'Failed to disconnect user: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function execute(array $commands): array
    {
        return $this->provisioningClient->executeCommandsWithConnection(
            'mikrotik-session-service',
            $this->connectionPayload(),
            $commands,
            $this->tenantId(),
        );
    }

    protected function readList(string $command, string $failureMessage): array
    {
        try {
            $this->connect();
            $results = $this->execute([$command]);
            $output = $results[0]['output'] ?? '';

            return [
                'success' => true,
                'data' => $this->parseDetailBlocks($output),
                'count' => count($this->parseDetailBlocks($output)),
            ];
        } catch (\Exception $e) {
            $this->logToSystemAndFile($failureMessage, [
                'error' => $e->getMessage(),
            ], 'error');

            return [
                'success' => false,
                'message' => $failureMessage,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function connectionPayload(): array
    {
        return [
            'ip_address' => (string) ($this->config['host'] ?? ''),
            'vpn_ip' => null,
            'username' => (string) ($this->config['user'] ?? ''),
            'password' => (string) ($this->config['pass'] ?? ''),
            'ssh_port' => (int) ($this->config['port'] ?? 22),
        ];
    }

    protected function tenantId(): string
    {
        return (string) ($this->config['tenant_id'] ?? 'system');
    }

    protected function parseDetailBlocks(string $output): array
    {
        $records = [];
        $current = [];

        foreach (preg_split('/?
/', $output) as $line) {
            $line = trim($line);
            if ($line === '') {
                if ($current !== []) {
                    $records[] = $current;
                    $current = [];
                }
                continue;
            }

            if (preg_match('/^\d+\s+/', $line) && $current !== []) {
                $records[] = $current;
                $current = [];
            }

            if (preg_match_all('/([A-Za-z0-9_.\/-]+):\s*([^\s].*?)(?=\s+[A-Za-z0-9_.\/-]+:|$)/', $line, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $current[$match[1]] = trim($match[2], '"');
                }
            }
        }

        if ($current !== []) {
            $records[] = $current;
        }

        return $records;
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
        }

        Log::$logLevel($action, $sanitizedDetails);
    }

    protected function sanitizeLogData(array $data): array
    {
        $sensitiveKeys = ['pass', 'password', 'secret', 'auth'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower($key), $sensitiveKeys, true)) {
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
