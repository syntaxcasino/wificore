<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Log;

class VerifyMpesaSignature
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification in development/testing if configured
        if (config('mpesa.skip_signature_verification', false)) {
            Log::warning('M-Pesa signature verification skipped (development mode)');
            return $next($request);
        }

        // Get the signature from headers
        $signature = $request->header('X-Mpesa-Signature');
        
        if (!$signature) {
            Log::error('M-Pesa webhook rejected: Missing signature header', [
                'ip' => $request->ip(),
                'headers' => $request->headers->all(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Missing signature'
            ], 401);
        }

        // Get the raw request body
        $payload = $request->getContent();
        
        // Verify the signature
        if (!$this->verifySignature($payload, $signature)) {
            Log::error('M-Pesa webhook rejected: Invalid signature', [
                'ip' => $request->ip(),
                'signature' => $signature,
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Invalid signature'
            ], 401);
        }

        Log::info('M-Pesa webhook signature verified successfully');
        
        return $next($request);
    }

    /**
     * Verify the M-Pesa webhook signature
     *
     * @param string $payload
     * @param string $signature
     * @return bool
     */
    protected function verifySignature(string $payload, string $signature): bool
    {
        // Get the M-Pesa public key or shared secret
        $secret = config('mpesa.webhook_secret');
        
        if (!$secret) {
            Log::error('M-Pesa webhook secret not configured');
            return false;
        }

        // Method 1: HMAC-SHA256 verification (if M-Pesa uses HMAC)
        $expectedSignature = hash_hmac('sha256', $payload, $secret);
        
        if (hash_equals($expectedSignature, $signature)) {
            return true;
        }

        // Method 2: Base64 encoded HMAC (alternative format)
        $expectedSignatureBase64 = base64_encode(hash_hmac('sha256', $payload, $secret, true));
        
        if (hash_equals($expectedSignatureBase64, $signature)) {
            return true;
        }

        // Method 3: RSA signature verification (if M-Pesa uses RSA)
        // This would require the M-Pesa public key certificate
        $publicKey = config('mpesa.public_key');
        
        if ($publicKey) {
            try {
                $verified = openssl_verify(
                    $payload,
                    base64_decode($signature),
                    $publicKey,
                    OPENSSL_ALGO_SHA256
                );
                
                if ($verified === 1) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::error('RSA signature verification failed', [
                    'error' => $e->getMessage()
                ]);
            }
        }

        return false;
    }
}
