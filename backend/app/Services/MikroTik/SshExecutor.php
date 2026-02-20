<?php

namespace App\Services\MikroTik;

use App\Models\RouterService;
use App\Services\RouterResourceManager;
use App\Models\Router;
use App\Models\TenantIpPool;
use App\Services\PasswordEncryptionService;
use phpseclib3\Net\SSH2;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class SshExecutor
{
    private Router $router;
    private int $timeout;
    private SSH2 $connection;
    private ?SFTP $sftp = null;
    private ?string $decryptedPassword = null;

    public function __construct(Router $router, int $timeout = 30)
    {
        $this->router = $router;
        $this->timeout = $timeout;
    }

    /**
     * Connect using SSH key or password fallback.
     */
    public function connect(): bool
    {
        $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];
        $port = $this->router->port ?? 22;

        $this->connection = new SSH2($host, $port, $this->timeout);

        // Key-based login (decrypt encrypted ssh_key blob)
        if (!empty($this->router->ssh_key)) {
            try {
                $rawKey = Crypt::decrypt($this->router->ssh_key);
                $key = PublicKeyLoader::load($rawKey);
                if ($this->connection->login($this->router->username, $key)) {
                    Log::info("SSH key login successful", ['router' => $host]);
                    return true;
                }
            } catch (\Exception $e) {
                Log::warning("SSH key login failed: {$e->getMessage()}", ['router' => $host]);
            }
        }

        // Password fallback
        if ($this->router->password) {
            $this->decryptedPassword = PasswordEncryptionService::safeDecrypt($this->router);
            if ($this->connection->login($this->router->username, $this->decryptedPassword)) {
                Log::info("SSH password login successful", ['router' => $host]);
                return true;
            }
            $this->destroyPassword();
        }

        Log::error("SSH connection failed", ['router' => $host, 'username' => $this->router->username]);
        return false;
    }

    /**
     * Ensure SFTP session is connected and verified.
     */
    private function ensureSftpConnected(): SFTP
    {
        if ($this->sftp && $this->sftp->isConnected()) {
            return $this->sftp;
        }

        $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];
        $this->sftp = new SFTP($host, $this->router->port ?? 22);

        $loginSuccess = false;
        if (!empty($this->router->ssh_key)) {
            try {
                $rawKey = Crypt::decrypt($this->router->ssh_key);
                $key = PublicKeyLoader::load($rawKey);
                $loginSuccess = $this->sftp->login($this->router->username, $key);
            } catch (\Exception $e) {
                Log::warning("SFTP key login failed: {$e->getMessage()}", ['router' => $host]);
            }
        }

        if (!$loginSuccess && $this->decryptedPassword) {
            $loginSuccess = $this->sftp->login($this->router->username, $this->decryptedPassword);
        }

        if (!$loginSuccess) {
            throw new \RuntimeException("No credentials available for SFTP login on router {$host}");
        }

        if (!$loginSuccess) {
            throw new \RuntimeException("SFTP login failed for router {$host}");
        }

        return $this->sftp;
    }

    /**
     * Execute a command with optional retry and async-friendly delay callback.
     */
    public function exec(string $command, int $retries = 3, ?callable $delayCallback = null): string
    {
        $attempt = 0;
        $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];

        do {
            $attempt++;
            try {
                if (!$this->connection->isConnected()) {
                    $this->reconnect();
                }

                $output = $this->connection->exec($command);

                if ($this->isCommandError($output)) {
                    throw new \RuntimeException("Command failed: $command | Output: $output");
                }

                Log::info("SSH command executed successfully", ['router' => $host, 'command' => $command]);
                return $output;
            } catch (\Exception $e) {
                Log::warning("SSH command attempt $attempt failed: {$e->getMessage()}", ['router' => $host]);

                if ($attempt >= $retries) {
                    throw $e;
                }

                if ($delayCallback) {
                    $delayCallback($attempt);
                } else {
                    usleep(pow(2, $attempt) * 500_000); // exponential backoff
                }

                $this->reconnect();
            }
        } while ($attempt < $retries);

        throw new \RuntimeException("Failed to execute command after $retries attempts: $command");
    }

    /**
     * Execute multiple commands efficiently (sequential with logging).
     */
    public function execBatch(array $commands, int $retries = 3, ?callable $delayCallback = null): array
    {
        $results = [];
        foreach ($commands as $cmd) {
            try {
                $results[$cmd] = $this->exec($cmd, $retries, $delayCallback);
            } catch (\Exception $e) {
                $results[$cmd] = "ERROR: {$e->getMessage()}";
            }
        }
        return $results;
    }

    /**
     * Upload large .rsc file safely with verification.
     */
    public function uploadRsc(string $localPath, string $remotePath): bool
    {
        $sftp = $this->ensureSftpConnected();

        $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];

        if (!is_readable($localPath)) {
            Log::error("Local RSC file not readable: $localPath", ['router' => $host]);
            return false;
        }

        $handle = fopen($localPath, 'rb');
        if (!$handle) {
            Log::error("Failed to open local RSC file: $localPath", ['router' => $host]);
            return false;
        }

        $success = $sftp->put($remotePath, $handle, SFTP::SOURCE_LOCAL_FILE);
        fclose($handle);

        if (!$success) {
            Log::error("Failed to upload RSC file $localPath to $remotePath", ['router' => $host]);
            return false;
        }

        Log::info("Uploaded RSC file successfully", ['router' => $host, 'local' => $localPath, 'remote' => $remotePath]);
        return true;
    }

    /**
     * Reconnect SSH session safely.
     */
    private function reconnect(): void
    {
        $this->destroyPassword();
        if (!$this->connect()) {
            $host = explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0];
            throw new \RuntimeException("Failed to reconnect to router {$host}");
        }
    }

    /**
     * Destroy decrypted password from memory.
     */
    private function destroyPassword(): void
    {
        if ($this->decryptedPassword) {
            $len = strlen($this->decryptedPassword);
            $this->decryptedPassword = str_repeat("\0", $len);
            $this->decryptedPassword = null;
        }
    }

    /**
     * Detect RouterOS-specific command errors robustly.
     */
    private function isCommandError(string $output): bool
    {
        $outputLower = strtolower($output);
        $errorPatterns = ['failure', 'error', 'invalid', '!trap', '!fatal', 'cannot', 'unknown command'];

        foreach ($errorPatterns as $pattern) {
            if (str_contains($outputLower, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Disconnect SSH and SFTP cleanly.
     */
    public function disconnect(): void
    {
        $this->destroyPassword();

        if ($this->sftp && $this->sftp->isConnected()) {
            $this->sftp->disconnect();
            $this->sftp = null;
        }

        if ($this->connection && $this->connection->isConnected()) {
            $this->connection->disconnect();
        }

        Log::info("SSH session disconnected", ['router' => explode('/', $this->router->vpn_ip ?? $this->router->ip_address ?? '')[0]]);
    }
}
