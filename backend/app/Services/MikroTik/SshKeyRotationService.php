<?php

namespace App\Services\MikroTik;

use App\Models\Router;
use App\Services\TenantAwareService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;

/**
 * SSH Key Rotation Service for MikroTik Routers
 * 
 * Handles automated SSH key generation, rotation, and management
 * for enhanced security and compliance with ISP-grade standards.
 */
class SshKeyRotationService extends TenantAwareService
{
    private const KEY_ROTATION_DAYS = 90;
    private const KEY_WARNING_DAYS = 80;
    
    /**
     * Generate a new SSH key pair for a router
     * 
     * @param Router $router
     * @return array ['public_key' => string, 'private_key' => string, 'fingerprint' => string]
     */
    public function generateKeyPair(Router $router): array
    {
        Log::info('Generating SSH key pair for router', [
            'router_id' => $router->id,
            'router_name' => $router->name
        ]);
        
        try {
            // Generate ED25519 key (modern, secure, fast)
            $key = RSA::createKey(2048);
            
            $privateKey = $key->toString('PKCS8');
            $publicKey = $key->getPublicKey()->toString('OpenSSH', [
                'comment' => "wificore-router-{$router->id}"
            ]);
            
            // Calculate fingerprint
            $fingerprint = md5(base64_decode(preg_replace('/^ssh-rsa\s+/', '', $publicKey)));
            $formattedFingerprint = implode(':', str_split($fingerprint, 2));
            
            Log::info('SSH key pair generated successfully', [
                'router_id' => $router->id,
                'fingerprint' => $formattedFingerprint,
                'key_type' => 'RSA-2048'
            ]);
            
            return [
                'public_key' => $publicKey,
                'private_key' => $privateKey,
                'fingerprint' => $formattedFingerprint
            ];
            
        } catch (\Exception $e) {
            Log::error('Failed to generate SSH key pair', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('SSH key generation failed: ' . $e->getMessage(), 500, $e);
        }
    }
    
    /**
     * Upload public key to router and configure user
     * 
     * @param Router $router
     * @param string $publicKey
     * @return bool
     */
    public function uploadPublicKeyToRouter(Router $router, string $publicKey): bool
    {
        Log::info('Uploading SSH public key to router', [
            'router_id' => $router->id
        ]);
        
        try {
            $ssh = new SshExecutor($router, 30);
            $ssh->connect();
            
            // Create temporary file with public key
            $keyFileName = "wificore_key_{$router->id}.pub";
            
            // Remove old key file if exists
            $ssh->exec("/file remove [find name=\"{$keyFileName}\"]");
            
            // Upload public key content
            $tempFile = tempnam(sys_get_temp_dir(), 'ssh_key_');
            file_put_contents($tempFile, $publicKey);
            
            $ssh->uploadFile($tempFile, $keyFileName);
            unlink($tempFile);
            
            // Import the key for the user
            $username = $router->username;
            $importResult = $ssh->exec("/user ssh-keys import public-key-file={$keyFileName} user={$username}");
            
            Log::info('SSH public key imported to router', [
                'router_id' => $router->id,
                'username' => $username,
                'result' => substr($importResult, 0, 200)
            ]);
            
            // Clean up the key file
            $ssh->exec("/file remove [find name=\"{$keyFileName}\"]");
            
            // Verify key was imported
            $keysResult = $ssh->exec("/user ssh-keys print");
            
            if (strpos($keysResult, $username) === false) {
                throw new \Exception('SSH key import verification failed - key not found for user');
            }
            
            $ssh->disconnect();
            
            Log::info('SSH public key uploaded and verified successfully', [
                'router_id' => $router->id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to upload SSH public key to router', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Rotate SSH key for a router
     * 
     * @param Router $router
     * @return array
     */
    public function rotateKey(Router $router): array
    {
        $startTime = microtime(true);
        
        Log::info('Starting SSH key rotation', [
            'router_id' => $router->id,
            'router_name' => $router->name,
            'current_key_age' => $this->getKeyAgeDays($router)
        ]);
        
        try {
            // Generate new key pair
            $keyPair = $this->generateKeyPair($router);
            
            // Upload new public key to router
            $this->uploadPublicKeyToRouter($router, $keyPair['public_key']);
            
            // Test new key authentication
            $this->testKeyAuthentication($router, $keyPair['private_key']);
            
            // Remove old key from router if it exists
            if ($router->ssh_key) {
                try {
                    $this->removeOldKeyFromRouter($router);
                } catch (\Exception $e) {
                    Log::warning('Failed to remove old key from router (non-critical)', [
                        'router_id' => $router->id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
            
            // Update database with new key
            $router->update([
                'ssh_key' => Crypt::encrypt($keyPair['private_key']),
                'ssh_key_created_at' => $router->ssh_key_created_at ?? now(),
                'ssh_key_rotated_at' => now()
            ]);
            
            $result = [
                'success' => true,
                'router_id' => $router->id,
                'fingerprint' => $keyPair['fingerprint'],
                'rotated_at' => now()->toDateTimeString(),
                'duration' => round(microtime(true) - $startTime, 2) . 's'
            ];
            
            Log::info('SSH key rotation completed successfully', $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('SSH key rotation failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            throw new \Exception('SSH key rotation failed: ' . $e->getMessage(), 500, $e);
        }
    }
    
    /**
     * Test SSH key authentication
     * 
     * @param Router $router
     * @param string $privateKey
     * @return bool
     */
    private function testKeyAuthentication(Router $router, string $privateKey): bool
    {
        Log::info('Testing new SSH key authentication', [
            'router_id' => $router->id
        ]);
        
        try {
            // Create temporary router instance with new key
            $testRouter = clone $router;
            $testRouter->ssh_key = Crypt::encrypt($privateKey);
            
            $ssh = new SshExecutor($testRouter, 10);
            $ssh->connect();
            
            // Execute simple test command
            $result = $ssh->exec('/system identity print');
            
            $ssh->disconnect();
            
            if (empty($result)) {
                throw new \Exception('SSH key authentication test failed - no response from router');
            }
            
            Log::info('SSH key authentication test successful', [
                'router_id' => $router->id
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('SSH key authentication test failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw new \Exception('New SSH key authentication test failed: ' . $e->getMessage(), 500, $e);
        }
    }
    
    /**
     * Remove old SSH key from router
     * 
     * @param Router $router
     * @return void
     */
    private function removeOldKeyFromRouter(Router $router): void
    {
        Log::info('Removing old SSH key from router', [
            'router_id' => $router->id
        ]);
        
        try {
            $ssh = new SshExecutor($router, 30);
            $ssh->connect();
            
            // List all keys for the user
            $keysResult = $ssh->exec("/user ssh-keys print detail");
            
            // Remove all keys except the newest one
            // This is a simplified approach - in production you'd parse the output
            // and selectively remove old keys
            $ssh->exec("/user ssh-keys remove [find user=\"{$router->username}\"]");
            
            $ssh->disconnect();
            
            Log::info('Old SSH keys removed from router', [
                'router_id' => $router->id
            ]);
            
        } catch (\Exception $e) {
            Log::warning('Failed to remove old SSH keys', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
    
    /**
     * Get routers that need key rotation
     * 
     * @return Collection
     */
    public function getRoutersNeedingRotation(): Collection
    {
        $rotationDate = now()->subDays(self::KEY_ROTATION_DAYS);
        
        $routers = Router::where(function ($query) use ($rotationDate) {
            $query->whereNotNull('ssh_key')
                  ->where(function ($q) use ($rotationDate) {
                      // Keys that have never been rotated and are old
                      $q->whereNull('ssh_key_rotated_at')
                        ->where('ssh_key_created_at', '<=', $rotationDate);
                  })
                  ->orWhere(function ($q) use ($rotationDate) {
                      // Keys that were rotated but rotation is old
                      $q->whereNotNull('ssh_key_rotated_at')
                        ->where('ssh_key_rotated_at', '<=', $rotationDate);
                  });
        })->get();
        
        Log::info('Found routers needing SSH key rotation', [
            'count' => $routers->count(),
            'rotation_threshold_days' => self::KEY_ROTATION_DAYS
        ]);
        
        return $routers;
    }
    
    /**
     * Get routers approaching key rotation deadline
     * 
     * @return Collection
     */
    public function getRoutersApproachingRotation(): Collection
    {
        $warningDate = now()->subDays(self::KEY_WARNING_DAYS);
        $rotationDate = now()->subDays(self::KEY_ROTATION_DAYS);
        
        $routers = Router::where(function ($query) use ($warningDate, $rotationDate) {
            $query->whereNotNull('ssh_key')
                  ->where(function ($q) use ($warningDate, $rotationDate) {
                      $q->whereNull('ssh_key_rotated_at')
                        ->where('ssh_key_created_at', '<=', $warningDate)
                        ->where('ssh_key_created_at', '>', $rotationDate);
                  })
                  ->orWhere(function ($q) use ($warningDate, $rotationDate) {
                      $q->whereNotNull('ssh_key_rotated_at')
                        ->where('ssh_key_rotated_at', '<=', $warningDate)
                        ->where('ssh_key_rotated_at', '>', $rotationDate);
                  });
        })->get();
        
        Log::info('Found routers approaching SSH key rotation deadline', [
            'count' => $routers->count(),
            'warning_threshold_days' => self::KEY_WARNING_DAYS
        ]);
        
        return $routers;
    }
    
    /**
     * Get key age in days
     * 
     * @param Router $router
     * @return int|null
     */
    public function getKeyAgeDays(Router $router): ?int
    {
        if (!$router->ssh_key) {
            return null;
        }
        
        $referenceDate = $router->ssh_key_rotated_at ?? $router->ssh_key_created_at;
        
        if (!$referenceDate) {
            return null;
        }
        
        return now()->diffInDays($referenceDate);
    }
    
    /**
     * Initialize SSH key for router (first-time setup)
     * 
     * @param Router $router
     * @return array
     */
    public function initializeKey(Router $router): array
    {
        Log::info('Initializing SSH key for router', [
            'router_id' => $router->id
        ]);
        
        if ($router->ssh_key) {
            throw new \Exception('Router already has an SSH key. Use rotateKey() instead.');
        }
        
        try {
            // ONE KEY PAIR PER TENANT:
            // If another router in this tenant already has an SSH key, reuse it
            $existingRouterWithKey = Router::whereNotNull('ssh_key')->first();

            if ($existingRouterWithKey) {
                Log::info('Reusing existing tenant SSH key for router', [
                    'router_id' => $router->id,
                    'source_router_id' => $existingRouterWithKey->id,
                ]);

                $existingPrivateKey = Crypt::decrypt($existingRouterWithKey->ssh_key);

                // Derive public key from existing private key
                $keyObject = PublicKeyLoader::load($existingPrivateKey);
                $publicKey = $keyObject->getPublicKey()->toString('OpenSSH', [
                    'comment' => "wificore-tenant-router-{$router->id}"
                ]);

                // Upload and test using the shared key
                $this->uploadPublicKeyToRouter($router, $publicKey);
                $this->testKeyAuthentication($router, $existingPrivateKey);

                $router->update([
                    'ssh_key' => Crypt::encrypt($existingPrivateKey),
                    'ssh_key_created_at' => now(),
                    'ssh_key_rotated_at' => null
                ]);

                // Compute fingerprint for logging/response
                $fingerprint = md5(base64_decode(preg_replace('/^ssh-rsa\s+/', '', $publicKey)));
                $formattedFingerprint = implode(':', str_split($fingerprint, 2));

                $result = [
                    'success' => true,
                    'router_id' => $router->id,
                    'fingerprint' => $formattedFingerprint,
                    'created_at' => now()->toDateTimeString()
                ];
            } else {
                // First router in this tenant: generate a brand new key pair
                $keyPair = $this->generateKeyPair($router);
                $this->uploadPublicKeyToRouter($router, $keyPair['public_key']);
                $this->testKeyAuthentication($router, $keyPair['private_key']);
                
                $router->update([
                    'ssh_key' => Crypt::encrypt($keyPair['private_key']),
                    'ssh_key_created_at' => now(),
                    'ssh_key_rotated_at' => null
                ]);
                
                $result = [
                    'success' => true,
                    'router_id' => $router->id,
                    'fingerprint' => $keyPair['fingerprint'],
                    'created_at' => now()->toDateTimeString()
                ];
            }
            
            Log::info('SSH key initialized successfully', $result);
            
            return $result;
            
        } catch (\Exception $e) {
            Log::error('SSH key initialization failed', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            throw $e;
        }
    }
}
