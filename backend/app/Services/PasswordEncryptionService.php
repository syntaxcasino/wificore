<?php

namespace App\Services;

use App\Models\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * Password Encryption Service
 * Handles secure password encryption/decryption with APP_KEY validation
 * Provides mechanisms to detect and fix encryption key mismatches
 */
class PasswordEncryptionService
{
    /**
     * Safely decrypt router password with fallback mechanisms
     * 
     * @param Router $router
     * @return string|null Decrypted password or null on failure
     */
    public static function safeDecrypt(Router $router): ?string
    {
        try {
            // Attempt normal decryption
            $password = Crypt::decrypt($router->password);
            
            Log::debug('Password decrypted successfully', [
                'router_id' => $router->id,
                'method' => 'primary'
            ]);
            
            return $password;
            
        } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
            Log::error('Password decryption failed - APP_KEY mismatch detected', [
                'router_id' => $router->id,
                'router_name' => $router->name,
                'error' => $e->getMessage(),
                'current_app_key' => substr(config('app.key'), 0, 20) . '...',
            ]);
            
            // Check if this is a known APP_KEY mismatch
            return null;
            
        } catch (\Exception $e) {
            Log::error('Unexpected password decryption error', [
                'router_id' => $router->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return null;
        }
    }
    
    /**
     * Encrypt password with current APP_KEY
     * 
     * @param string $plainPassword
     * @return string Encrypted password
     */
    public static function encrypt(string $plainPassword): string
    {
        try {
            return Crypt::encrypt($plainPassword);
        } catch (\Exception $e) {
            Log::error('Password encryption failed', [
                'error' => $e->getMessage()
            ]);
            throw new \Exception('Failed to encrypt password: ' . $e->getMessage());
        }
    }
    
    /**
     * Validate if a password can be decrypted
     * 
     * @param string $encryptedPassword
     * @return bool
     */
    public static function canDecrypt(string $encryptedPassword): bool
    {
        try {
            Crypt::decrypt($encryptedPassword);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Re-encrypt router password with current APP_KEY
     * Use this when APP_KEY has changed
     * 
     * @param Router $router
     * @param string $plainPassword The known plain text password
     * @return bool Success status
     */
    public static function reEncryptPassword(Router $router, string $plainPassword): bool
    {
        try {
            $router->password = self::encrypt($plainPassword);
            $router->save();
            
            Log::info('Router password re-encrypted successfully', [
                'router_id' => $router->id,
                'router_name' => $router->name
            ]);
            
            return true;
            
        } catch (\Exception $e) {
            Log::error('Failed to re-encrypt router password', [
                'router_id' => $router->id,
                'error' => $e->getMessage()
            ]);
            
            return false;
        }
    }
    
    /**
     * Validate all routers can decrypt their passwords
     * Returns list of routers with decryption issues
     * 
     * @param string|null $tenantId Optional tenant ID for tenant-specific check
     * @return array Array of router IDs with decryption issues
     */
    public static function validateAllPasswords(?string $tenantId = null): array
    {
        $failedRouters = [];
        
        try {
            $query = Router::select('id', 'name', 'password');
            
            if ($tenantId) {
                // Query will be scoped by tenant context
                Log::info('Validating passwords for tenant', ['tenant_id' => $tenantId]);
            }
            
            $routers = $query->get();
            
            foreach ($routers as $router) {
                if (!self::canDecrypt($router->password)) {
                    $failedRouters[] = [
                        'id' => $router->id,
                        'name' => $router->name,
                    ];
                    
                    Log::warning('Router password cannot be decrypted', [
                        'router_id' => $router->id,
                        'router_name' => $router->name
                    ]);
                }
            }
            
            if (empty($failedRouters)) {
                Log::info('All router passwords validated successfully', [
                    'total_routers' => $routers->count(),
                    'tenant_id' => $tenantId
                ]);
            } else {
                Log::warning('Some router passwords failed validation', [
                    'failed_count' => count($failedRouters),
                    'total_routers' => $routers->count(),
                    'tenant_id' => $tenantId
                ]);
            }
            
        } catch (\Exception $e) {
            Log::error('Failed to validate router passwords', [
                'error' => $e->getMessage(),
                'tenant_id' => $tenantId
            ]);
        }
        
        return $failedRouters;
    }
    
    /**
     * Get APP_KEY information for debugging
     * 
     * @return array
     */
    public static function getAppKeyInfo(): array
    {
        $appKey = config('app.key');
        
        return [
            'exists' => !empty($appKey),
            'format' => str_starts_with($appKey, 'base64:') ? 'base64' : 'plain',
            'length' => strlen($appKey),
            'prefix' => substr($appKey, 0, 20) . '...',
            'cipher' => config('app.cipher', 'AES-256-CBC'),
        ];
    }
    
    /**
     * Check if APP_KEY is properly configured
     * 
     * @return array Status and any issues
     */
    public static function validateAppKey(): array
    {
        $appKey = config('app.key');
        $issues = [];
        
        if (empty($appKey)) {
            $issues[] = 'APP_KEY is not set in .env file';
        }
        
        if (!str_starts_with($appKey, 'base64:')) {
            $issues[] = 'APP_KEY should start with "base64:" prefix';
        }
        
        if (strlen($appKey) < 30) {
            $issues[] = 'APP_KEY appears to be too short';
        }
        
        // Try to encrypt/decrypt test data
        try {
            $testData = 'test_encryption_' . time();
            $encrypted = Crypt::encrypt($testData);
            $decrypted = Crypt::decrypt($encrypted);
            
            if ($testData !== $decrypted) {
                $issues[] = 'Encryption/decryption test failed - data mismatch';
            }
        } catch (\Exception $e) {
            $issues[] = 'Encryption/decryption test failed: ' . $e->getMessage();
        }
        
        return [
            'valid' => empty($issues),
            'issues' => $issues,
            'info' => self::getAppKeyInfo()
        ];
    }
}
