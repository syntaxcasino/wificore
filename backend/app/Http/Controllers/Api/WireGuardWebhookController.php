<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessWireGuardWebhookJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * WireGuard Webhook Controller
 *
 * Receives peer activity events from WireGuard controller/container
 * for event-based router status updates (instead of polling).
 */
class WireGuardWebhookController extends Controller
{
    /**
     * Verify API key from request
     */
    private function verifyApiKey(Request $request): bool
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }
        
        $token = substr($authHeader, 7);
        $expectedKey = config('services.wireguard.webhook_key', config('services.wireguard.api_key'));
        
        return $token === $expectedKey;
    }

    /**
     * Handle peer handshake event from WireGuard
     * Called when a peer completes a handshake
     */
    public function peerHandshake(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$this->verifyApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $validated = $request->validate([
            'public_key' => 'required|string',
            'endpoint' => 'nullable|string',
            'allowed_ips' => 'nullable|string',
            'latest_handshake' => 'required|integer',
            'transfer_rx' => 'nullable|integer',
            'transfer_tx' => 'nullable|integer',
            'interface' => 'nullable|string',
            'tenant_id' => 'nullable|string',
        ]);

        $publicKey = $validated['public_key'];
        $handshakeTimestamp = $validated['latest_handshake'];

        Log::debug('WireGuard peer handshake received', [
            'public_key' => substr($publicKey, 0, 20) . '...',
            'endpoint' => $validated['endpoint'] ?? null,
            'handshake_time' => $handshakeTimestamp,
        ]);

        // Dispatch job to process asynchronously (fast response to webhook)
        ProcessWireGuardWebhookJob::dispatch($validated);

        // Also immediately update Redis cache for instant UI feedback
        $this->updateRedisCache($publicKey, $handshakeTimestamp, 'connected');

        return response()->json([
            'success' => true,
            'message' => 'Peer handshake event accepted',
        ]);
    }

    /**
     * Handle peer removal/expiration event
     * Called when a peer is removed or handshake expires
     */
    public function peerExpired(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$this->verifyApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $validated = $request->validate([
            'public_key' => 'required|string',
            'reason' => 'nullable|string',
            'interface' => 'nullable|string',
        ]);

        $publicKey = $validated['public_key'];

        Log::debug('WireGuard peer expired/removed', [
            'public_key' => substr($publicKey, 0, 20) . '...',
            'reason' => $validated['reason'] ?? 'unknown',
        ]);

        ProcessWireGuardWebhookJob::dispatch(array_merge($validated, ['event_type' => 'expired']));

        $this->updateRedisCache($publicKey, null, 'disconnected');

        return response()->json([
            'success' => true,
            'message' => 'Peer expired event accepted',
        ]);
    }

    /**
     * Batch update from WireGuard dump
     * Called periodically with full peer list
     */
    public function batchUpdate(Request $request): \Illuminate\Http\JsonResponse
    {
        if (!$this->verifyApiKey($request)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }
        
        $validated = $request->validate([
            'peers' => 'required|array',
            'peers.*.public_key' => 'required|string',
            'peers.*.latest_handshake' => 'nullable|integer',
            'peers.*.endpoint' => 'nullable|string',
            'interface' => 'nullable|string',
        ]);

        $peers = $validated['peers'];

        Log::debug('WireGuard batch peer update received', [
            'peer_count' => count($peers),
        ]);

        // Process batch update
        foreach ($peers as $peer) {
            if (!empty($peer['latest_handshake'])) {
                $this->updateRedisCache(
                    $peer['public_key'],
                    $peer['latest_handshake'],
                    'connected'
                );
            }
        }

        // Dispatch batch processing job
        ProcessWireGuardWebhookJob::dispatch([
            'event_type' => 'batch',
            'peers' => $peers,
        ]);

        return response()->json([
            'success' => true,
            'processed' => count($peers),
        ]);
    }

    /**
     * Health check for webhook endpoint
     */
    public function health(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'wireguard-webhook',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Update Redis cache for instant UI feedback
     */
    private function updateRedisCache(string $publicKey, ?int $handshakeTimestamp, string $status): void
    {
        try {
            $cacheKey = "wireguard:peer:{$publicKey}";
            $data = [
                'public_key' => $publicKey,
                'status' => $status,
                'last_handshake' => $handshakeTimestamp,
                'updated_at' => now()->toIso8601String(),
            ];

            Redis::hset($cacheKey, 'data', json_encode($data));
            Redis::expire($cacheKey, 300); // 5 minute TTL

            // Publish event for WebSocket subscribers
            Redis::publish('wireguard:peer:update', json_encode($data));
        } catch (\Exception $e) {
            Log::warning('Failed to update Redis cache for peer', [
                'public_key' => substr($publicKey, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
