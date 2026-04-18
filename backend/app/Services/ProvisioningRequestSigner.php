<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Provisioning Request Signer
 * 
 * Provides HMAC-SHA256 request signing for router provisioning operations.
 * Prevents provisioning request spoofing by validating request authenticity.
 * 
 * Security Features:
 * - HMAC-SHA256 signature verification
 * - Timestamp-based replay protection (5-minute window)
 * - Tenant-specific signing keys
 * - Constant-time signature comparison
 */
class ProvisioningRequestSigner
{
    /**
     * Signature header name
     */
    protected const SIGNATURE_HEADER = 'X-Provisioning-Signature';
    
    /**
     * Timestamp header name
     */
    protected const TIMESTAMP_HEADER = 'X-Provisioning-Timestamp';
    
    /**
     * Allowed clock skew in seconds (5 minutes)
     */
    protected const ALLOWED_CLOCK_SKEW = 300;
    
    /**
     * Get tenant-specific signing key
     * 
     * Each tenant has a unique signing key derived from the master key
     * and their tenant ID. This ensures requests from one tenant cannot
     * be replayed to another tenant.
     * 
     * @param string $tenantId
     * @return string
     */
    public function getSigningKey(string $tenantId): string
    {
        $masterKey = env('PROVISIONING_SIGNING_KEY');
        
        if (empty($masterKey)) {
            Log::error('ProvisioningRequestSigner: PROVISIONING_SIGNING_KEY not set');
            throw new \RuntimeException('Provisioning signing key not configured');
        }
        
        // Derive tenant-specific key using HMAC
        return hash_hmac('sha256', $tenantId, $masterKey);
    }
    
    /**
     * Sign a provisioning request
     * 
     * @param string $tenantId Tenant ID
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $path Request path (e.g., /api/routers/provision)
     * @param array $payload Request body data
     * @param int|null $timestamp Unix timestamp (defaults to now)
     * @return array Signature headers to add to request
     */
    public function signRequest(string $tenantId, string $method, string $path, array $payload = [], ?int $timestamp = null): array
    {
        $timestamp = $timestamp ?? time();
        $key = $this->getSigningKey($tenantId);
        
        // Build canonical request string
        $canonicalRequest = $this->buildCanonicalRequest($method, $path, $payload, $timestamp);
        
        // Generate signature
        $signature = hash_hmac('sha256', $canonicalRequest, $key);
        
        Log::debug('Provisioning request signed', [
            'tenant_id' => $tenantId,
            'method' => $method,
            'path' => $path,
            'timestamp' => $timestamp,
        ]);
        
        return [
            self::SIGNATURE_HEADER => $signature,
            self::TIMESTAMP_HEADER => (string) $timestamp,
        ];
    }
    
    /**
     * Verify a provisioning request signature
     * 
     * @param string $tenantId Tenant ID
     * @param string $method HTTP method
     * @param string $path Request path
     * @param array $payload Request body data
     * @param string $signature Provided signature
     * @param int $timestamp Request timestamp
     * @return bool True if signature is valid
     */
    public function verifyRequest(string $tenantId, string $method, string $path, array $payload, string $signature, int $timestamp): bool
    {
        try {
            // Check timestamp for replay protection
            $now = time();
            $age = abs($now - $timestamp);
            
            if ($age > self::ALLOWED_CLOCK_SKEW) {
                Log::warning('Provisioning request timestamp too old or too far in future', [
                    'tenant_id' => $tenantId,
                    'timestamp' => $timestamp,
                    'now' => $now,
                    'age_seconds' => $age,
                ]);
                return false;
            }
            
            // Get signing key
            $key = $this->getSigningKey($tenantId);
            
            // Build canonical request
            $canonicalRequest = $this->buildCanonicalRequest($method, $path, $payload, $timestamp);
            
            // Compute expected signature
            $expectedSignature = hash_hmac('sha256', $canonicalRequest, $key);
            
            // Constant-time comparison to prevent timing attacks
            return $this->secureCompare($signature, $expectedSignature);
            
        } catch (\Exception $e) {
            Log::error('Provisioning signature verification failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
    
    /**
     * Verify request from HTTP headers
     * 
     * @param \Illuminate\Http\Request $request
     * @param string $tenantId
     * @return bool
     */
    public function verifyFromRequest($request, string $tenantId): bool
    {
        $signature = $request->header(self::SIGNATURE_HEADER);
        $timestamp = (int) $request->header(self::TIMESTAMP_HEADER);
        
        if (empty($signature) || empty($timestamp)) {
            Log::warning('Provisioning request missing signature headers', [
                'tenant_id' => $tenantId,
                'has_signature' => !empty($signature),
                'has_timestamp' => !empty($timestamp),
            ]);
            return false;
        }
        
        return $this->verifyRequest(
            $tenantId,
            $request->method(),
            $request->path(),
            $request->all(),
            $signature,
            $timestamp
        );
    }
    
    /**
     * Build canonical request string for signing
     * 
     * Format: METHOD\nPATH\nPAYLOAD_HASH\nTIMESTAMP
     * 
     * @param string $method
     * @param string $path
     * @param array $payload
     * @param int $timestamp
     * @return string
     */
    protected function buildCanonicalRequest(string $method, string $path, array $payload, int $timestamp): string
    {
        // Normalize method to uppercase
        $method = strtoupper($method);
        
        // Normalize path (remove query string, ensure leading slash)
        $path = parse_url($path, PHP_URL_PATH) ?? '/';
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }
        
        // Hash payload (empty payload has empty hash)
        $payloadJson = empty($payload) ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash('sha256', $payloadJson);
        
        return implode("\n", [
            $method,
            $path,
            $payloadHash,
            $timestamp,
        ]);
    }
    
    /**
     * Constant-time string comparison
     * 
     * Prevents timing attacks by ensuring comparison takes constant time
     * regardless of where strings differ.
     * 
     * @param string $a
     * @param string $b
     * @return bool
     */
    protected function secureCompare(string $a, string $b): bool
    {
        // Use hash_equals if available (PHP 5.6+)
        if (function_exists('hash_equals')) {
            return hash_equals($a, $b);
        }
        
        // Fallback implementation
        if (strlen($a) !== strlen($b)) {
            return false;
        }
        
        $result = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $result |= ord($a[$i]) ^ ord($b[$i]);
        }
        
        return $result === 0;
    }
}
