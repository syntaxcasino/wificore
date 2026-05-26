<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\ProvisioningServiceClient;
use Illuminate\Support\Facades\Log;

class SshExecutor
{
    private Router $router;
    private int $timeout;
    private ProvisioningServiceClient $provisioningClient;
    private bool $connected = false;
    private array $stagedFiles = [];

    public function __construct(Router $router, int $timeout = 30)
    {
        $this->router = $router;
        $this->timeout = $timeout;
        $this->provisioningClient = app(ProvisioningServiceClient::class);
    }

    public function setTimeout(int $timeout): void
    {
        $this->timeout = $timeout;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function connect(): bool
    {
        $this->connected = true;
        return true;
    }

    public function exec(string $command, int $retries = 3, ?callable $delayCallback = null): string
    {
        $attempt = 0;

        do {
            $attempt++;

            try {
                $results = $this->provisioningClient->executeCommands(
                    $this->router,
                    [$command],
                    $this->resolveTenantId(),
                );

                $result = $results[0] ?? [];
                $error = trim((string) ($result['error'] ?? ''));
                if ($error !== '') {
                    throw new \RuntimeException($error);
                }

                $this->connected = true;
                return (string) ($result['output'] ?? '');
            } catch (\Exception $e) {
                Log::warning('Go-backed SSH command attempt failed', [
                    'router_id' => $this->router->id,
                    'attempt' => $attempt,
                    'command' => $command,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $retries) {
                    throw $e;
                }

                if ($delayCallback) {
                    $delayCallback($attempt);
                } else {
                    usleep((2 ** $attempt) * 500000);
                }
            }
        } while ($attempt < $retries);

        throw new \RuntimeException("Failed to execute command after {$retries} attempts: {$command}");
    }

    public function execBatch(array $commands, int $retries = 3, ?callable $delayCallback = null): array
    {
        $results = [];
        foreach ($commands as $command) {
            try {
                $results[$command] = $this->exec((string) $command, $retries, $delayCallback);
            } catch (\Exception $e) {
                $results[$command] = 'ERROR: ' . $e->getMessage();
            }
        }

        return $results;
    }

    public function uploadRsc(string $localPath, string $remotePath): bool
    {
        if (!is_readable($localPath)) {
            Log::error('Local RSC file not readable', [
                'router_id' => $this->router->id,
                'local_path' => $localPath,
            ]);
            return false;
        }

        $this->stagedFiles[$remotePath] = file_get_contents($localPath) ?: '';
        return true;
    }

    public function uploadFile(string $localPath, string $remotePath): bool
    {
        return $this->uploadRsc($localPath, $remotePath);
    }

    public function importFile(string $remotePath): string
    {
        if (trim($remotePath) === '') {
            throw new \InvalidArgumentException('Remote path is required for import');
        }

        if (array_key_exists($remotePath, $this->stagedFiles)) {
            $response = $this->provisioningClient->deployScript(
                $this->router,
                $this->stagedFiles[$remotePath],
                $this->resolveTenantId(),
            );

            return (string) ($response['message'] ?? 'Script deployed successfully');
        }

        return $this->exec('/import file-name=' . $remotePath);
    }

    public function deleteFile(string $remotePath): void
    {
        if (trim($remotePath) === '') {
            throw new \InvalidArgumentException('Remote path is required for delete');
        }

        if (array_key_exists($remotePath, $this->stagedFiles)) {
            unset($this->stagedFiles[$remotePath]);
            return;
        }

        $this->exec('/file remove [find name="' . addslashes($remotePath) . '"]');
    }

    public function disconnect(bool $log = true): void
    {
        $this->connected = false;
        $this->stagedFiles = [];

        if ($log) {
            Log::info('Go-backed SSH session disconnected', ['router_id' => $this->router->id]);
        }
    }

    private function resolveTenantId(): string
    {
        $tenantId = (string) ($this->router->tenant_id ?? '');
        if ($tenantId === '') {
            throw new \RuntimeException('Router tenant context is not available for provisioning service execution.');
        }

        return $tenantId;
    }
}
