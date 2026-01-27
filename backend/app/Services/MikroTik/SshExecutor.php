<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\PasswordEncryptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
use phpseclib3\Net\SFTP;
use phpseclib3\Crypt\PublicKeyLoader;

/**
 * Dedicated SSH Executor for MikroTik Routers
 * 
 * Security Features:
 * - Decrypt credentials ONCE per session
 * - Support SSH keys (primary) and passwords (fallback)
 * - Single connection for multiple commands
 * - Automatic cleanup and session management
 * - Credential rotation support
 */
class SshExecutor
{
    private ?SSH2 $connection = null;
    private ?SFTP $sftp = null;
    private ?string $decryptedPassword = null;
    private ?string $sshKey = null;
    private ?string $sshKeyPassphrase = null;
    private Router $router;
    private string $host;
    private int $port;
    private int $timeout;
    private ?string $lastRouterOsError = null;

    private function isRouterOsErrorOutput(string $output): bool
    {
        $trimmed = trim($output);

        if ($trimmed === '') {
            return false;
        }

        return (bool) preg_match(
            '/(^|\n)\s*(failure:|expected end of command|input does not match any value|bad command name|syntax error)\b/i',
            $trimmed
        );
    }

    private function formatRouterOsErrorMessage(string $output): string
    {
        $trimmed = trim($output);
        $trimmed = preg_replace('/\s+/', ' ', $trimmed);
        $trimmed = substr((string) $trimmed, 0, 240);
        return $trimmed;
    }

    private function buildFailedCommandSummary(array $commands, array $results): array
    {
        $failed = [];

        foreach ($results as $index => $result) {
            if ($result !== null) {
                continue;
            }

            $command = $commands[$index] ?? '';
            $failed[] = [
                'index' => $index,
                'command_preview' => substr((string) $command, 0, 200),
            ];
        }

        return $failed;
    }
    
    /**
     * Initialize executor with router
     * Credentials are decrypted ONCE here
     */
    public function __construct(Router $router, int $timeout = 30)
    {
        $this->router = $router;
        $this->timeout = $timeout;

        // Ensure timeout is not too long
        if ($this->timeout > 30) {
            $this->timeout = 30;
        }

        $ip = $router->vpn_ip ?? $router->ip_address;
        $this->host = explode('/', $ip)[0];

        $portCandidate = (int) ($router->port ?? 0);
        if ($portCandidate <= 0) {
            $portCandidate = 22;
        }

        if (in_array($portCandidate, [8728, 8729, 8720], true)) {
            Log::debug('SSH Executor: Router port appears to be RouterOS API port, defaulting SSH to 22', [
                'router_id' => $router->id,
                'host' => $this->host,
                'router_port' => $portCandidate,
            ]);
            $portCandidate = 22;
        }

        $this->port = $portCandidate;

        if (!$this->isReachable($this->host)) {
            Log::warning('SSH Executor: Host not reachable (ping + tcp probe failed)', [
                'router_id' => $router->id,
                'host' => $this->host,
            ]);
        }
        
        // Decrypt credentials ONCE at initialization using safe decryption
        if (!empty($router->ssh_key)) {
            try {
                $this->sshKey = Crypt::decrypt($router->ssh_key);
                $this->sshKeyPassphrase = null;
                Log::debug('SSH Executor: Using SSH key authentication', [
                    'router_id' => $router->id,
                    'method' => 'ssh_key'
                ]);
            } catch (\Exception $e) {
                Log::error('SSH Executor: Failed to decrypt SSH key', [
                    'router_id' => $router->id,
                    'error' => $e->getMessage(),
                    'hint' => 'Check if APP_KEY in .env matches the key used when router was created'
                ]);
            }
        }

        if (empty($this->sshKey)) {
            $globalKeyPath = (string) env('MIKROTIK_SSH_PRIVATE_KEY_PATH', '');
            if ($globalKeyPath !== '' && is_file($globalKeyPath)) {
                $keyContents = @file_get_contents($globalKeyPath);
                if (is_string($keyContents) && trim($keyContents) !== '') {
                    $this->sshKey = $keyContents;
                    $this->sshKeyPassphrase = (string) env('MIKROTIK_SSH_PRIVATE_KEY_PASSPHRASE', '');
                    if ($this->sshKeyPassphrase === '') {
                        $this->sshKeyPassphrase = null;
                    }
                    Log::debug('SSH Executor: Using global SSH key authentication', [
                        'router_id' => $router->id,
                        'method' => 'ssh_key_global'
                    ]);
                }
            }
        }

        // Always attempt to decrypt password so password fallback remains available
        // even when SSH key auth is configured (key first, password fallback).
        $this->decryptedPassword = PasswordEncryptionService::safeDecrypt($router);
        if ($this->decryptedPassword === null) {
            if (empty($this->sshKey)) {
                Log::error('SSH Executor: Password decryption failed', [
                    'router_id' => $router->id,
                    'router_name' => $router->name,
                    'hint' => 'Run: php artisan router:validate-passwords to diagnose and fix'
                ]);
                throw new \Exception(
                    'Failed to decrypt router password. This indicates an APP_KEY mismatch. ' .
                    'Run "php artisan router:validate-passwords" to diagnose and fix the issue.'
                );
            }
        } else {
            Log::debug('SSH Executor: Password authentication available as fallback', [
                'router_id' => $router->id,
                'method' => 'password_fallback_available'
            ]);
        }
    }

    private function isReachable(string $host): bool
    {
        $pingResult = $this->pingHost($host);
        if ($pingResult !== null && $pingResult === true) {
            return true;
        }

        return $this->tcpProbeHost($host, $this->port, 3);
    }

    private function pingHost(string $host): ?bool
    {
        $count = 1;
        $timeout = 1;

        $command = sprintf(
            'ping -c %d -W %d %s 2>&1',
            $count,
            $timeout,
            escapeshellarg($host)
        );

        $output = [];
        $returnCode = 0;
        exec($command, $output, $returnCode);

        if ($returnCode === 0) {
            return true;
        }

        $outputString = implode("\n", $output);
        if ($returnCode === 127 || str_contains($outputString, 'not found')) {
            Log::warning('SSH Executor: ping command not available, skipping ping pre-check', [
                'router_id' => $this->router->id,
                'host' => $host,
                'return_code' => $returnCode,
            ]);
            return null;
        }

        Log::debug('SSH Executor: ping pre-check failed', [
            'router_id' => $this->router->id,
            'host' => $host,
            'return_code' => $returnCode,
            'output_preview' => substr($outputString, 0, 200),
        ]);

        return false;
    }

    private function tcpProbeHost(string $host, int $port, int $timeoutSeconds): bool
    {
        $errno = 0;
        $errstr = '';

        $startTime = microtime(true);
        $fp = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);

        if (is_resource($fp)) {
            fclose($fp);

            Log::debug('SSH Executor: TCP probe succeeded', [
                'router_id' => $this->router->id,
                'host' => $host,
                'port' => $port,
                'duration' => round(microtime(true) - $startTime, 3) . 's',
            ]);

            return true;
        }

        Log::debug('SSH Executor: TCP probe failed', [
            'router_id' => $this->router->id,
            'host' => $host,
            'port' => $port,
            'errno' => $errno,
            'error' => $errstr,
            'duration' => round(microtime(true) - $startTime, 3) . 's',
        ]);

        return false;
    }
    
    /**
     * Connect to router via SSH
     * Uses SSH key if available, falls back to password
     */
    public function connect(): void
    {
        if ($this->connection !== null) {
            Log::debug('SSH Executor: Already connected', ['router_id' => $this->router->id]);
            return;
        }
        
        $startTime = microtime(true);
        $maxAttempts = (int) env('MIKROTIK_SSH_CONNECT_RETRIES', 3);
        if ($maxAttempts < 1) {
            $maxAttempts = 1;
        }
        $baseDelay = (int) env('MIKROTIK_SSH_CONNECT_RETRY_DELAY', 1);
        if ($baseDelay < 0) {
            $baseDelay = 0;
        }

        $lastException = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->connection = new SSH2($this->host, $this->port, $this->timeout);

                // Try SSH key first (preferred method)
                if ($this->sshKey !== null) {
                    $key = PublicKeyLoader::load($this->sshKey, $this->sshKeyPassphrase);

                    if (!$this->connection->login($this->router->username, $key)) {
                        if ($this->decryptedPassword === null) {
                            throw new \Exception('SSH key authentication failed');
                        }

                        Log::warning('SSH Executor: SSH key authentication failed, falling back to password', [
                            'router_id' => $this->router->id,
                            'host' => $this->host,
                            'port' => $this->port,
                        ]);

                        $password = $this->resolvePasswordForLogin();
                        if (!$this->connection->login($this->router->username, $password)) {
                            throw new \Exception('SSH password authentication failed');
                        }

                        Log::info('SSH Executor: Connected via password (key fallback)', [
                            'router_id' => $this->router->id,
                            'host' => $this->host,
                            'port' => $this->port,
                            'duration' => round(microtime(true) - $startTime, 3) . 's',
                            'method' => 'password_fallback'
                        ]);

                        $this->tryAutoBootstrapPublicKey();

                        return;
                    }

                    Log::info('SSH Executor: Connected via SSH key', [
                        'router_id' => $this->router->id,
                        'host' => $this->host,
                        'port' => $this->port,
                        'duration' => round(microtime(true) - $startTime, 3) . 's',
                        'method' => 'ssh_key'
                    ]);
                } else {
                    // Fallback to password
                    $password = $this->resolvePasswordForLogin();
                    if (!$this->connection->login($this->router->username, $password)) {
                        throw new \Exception('SSH password authentication failed');
                    }

                    Log::info('SSH Executor: Connected via password', [
                        'router_id' => $this->router->id,
                        'host' => $this->host,
                        'port' => $this->port,
                        'duration' => round(microtime(true) - $startTime, 3) . 's',
                        'method' => 'password'
                    ]);

                    $this->tryAutoBootstrapPublicKey();
                }

                return;
            } catch (\Exception $e) {
                $lastException = $e;
                $this->connection = null;

                Log::warning('SSH Executor: Connection attempt failed', [
                    'router_id' => $this->router->id,
                    'host' => $this->host,
                    'port' => $this->port,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    $delay = $baseDelay * $attempt;
                    if ($delay > 0) {
                        sleep($delay);
                    }
                }
            }
        }

        $error = $lastException ? $lastException->getMessage() : 'Unknown error';
        Log::error('SSH Executor: Connection failed', [
            'router_id' => $this->router->id,
            'host' => $this->host,
            'port' => $this->port,
            'error' => $error,
            'duration' => round(microtime(true) - $startTime, 3) . 's'
        ]);

        throw new \Exception('SSH connection failed: ' . $error, 503, $lastException);
    }

    private function resolvePasswordForLogin(): string
    {
        if ($this->decryptedPassword === null) {
            $this->decryptedPassword = PasswordEncryptionService::safeDecrypt($this->router);
        }

        if ($this->decryptedPassword === null) {
            throw new \Exception('SSH password not available (decryption failed)');
        }

        return $this->decryptedPassword;
    }
    
    /**
     * Execute a single command
     */
    public function exec(string $command): string
    {
        $this->ensureConnected();
        
        $startTime = microtime(true);
        
        try {
            $result = $this->connection->exec($command);

            if ($this->isRouterOsErrorOutput($result)) {
                $this->lastRouterOsError = trim($result);
                Log::warning('SSH Executor: RouterOS command returned error output', [
                    'router_id' => $this->router->id,
                    'command_preview' => substr($command, 0, 200),
                    'output_preview' => substr(trim($result), 0, 200),
                ]);
                throw new \Exception('RouterOS error: ' . $this->formatRouterOsErrorMessage($result));
            }
            
            Log::debug('SSH Executor: Command executed', [
                'router_id' => $this->router->id,
                'command_preview' => substr($command, 0, 100),
                'result_length' => strlen($result),
                'duration' => round(microtime(true) - $startTime, 3) . 's'
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
            $message = $e->getMessage();
            $shouldRetry = $this->connection === null
                || !$this->connection->isConnected()
                || str_contains($message, 'Please close the channel')
                || str_contains($message, 'Error reading SSH identification string');
            if ($shouldRetry) {
                try {
                    $this->disconnect(false);
                } catch (\Exception $disconnectError) {
                }

                $this->ensureConnected();

                $result = $this->connection->exec($command);

                if ($this->isRouterOsErrorOutput($result)) {
                    $this->lastRouterOsError = trim($result);
                    Log::warning('SSH Executor: RouterOS command returned error output', [
                        'router_id' => $this->router->id,
                        'command_preview' => substr($command, 0, 200),
                        'output_preview' => substr(trim($result), 0, 200),
                    ]);
                    throw new \Exception('RouterOS error: ' . $this->formatRouterOsErrorMessage($result));
                }

                Log::debug('SSH Executor: Command executed after reconnect', [
                    'router_id' => $this->router->id,
                    'command_preview' => substr($command, 0, 100),
                    'result_length' => strlen($result),
                    'duration' => round(microtime(true) - $startTime, 3) . 's'
                ]);

                return $result;
            }

            Log::error('SSH Executor: Command execution failed', [
                'router_id' => $this->router->id,
                'command_preview' => substr($command, 0, 100),
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Execute multiple commands in batch (single session)
     * This is MUCH faster than individual exec() calls
     */
    public function execBatch(array $commands): array
    {
        $this->ensureConnected();
        
        $startTime = microtime(true);
        $results = [];
        
        Log::info('SSH Executor: Executing batch commands', [
            'router_id' => $this->router->id,
            'command_count' => count($commands)
        ]);
        
        foreach ($commands as $index => $command) {
            try {
                $result = $this->connection->exec($command);

                if ($this->isRouterOsErrorOutput($result)) {
                    $this->lastRouterOsError = trim($result);
                    Log::warning('SSH Executor: Batch command returned RouterOS error output', [
                        'router_id' => $this->router->id,
                        'command_index' => $index,
                        'command_preview' => substr($command, 0, 200),
                        'output_preview' => substr(trim($result), 0, 200),
                    ]);
                    $results[$index] = null;
                    continue;
                }

                $results[$index] = $result;
            } catch (\Exception $e) {
                $message = $e->getMessage();
                $shouldReconnect = $this->connection === null
                    || !$this->connection->isConnected()
                    || str_contains($message, 'Please close the channel')
                    || str_contains($message, 'Error reading SSH identification string');

                if ($shouldReconnect) {
                    Log::warning('SSH Executor: Batch command failed, reconnecting and retrying once', [
                        'router_id' => $this->router->id,
                        'command_index' => $index,
                        'command_preview' => substr($command, 0, 100),
                        'error' => $message,
                    ]);

                    try {
                        $this->disconnect(false);
                    } catch (\Throwable $disconnectError) {
                    }

                    try {
                        $this->ensureConnected();
                        $retryResult = $this->connection->exec($command);

                        if ($this->isRouterOsErrorOutput($retryResult)) {
                            $this->lastRouterOsError = trim($retryResult);
                            Log::warning('SSH Executor: Batch retry returned RouterOS error output', [
                                'router_id' => $this->router->id,
                                'command_index' => $index,
                                'command_preview' => substr($command, 0, 200),
                                'output_preview' => substr(trim($retryResult), 0, 200),
                            ]);
                            $results[$index] = null;
                            continue;
                        }

                        $results[$index] = $retryResult;
                        continue;
                    } catch (\Throwable $retryError) {
                        Log::warning('SSH Executor: Batch command retry failed; aborting remaining batch', [
                            'router_id' => $this->router->id,
                            'command_index' => $index,
                            'command_preview' => substr($command, 0, 100),
                            'error' => $retryError->getMessage(),
                        ]);
                    }
                } else {
                    Log::warning('SSH Executor: Batch command failed', [
                        'router_id' => $this->router->id,
                        'command_index' => $index,
                        'command_preview' => substr($command, 0, 100),
                        'error' => $message,
                    ]);
                }

                $results[$index] = null;

                for ($remaining = $index + 1; $remaining < count($commands); $remaining++) {
                    $results[$remaining] = null;
                }
                break;
            }
        }
        
        Log::info('SSH Executor: Batch execution complete', [
            'router_id' => $this->router->id,
            'command_count' => count($commands),
            'successful' => count(array_filter($results, fn($r) => $r !== null)),
            'duration' => round(microtime(true) - $startTime, 3) . 's'
        ]);
        
        return $results;
    }
    
    /**
     * Execute batch commands with automatic retry and exponential backoff
     * Designed for service configuration scripts (Hotspot, PPPoE, Hybrid)
     * 
     * @param array $commands Array of RouterOS commands to execute
     * @param int $maxRetries Maximum number of retry attempts (default: 3)
     * @param int $baseDelay Base delay in seconds for exponential backoff (default: 2)
     * @param callable|null $validator Optional validation callback to verify execution success
     * @return array Execution result with success status and details
     */
    public function execBatchWithRetry(
        array $commands, 
        int $maxRetries = 3, 
        int $baseDelay = 2,
        ?callable $validator = null
    ): array {
        $attempt = 0;
        $lastError = null;
        
        while ($attempt < $maxRetries) {
            $attempt++;
            
            try {
                Log::info('SSH Executor: Service script execution attempt', [
                    'router_id' => $this->router->id,
                    'attempt' => $attempt,
                    'max_retries' => $maxRetries,
                    'command_count' => count($commands),
                ]);
                
                // Ensure fresh connection for each attempt
                if ($attempt > 1) {
                    $this->disconnect(false);
                    sleep(1); // Brief pause before reconnecting
                }
                
                $this->connect();
                
                // Execute all commands
                $results = $this->execBatch($commands);
                
                // Check for failed commands
                $failedCount = count(array_filter($results, fn($r) => $r === null));
                
                if ($failedCount > 0) {
                    $failedSummary = $this->buildFailedCommandSummary($commands, $results);

                    Log::error('SSH Executor: Service script command failures', [
                        'router_id' => $this->router->id,
                        'attempt' => $attempt,
                        'failed_count' => $failedCount,
                        'failed_commands' => array_slice($failedSummary, 0, 10),
                    ]);

                    $firstFailure = $failedSummary[0] ?? null;
                    $firstFailureText = '';
                    if (is_array($firstFailure)) {
                        $firstFailureText = ' First failing command #' . ($firstFailure['index'] ?? 'unknown') . ': ' . ($firstFailure['command_preview'] ?? '');
                    }

                    throw new \Exception("$failedCount commands failed during execution." . $firstFailureText);
                }
                
                // Run custom validator if provided
                if ($validator !== null) {
                    Log::debug('SSH Executor: Running validation callback', [
                        'router_id' => $this->router->id,
                        'attempt' => $attempt,
                    ]);
                    
                    $validationResult = $validator($this);
                    
                    if (!$validationResult['valid']) {
                        throw new \Exception('Validation failed: ' . ($validationResult['error'] ?? 'Unknown validation error'));
                    }
                    
                    Log::info('SSH Executor: Validation passed', [
                        'router_id' => $this->router->id,
                        'attempt' => $attempt,
                    ]);
                }
                
                // Success!
                Log::info('SSH Executor: Service script executed successfully', [
                    'router_id' => $this->router->id,
                    'attempt' => $attempt,
                    'total_attempts' => $attempt,
                    'command_count' => count($commands),
                ]);
                
                return [
                    'success' => true,
                    'attempt' => $attempt,
                    'results' => $results,
                    'message' => "Service script executed successfully on attempt $attempt",
                ];
                
            } catch (\Exception $e) {
                $lastError = $e;
                
                Log::warning('SSH Executor: Service script execution failed', [
                    'router_id' => $this->router->id,
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                    'will_retry' => $attempt < $maxRetries,
                ]);
                
                // Cleanup connection
                try {
                    $this->disconnect(false);
                } catch (\Exception $disconnectError) {
                    Log::debug('SSH Executor: Disconnect error during retry cleanup', [
                        'error' => $disconnectError->getMessage(),
                    ]);
                }
                
                // If this was the last attempt, break and return failure
                if ($attempt >= $maxRetries) {
                    break;
                }
                
                // Exponential backoff: 2s, 4s, 8s, etc.
                $delay = $baseDelay * pow(2, $attempt - 1);
                
                Log::info('SSH Executor: Waiting before retry', [
                    'router_id' => $this->router->id,
                    'delay_seconds' => $delay,
                    'next_attempt' => $attempt + 1,
                ]);
                
                sleep($delay);
            }
        }
        
        // All retries exhausted
        $errorMessage = $lastError ? $lastError->getMessage() : 'Unknown error';
        
        Log::error('SSH Executor: Service script execution failed after all retries', [
            'router_id' => $this->router->id,
            'total_attempts' => $attempt,
            'last_error' => $errorMessage,
        ]);
        
        return [
            'success' => false,
            'attempt' => $attempt,
            'error' => $errorMessage,
            'message' => "Service script execution failed after $attempt attempts: $errorMessage",
        ];
    }
    
    /**
     * Upload a file to the router
     * Used for .rsc configuration files
     */
    public function uploadFile(string $localPath, string $remotePath): bool
    {
        $this->ensureConnected();

        $startTime = microtime(true);

        try {
            if (!file_exists($localPath)) {
                throw new \Exception("Local file not found: {$localPath}");
            }

            $content = file_get_contents($localPath);
            if (!is_string($content)) {
                throw new \Exception("Failed to read local file: {$localPath}");
            }

            $fileSize = strlen($content);

            Log::info('SSH Executor: Uploading file via SFTP', [
                'router_id' => $this->router->id,
                'remote_path' => $remotePath,
                'size' => $fileSize
            ]);

            $this->ensureSftpConnected();

            // Best-effort cleanup of old file (ignore failures)
            try {
                $this->exec("/file remove [find name=\"{$remotePath}\"]");
            } catch (\Throwable $e) {
            }

            if (!$this->sftp->put($remotePath, $content)) {
                throw new \Exception('SFTP put failed');
            }

            Log::info('SSH Executor: File uploaded successfully via SFTP', [
                'router_id' => $this->router->id,
                'remote_path' => $remotePath,
                'size' => $fileSize,
                'duration' => round(microtime(true) - $startTime, 3) . 's'
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('SSH Executor: File upload failed', [
                'router_id' => $this->router->id,
                'remote_path' => $remotePath,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    private function ensureSftpConnected(): void
    {
        if ($this->sftp !== null && $this->sftp->isConnected()) {
            return;
        }

        $this->sftp = new SFTP($this->host, $this->port, $this->timeout);

        if ($this->sshKey !== null) {
            $key = PublicKeyLoader::load($this->sshKey, $this->sshKeyPassphrase);
            if (!$this->sftp->login($this->router->username, $key)) {
                if ($this->decryptedPassword === null) {
                    throw new \Exception('SFTP login failed (SSH key)');
                }

                $password = $this->resolvePasswordForLogin();
                if (!$this->sftp->login($this->router->username, $password)) {
                    throw new \Exception('SFTP login failed (password fallback)');
                }
            }

            return;
        }

        $password = $this->resolvePasswordForLogin();
        if (!$this->sftp->login($this->router->username, $password)) {
            throw new \Exception('SFTP login failed (password)');
        }
    }
    
    /**
     * Import and execute a .rsc file on the router
     */
    public function importFile(string $remotePath): string
    {
        $this->ensureConnected();
        
        Log::info('SSH Executor: Importing file', [
            'router_id' => $this->router->id,
            'remote_path' => $remotePath
        ]);
        
        $result = $this->connection->exec("/import file-name=\"{$remotePath}\"");
        
        Log::info('SSH Executor: File imported', [
            'router_id' => $this->router->id,
            'remote_path' => $remotePath,
            'result_preview' => substr($result, 0, 200)
        ]);
        
        return $result;
    }
    
    /**
     * Delete a file from the router
     */
    public function deleteFile(string $remotePath): void
    {
        $this->ensureConnected();
        
        $this->connection->exec("/file remove [find name=\"{$remotePath}\"]");
        
        Log::debug('SSH Executor: File deleted', [
            'router_id' => $this->router->id,
            'remote_path' => $remotePath
        ]);
    }
    
    /**
     * Disconnect and cleanup
     * Destroys decrypted credentials from memory
     */
    public function disconnect(bool $destroyCredentials = true): void
    {
        if ($this->connection !== null) {
            try {
                $this->connection->disconnect();
                Log::debug('SSH Executor: Disconnected', ['router_id' => $this->router->id]);
            } catch (\Exception $e) {
                Log::warning('SSH Executor: Disconnect error', [
                    'router_id' => $this->router->id,
                    'error' => $e->getMessage()
                ]);
            }
            
            $this->connection = null;
        }

        if ($this->sftp !== null) {
            try {
                $this->sftp->disconnect();
            } catch (\Throwable $e) {
            }
            $this->sftp = null;
        }

        if (!$destroyCredentials) {
            return;
        }
        
        // Destroy credentials from memory
        if ($this->decryptedPassword !== null) {
            $this->decryptedPassword = str_repeat("\0", strlen($this->decryptedPassword));
            $this->decryptedPassword = null;
        }
        
        if ($this->sshKey !== null) {
            $this->sshKey = str_repeat("\0", strlen($this->sshKey));
            $this->sshKey = null;
        }

        if ($this->sshKeyPassphrase !== null) {
            $this->sshKeyPassphrase = str_repeat("\0", strlen($this->sshKeyPassphrase));
            $this->sshKeyPassphrase = null;
        }
    }
    
    /**
     * Ensure connection is established
     */
    private function ensureConnected(): void
    {
        if ($this->connection === null || !$this->connection->isConnected()) {
            $this->connect();
        }
    }
    
    /**
     * Get connection status
     */
    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    private function tryAutoBootstrapPublicKey(): void
    {
        $enabled = (string) env('MIKROTIK_SSH_AUTO_BOOTSTRAP', 'false');
        if (!in_array(strtolower($enabled), ['1', 'true', 'yes', 'on'], true)) {
            return;
        }

        $cooldownKey = 'router:ssh_autobootstrap:cooldown:' . $this->router->id;
        if (Cache::has($cooldownKey)) {
            return;
        }

        $lock = Cache::lock('router:ssh_autobootstrap:lock:' . $this->router->id, 20);
        if (!$lock->get()) {
            return;
        }

        try {
            $publicKey = $this->resolveAutoBootstrapPublicKey();
            if ($publicKey === null) {
                return;
            }

            $username = $this->router->username;
            $existingCount = (int) trim($this->exec('/user ssh-keys print count-only where user="' . $username . '"'));
            if ($existingCount > 0) {
                Cache::put($cooldownKey, true, now()->addDays(7));
                return;
            }

            $keyFileName = "wificore_global_key_{$this->router->id}.pub";
            try {
                $this->exec("/file remove [find name=\"{$keyFileName}\"]");
            } catch (\Throwable $e) {
            }

            $tempFile = tempnam(sys_get_temp_dir(), 'mikrotik_key_');
            if (!is_string($tempFile) || $tempFile === '') {
                return;
            }

            file_put_contents($tempFile, $publicKey);
            $this->uploadFile($tempFile, $keyFileName);
            @unlink($tempFile);

            $this->exec("/user ssh-keys import public-key-file=\"{$keyFileName}\" user=\"{$username}\"");
            $this->exec("/file remove [find name=\"{$keyFileName}\"]");
            Cache::put($cooldownKey, true, now()->addDays(7));
        } catch (\Exception $e) {
            Log::warning('SSH Executor: Auto-bootstrap public key failed (non-fatal)', [
                'router_id' => $this->router->id,
                'host' => $this->host,
                'error' => $e->getMessage(),
            ]);

            Cache::put($cooldownKey, true, now()->addMinutes(30));
        } finally {
            try {
                $lock->release();
            } catch (\Throwable $e) {
            }
        }
    }

    private function resolveAutoBootstrapPublicKey(): ?string
    {
        $privateKeyPath = (string) env('MIKROTIK_SSH_PRIVATE_KEY_PATH', '');
        if ($privateKeyPath !== '') {
            $pubPath = $privateKeyPath . '.pub';
            if (is_file($pubPath)) {
                $pub = @file_get_contents($pubPath);
                if (is_string($pub) && trim($pub) !== '') {
                    return rtrim($pub) . "\n";
                }
            }
        }

        if (is_file('/run/secrets/mikrotik_id_rsa.pub')) {
            $pub = @file_get_contents('/run/secrets/mikrotik_id_rsa.pub');
            if (is_string($pub) && trim($pub) !== '') {
                return rtrim($pub) . "\n";
            }
        }

        return null;
    }
    
    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
