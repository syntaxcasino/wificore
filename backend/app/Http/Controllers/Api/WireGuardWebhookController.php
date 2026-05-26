<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WireGuardWebhookProjectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class WireGuardWebhookController extends Controller
{
    public function __construct(private readonly WireGuardWebhookProjectionService $projectionService)
    {
    }

    public function peerHandshake(Request $request): \Illuminate\Http\JsonResponse
    {
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

        Log::debug('WireGuard peer handshake received', [
            'public_key' => substr($validated['public_key'], 0, 20) . '...',
            'endpoint' => $validated['endpoint'] ?? null,
            'handshake_time' => $validated['latest_handshake'],
        ]);

        $this->projectionService->process(array_merge($validated, ['event_type' => 'handshake']));
        $this->updateRedisCache($validated['public_key'], $validated['latest_handshake'], 'connected');

        return response()->json([
            'success' => true,
            'message' => 'Peer handshake event accepted',
        ]);
    }

    public function peerExpired(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'public_key' => 'required|string',
            'reason' => 'nullable|string',
            'interface' => 'nullable|string',
        ]);

        Log::debug('WireGuard peer expired/removed', [
            'public_key' => substr($validated['public_key'], 0, 20) . '...',
            'reason' => $validated['reason'] ?? 'unknown',
        ]);

        $this->projectionService->process(array_merge($validated, ['event_type' => 'expired']));
        $this->updateRedisCache($validated['public_key'], null, 'disconnected');

        return response()->json([
            'success' => true,
            'message' => 'Peer expired event accepted',
        ]);
    }

    public function batchUpdate(Request $request): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate([
            'peers' => 'required|array',
            'peers.*.public_key' => 'required|string',
            'peers.*.latest_handshake' => 'nullable|integer',
            'peers.*.endpoint' => 'nullable|string',
            'interface' => 'nullable|string',
        ]);

        Log::debug('WireGuard batch peer update received', [
            'peer_count' => count($validated['peers']),
        ]);

        foreach ($validated['peers'] as $peer) {
            if (! empty($peer['latest_handshake'])) {
                $this->updateRedisCache($peer['public_key'], $peer['latest_handshake'], 'connected');
            }
        }

        $this->projectionService->process([
            'event_type' => 'batch',
            'peers' => $validated['peers'],
        ]);

        return response()->json([
            'success' => true,
            'processed' => count($validated['peers']),
        ]);
    }

    public function health(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'wireguard-webhook',
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    private function updateRedisCache(string $publicKey, ?int $handshakeTimestamp, string $status): void
    {
        try {
            $cacheKey = 'wireguard:peer:' . $publicKey;
            $data = [
                'public_key' => $publicKey,
                'status' => $status,
                'last_handshake' => $handshakeTimestamp,
                'updated_at' => now()->toIso8601String(),
            ];

            Redis::hset($cacheKey, 'data', json_encode($data));
            Redis::expire($cacheKey, 300);
            Redis::publish('wireguard:peer:update', json_encode($data));
        } catch (\Exception $e) {
            Log::warning('Failed to update Redis cache for peer', [
                'public_key' => substr($publicKey, 0, 20) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }
}
