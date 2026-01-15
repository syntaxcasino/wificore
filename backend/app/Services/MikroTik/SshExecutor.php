<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\PasswordEncryptionService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use phpseclib3\Net\SSH2;
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
    private ?string $decryptedPassword = null;
    private ?string $sshKey = null;
    private Router $router;
    private string $host;
    private int $timeout;
    
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
        
        // Decrypt credentials ONCE at initialization using safe decryption
        if (!empty($router->ssh_key)) {
            try {
                $this->sshKey = Crypt::decrypt($router->ssh_key);
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
                throw new \Exception('Failed to decrypt router SSH key. This may indicate an APP_KEY mismatch between environments.', 0, $e);
            }
        } else {
            // Use safe password decryption with better error handling
            $this->decryptedPassword = PasswordEncryptionService::safeDecrypt($router);
            
            if ($this->decryptedPassword === null) {
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
            
            Log::debug('SSH Executor: Using password authentication', [
                'router_id' => $router->id,
                'method' => 'password'
            ]);
        }
        
        // Determine host (prefer VPN IP)
        $ip = $router->vpn_ip ?? $router->ip_address;
        $this->host = explode('/', $ip)[0];
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
        
        try {
            $this->connection = new SSH2($this->host, 22, $this->timeout);
            
            // Try SSH key first (preferred method)
            if ($this->sshKey !== null) {
                $key = PublicKeyLoader::load($this->sshKey);
                
                if (!$this->connection->login($this->router->username, $key)) {
                    throw new \Exception('SSH key authentication failed');
                }
                
                Log::info('SSH Executor: Connected via SSH key', [
                    'router_id' => $this->router->id,
                    'host' => $this->host,
                    'duration' => round(microtime(true) - $startTime, 3) . 's',
                    'method' => 'ssh_key'
                ]);
                
            } else {
                // Fallback to password
                if (!$this->connection->login($this->router->username, $this->decryptedPassword)) {
                    throw new \Exception('SSH password authentication failed');
                }
                
                Log::info('SSH Executor: Connected via password', [
                    'router_id' => $this->router->id,
                    'host' => $this->host,
                    'duration' => round(microtime(true) - $startTime, 3) . 's',
                    'method' => 'password'
                ]);
            }
            
        } catch (\Exception $e) {
            $this->connection = null;
            
            Log::error('SSH Executor: Connection failed', [
                'router_id' => $this->router->id,
                'host' => $this->host,
                'error' => $e->getMessage(),
                'duration' => round(microtime(true) - $startTime, 3) . 's'
            ]);
            
            throw new \Exception('SSH connection failed: ' . $e->getMessage(), 503, $e);
        }
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
            
            Log::debug('SSH Executor: Command executed', [
                'router_id' => $this->router->id,
                'command_preview' => substr($command, 0, 100),
                'result_length' => strlen($result),
                'duration' => round(microtime(true) - $startTime, 3) . 's'
            ]);
            
            return $result;
            
        } catch (\Exception $e) {
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
                $results[$index] = $this->connection->exec($command);
            } catch (\Exception $e) {
                Log::warning('SSH Executor: Batch command failed', [
                    'router_id' => $this->router->id,
                    'command_index' => $index,
                    'command_preview' => substr($command, 0, 100),
                    'error' => $e->getMessage()
                ]);
                
                $results[$index] = null;
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
                    $this->disconnect();
                    sleep(1); // Brief pause before reconnecting
                }
                
                $this->connect();
                
                // Execute all commands
                $results = $this->execBatch($commands);
                
                // Check for failed commands
                $failedCount = count(array_filter($results, fn($r) => $r === null));
                
                if ($failedCount > 0) {
                    throw new \Exception("$failedCount commands failed during execution");
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
                    $this->disconnect();
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
            $fileSize = strlen($content);
            
            Log::info('SSH Executor: Uploading file', [
                'router_id' => $this->router->id,
                'remote_path' => $remotePath,
                'size' => $fileSize
            ]);
            
            // Remove existing file
            $this->connection->exec("/file remove [find name=\"{$remotePath}\"]");
            
            // Upload content line by line to avoid command length limits
            $lines = explode("\n", $content);
            $totalLines = count($lines);
            
            foreach ($lines as $index => $line) {
                $isFirst = ($index === 0);
                
                // Escape special characters
                $escapedLine = addslashes($line);
                
                if ($isFirst) {
                    $this->connection->exec("/file print file=\"{$remotePath}\" from=[/terminal style set \"{$escapedLine}\"]");
                } else {
                    $this->connection->exec("/file set {$remotePath} contents=\"\$contents{$escapedLine}\\n\"");
                }
                
                // Log progress every 100 lines
                if ($index % 100 === 0 && $index > 0) {
                    Log::debug('SSH Executor: Upload progress', [
                        'router_id' => $this->router->id,
                        'progress' => round(($index / $totalLines) * 100, 1) . '%'
                    ]);
                }
            }
            
            Log::info('SSH Executor: File uploaded successfully', [
                'router_id' => $this->router->id,
                'remote_path' => $remotePath,
                'size' => $fileSize,
                'lines' => $totalLines,
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
    public function disconnect(): void
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
        
        // Destroy credentials from memory
        if ($this->decryptedPassword !== null) {
            $this->decryptedPassword = str_repeat("\0", strlen($this->decryptedPassword));
            $this->decryptedPassword = null;
        }
        
        if ($this->sshKey !== null) {
            $this->sshKey = str_repeat("\0", strlen($this->sshKey));
            $this->sshKey = null;
        }
    }
    
    /**
     * Ensure connection is established
     */
    private function ensureConnected(): void
    {
        if ($this->connection === null) {
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
    
    /**
     * Destructor - ensure cleanup
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}
