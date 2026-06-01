<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RouterInventoryTopologyService
{
    public function build(Router $router, array|Collection $accessPoints = [], array|Collection $services = [], array $liveData = []): array
    {
        $accessPoints = $this->normalizeList($accessPoints);
        $services = $this->normalizeList($services);

        $onlineAccessPoints = array_values(array_filter($accessPoints, static fn (array $ap) => strtolower((string) ($ap['status'] ?? 'unknown')) === 'online'));
        $offlineAccessPoints = array_values(array_filter($accessPoints, static fn (array $ap) => strtolower((string) ($ap['status'] ?? 'unknown')) === 'offline'));

        $routerNode = [
            'id' => 'router:' . (string) $router->id,
            'type' => 'router',
            'label' => (string) ($router->name ?? 'Router'),
            'sub_label' => trim(sprintf('%s %s', (string) ($router->vendor ?? 'MikroTik'), (string) ($router->model ?? ''))),
            'status' => strtolower((string) ($router->status ?? 'unknown')),
        ];

        $apNodes = array_map(static function (array $ap): array {
            return [
                'id' => 'ap:' . (string) ($ap['id'] ?? Str::uuid()->toString()),
                'type' => 'access_point',
                'label' => (string) ($ap['name'] ?? 'Access Point'),
                'sub_label' => trim(sprintf('%s %s', (string) ($ap['vendor'] ?? 'unknown'), (string) ($ap['model'] ?? ''))),
                'status' => strtolower((string) ($ap['status'] ?? 'unknown')),
                'active_users' => (int) ($ap['active_users'] ?? 0),
                'location' => $ap['location'] ?? null,
            ];
        }, $accessPoints);

        $edges = array_map(static function (array $ap) use ($router): array {
            return [
                'source' => 'router:' . (string) $router->id,
                'target' => 'ap:' . (string) ($ap['id'] ?? ''),
                'label' => 'uplink',
                'status' => strtolower((string) ($ap['status'] ?? 'unknown')),
            ];
        }, $accessPoints);

        $serviceNames = array_values(array_map(static function ($service): string {
            if (is_array($service)) {
                return (string) ($service['name'] ?? $service['service_name'] ?? 'service');
            }

            return (string) ($service->name ?? $service->service_name ?? 'service');
        }, $services));

        return [
            'router' => $routerNode,
            'summary' => [
                'routers' => 1,
                'access_points' => count($accessPoints),
                'access_points_online' => count($onlineAccessPoints),
                'access_points_offline' => count($offlineAccessPoints),
                'services' => count($services),
                'active_connections' => (int) ($liveData['active_connections'] ?? 0),
            ],
            'health' => [
                'status' => count($offlineAccessPoints) > 0 ? 'degraded' : 'healthy',
                'score' => max(0, 100 - (count($offlineAccessPoints) * 10) - (count($accessPoints) > 0 ? 0 : 5)),
                'signals' => [
                    'offline_access_points' => count($offlineAccessPoints),
                    'configured_services' => count($services),
                    'service_names' => array_slice($serviceNames, 0, 10),
                ],
            ],
            'nodes' => array_merge([$routerNode], $apNodes),
            'edges' => $edges,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    private function normalizeList(array|Collection $value): array
    {
        if ($value instanceof Collection) {
            return array_values(array_map(static fn ($item) => is_array($item) ? $item : (array) $item, $value->all()));
        }

        return array_values(array_map(static fn ($item) => is_array($item) ? $item : (array) $item, $value));
    }
}
